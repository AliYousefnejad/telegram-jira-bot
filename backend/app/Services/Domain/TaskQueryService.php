<?php

namespace App\Services\Domain;

use App\Contracts\JiraServiceInterface;
use App\Enums\TaskImpact;
use App\Enums\TaskSeverity;
use App\Resources\TaskResource;
use App\Services\Jira\JiraCredentials;
use App\Services\Jira\TaskOwnerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Channel-agnostic task reads for the Mini App: board statuses with counts
 * and the criticality-sorted task list the bot's /managetasks shows.
 */
class TaskQueryService
{
    private const VERSION_KEY = 'webapp_tasks_version';

    public function __construct(
        private JiraServiceInterface $jira,
        private TaskOwnerService $owners,
    ) {}

    /**
     * Board statuses in workflow order, each with its task count and owner.
     *
     * @return array{open: int, statuses: array<int, array{id: string, name: string, count: int, owner: ?string}>}
     */
    public function statusesWithCounts(string $projectKey): array
    {
        return $this->cached("webapp_statuses_{$projectKey}", [60, 3600], function () use ($projectKey): array {
            // One query counts every status; only a project bigger than the fetch limit falls back to per-status counts.
            $result = $this->jira->getIssues($this->projectJql($projectKey), (int) config('telegram.reports.fetch_limit'));
            $exact = $result['total'] <= count($result['issues']);
            $counts = [];
            $open = 0;

            foreach ($result['issues'] as $issue) {
                $name = (string) ($issue['fields']['status']['name'] ?? '');
                $counts[$name] = ($counts[$name] ?? 0) + 1;
                $open += ($issue['fields']['status']['statusCategory']['key'] ?? '') === 'done' ? 0 : 1;
            }

            $statuses = array_map(fn (array $status): array => [
                'id' => $status['id'],
                'name' => $status['name'],
                'count' => $exact
                    ? ($counts[$status['name']] ?? 0)
                    : $this->count($this->projectJql($projectKey).' AND status = "'.$this->escape($status['name']).'"'),
                'owner' => $this->owners->resolveOwnerForStatus($status['name'])?->value,
            ], $this->boardStatuses($projectKey));

            return [
                'open' => $exact ? $open : $this->count($this->projectJql($projectKey).' AND statusCategory != Done'),
                'statuses' => $statuses,
            ];
        });
    }

    /**
     * Tasks in one status (or every open task when $status is null), most critical first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tasks(string $projectKey, ?string $status): array
    {
        $jql = $this->projectJql($projectKey).($status === null
            ? ' AND statusCategory != Done'
            : ' AND status = "'.$this->escape($status).'"');

        return $this->cached('webapp_tasks_'.md5($jql), [30, 600], function () use ($jql): array {
            $issues = $this->jira->getIssues($jql.' ORDER BY updated DESC', (int) config('telegram.reports.fetch_limit'), 0, withCustomFields: true)['issues'];

            usort($issues, fn (array $a, array $b): int => $this->compareByCriticality($a, $b));

            return $issues;
        });
    }

    /** After an edit: lists and counts are read fresh from Jira next time (every identity). */
    public function forgetTasks(): void
    {
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 0) + 1);
    }

    /**
     * Cached per Jira identity (a user's own token may see other tasks than the bot). A stale value
     * is returned at once and refreshed after the response, as the same identity.
     *
     * @template T
     *
     * @param  array{0: int, 1: int}  $ttl  seconds fresh, seconds stale-but-usable
     * @param  callable(): T  $load
     * @return T
     */
    private function cached(string $key, array $ttl, callable $load): mixed
    {
        $credentials = app(JiraCredentials::class);
        $actingUser = $credentials->actingUser();

        $key .= '_v'.(int) Cache::get(self::VERSION_KEY, 0);

        return Cache::flexible($key.'@'.$credentials->cacheScope(), $ttl, fn () => $credentials->runAs($actingUser, $load));
    }

    /** @return array<int, array{id: string, name: string}> */
    private function boardStatuses(string $projectKey): array
    {
        try {
            $boardId = $this->jira->getProjectBoard($projectKey);

            return $boardId === null ? [] : $this->jira->getBoardConfiguration($boardId)['status_rows'];
        } catch (Throwable $e) {
            Log::warning('Board status lookup failed: '.$e->getMessage());

            return [];
        }
    }

    private function count(string $jql): int
    {
        return (int) $this->jira->getIssues($jql, 0)['total'];
    }

    private function projectJql(string $projectKey): string
    {
        return 'project = "'.$this->escape($projectKey).'"';
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * Same order as the bot: severity rank, then impact rank, newest first.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compareByCriticality(array $a, array $b): int
    {
        $rank = fn (TaskSeverity|TaskImpact|null $enum): int => $enum === null ? 99 : (int) array_search($enum, $enum::cases(), true);
        $severity = fn (array $issue): int => $rank(TaskSeverity::fromJiraOption(TaskResource::customFieldValue($issue['fields'] ?? [], 'severity')));
        $impact = fn (array $issue): int => $rank(TaskImpact::fromJiraOption(TaskResource::customFieldValue($issue['fields'] ?? [], 'impact')));

        foreach ([$severity, $impact] as $by) {
            if (($diff = $by($a) <=> $by($b)) !== 0) {
                return $diff;
            }
        }

        return strcasecmp((string) ($b['fields']['updated'] ?? ''), (string) ($a['fields']['updated'] ?? ''));
    }
}
