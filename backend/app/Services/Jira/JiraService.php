<?php

namespace App\Services\Jira;

use App\Contracts\JiraServiceInterface;
use App\Exceptions\JiraTimeoutException;
use App\Exceptions\JiraUserTokenRejected;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class JiraService implements JiraServiceInterface
{
    private const BOARD_CONFIG_CACHE_PREFIX = 'jira_board_cfg_v2_';

    private const BOARD_LOCATION_CACHE_PREFIX = 'jira_board_loc_v2_';

    private const PROJECT_BOARD_CACHE_PREFIX = 'jira_project_board_';

    private const FIELD_ID_CACHE_PREFIX = 'jira_field_id_v2_';

    private const FIELD_OPTIONS_CACHE_PREFIX = 'jira_field_opts_v1_';

    private const PROJECT_STATUSES_CACHE_PREFIX = 'jira_project_statuses_';

    private const API_PREFIX = '/rest/api/2';

    private const AGILE_API_PREFIX = '/rest/agile/1.0';

    private string $baseUrl;

    private string $pat;

    private string $email;

    private string $apiToken;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.jira.base_url'), '/');
        $this->pat = (string) config('services.jira.pat');
        $this->email = (string) config('services.jira.email');
        $this->apiToken = (string) config('services.jira.api_token');

        if (! $this->baseUrl) {
            throw new RuntimeException('Jira is not configured. Set JIRA_BASE_URL in .env');
        }

        if (! $this->pat && (! $this->email || ! $this->apiToken)) {
            throw new RuntimeException(
                'Jira authentication is not configured. Set JIRA_PAT (recommended) or JIRA_EMAIL and JIRA_API_TOKEN in .env'
            );
        }
    }

    private function client(): PendingRequest
    {
        $client = Http::acceptJson()
            ->timeout(config('services.jira.request_timeout_seconds'))
            ->connectTimeout(config('services.jira.connect_timeout_seconds'));

        if ($this->credentials()->wasRejected()) {
            throw new JiraUserTokenRejected;
        }

        // A user who signed in with their own Personal Access Token acts as themselves.
        if (($userToken = $this->credentials()->userToken()) !== null) {
            return $client->withToken($userToken);
        }

        if ($this->pat !== '') {
            return $client->withToken($this->pat);
        }

        return $client->withBasicAuth($this->email, $this->apiToken);
    }

    private function get(string $url, array $query = []): Response
    {
        return $this->sendWithRetry(fn (): Response => $this->client()->get($url, $query), $url);
    }

    private function post(string $url, array $payload = []): Response
    {
        return $this->send(fn (): Response => $this->client()->post($url, $payload), $url);
    }

    private function put(string $url, array $payload = []): Response
    {
        return $this->send(fn (): Response => $this->client()->put($url, $payload), $url);
    }

    private function sendWithRetry(callable $request, string $url): Response
    {
        try {
            return $this->send($request, $url);
        } catch (JiraTimeoutException $first) {
            Log::warning('Jira GET failed once, retrying', [
                'url' => $url,
                'exception' => $first->getMessage(),
            ]);

            usleep(config('services.jira.retry_delay_us'));

            return $this->send($request, $url);
        }
    }

    private function send(callable $request, string $url): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            Log::warning('Jira request timed out', [
                'url' => $url,
                'exception' => $e->getMessage(),
            ]);

            throw JiraTimeoutException::fromConnection($url, $e->getMessage());
        }

        if ($response->status() === 401 && $this->credentials()->isUserToken()) {
            $this->credentials()->rejectCurrentToken();

            throw new JiraUserTokenRejected;
        }

        return $response;
    }

    private function credentials(): JiraCredentials
    {
        return app(JiraCredentials::class);
    }

    /** Permission-dependent caches are kept apart per identity (bot token vs. each user's own token). */
    private function scoped(string $cacheKey): string
    {
        return $cacheKey.'@'.$this->credentials()->cacheScope();
    }

    /**
     * Who owns this Personal Access Token: GET /myself with it. Null when Jira refuses the token.
     *
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException when Jira cannot be reached or answers with an error
     */
    public function verifyToken(string $token): ?array
    {
        $url = $this->baseUrl.self::API_PREFIX.'/myself';
        $response = $this->send(fn (): Response => Http::acceptJson()
            ->timeout(config('services.jira.request_timeout_seconds'))
            ->connectTimeout(config('services.jira.connect_timeout_seconds'))
            ->withToken($token)
            ->get($url), $url);

        if (in_array($response->status(), [401, 403], true)) {
            return null;
        }

        $user = $this->handleResponse($response)->json();

        return is_array($user) && isset($user['name']) ? $user : null;
    }

    public static function userId(array $user): string
    {
        return (string) ($user['name'] ?? $user['key'] ?? $user['accountId'] ?? '');
    }

    /** @return array<string, mixed>|null */
    public function getUser(string $userId): ?array
    {
        if (trim($userId) === '') {
            return null;
        }

        $response = $this->get($this->baseUrl.self::API_PREFIX.'/user', ['username' => $userId]);

        if ($response->status() === 404) {
            return null;
        }

        return $this->handleResponse($response)->json();
    }

    /** @return array{body: string, type: string}|null */
    public function fetchAvatar(string $url): ?array
    {
        // Only this Jira host: the URL comes from Jira, but never let it point anywhere else.
        if (parse_url($url, PHP_URL_HOST) !== parse_url($this->baseUrl, PHP_URL_HOST)) {
            return null;
        }

        try {
            $response = $this->client()->accept('image/*')->get($url);
        } catch (\Throwable $e) {
            Log::warning('Jira avatar download failed: '.$e->getMessage());

            return null;
        }

        $type = (string) $response->header('Content-Type');

        return $response->successful() && str_starts_with($type, 'image/')
            ? ['body' => $response->body(), 'type' => $type]
            : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function getProjects(): array
    {
        $response = $this->handleResponse(
            $this->get($this->baseUrl.self::API_PREFIX.'/project')
        );

        return $response->json() ?? [];
    }

    /** @return array{issues: array<int, array<string, mixed>>, total: int, hasMore: bool} */
    public function getIssues(string $jql, int $maxResults = 20, int $startAt = 0, bool $withCustomFields = false): array
    {
        $fields = ['summary', 'status', 'priority', 'issuetype', 'assignee', 'reporter', 'created', 'updated'];

        if ($withCustomFields) {
            foreach (config('services.jira.custom_fields') as $fieldName) {
                if (($fieldId = $this->customFieldId((string) $fieldName)) !== null) {
                    $fields[] = $fieldId;
                }
            }
        }

        $payload = [
            'jql' => $jql,
            'maxResults' => $maxResults,
            'startAt' => $startAt,
            'fields' => $fields,
        ];

        $response = $this->handleResponse(
            $this->post($this->baseUrl.self::API_PREFIX.'/search', $payload)
        );

        $issues = $response->json('issues') ?? [];
        $total = (int) $response->json('total', 0);

        return [
            'issues' => $issues,
            'total' => $total,
            'hasMore' => $startAt + count($issues) < $total,
        ];
    }

    /** @return array<string, mixed>|null */
    public function getIssue(string $key): ?array
    {
        $fieldList = ['summary', 'description', 'status', 'priority', 'issuetype', 'assignee', 'reporter', 'created', 'updated', 'project', 'labels'];

        foreach (config('services.jira.custom_fields') as $fieldName) {
            if (($fieldId = $this->customFieldId((string) $fieldName)) !== null) {
                $fieldList[] = $fieldId;
            }
        }

        $response = $this->get($this->baseUrl.self::API_PREFIX.'/issue/'.rawurlencode($key), [
            'fields' => implode(',', $fieldList),
        ]);

        if ($response->status() === 404) {
            return null;
        }

        return $this->handleResponse($response)->json();
    }

    /** @return array<int, array<string, mixed>> */
    public function getUsers(?string $query = null): array
    {
        if ($query !== null && trim($query) !== '') {
            return $this->searchUsers($query);
        }

        return $this->usersFromGroups();
    }

    private function searchUsers(string $query): array
    {
        $response = $this->handleResponse(
            $this->get($this->baseUrl.self::API_PREFIX.'/user/search', [
                'maxResults' => config('services.jira.api_page_size'),
                'username' => $query,
            ])
        );

        return $response->json() ?? [];
    }

    private function usersFromGroups(): array
    {
        $users = [];
        $seen = [];

        $groups = $this->handleResponse(
            $this->get($this->baseUrl.self::API_PREFIX.'/groups/picker', [
                'query' => '',
                'maxResults' => config('services.jira.api_page_size'),
            ])
        )->json('groups') ?? [];

        foreach ($groups as $group) {
            $groupName = $group['name'] ?? '';

            if ($groupName === '') {
                continue;
            }

            $startAt = 0;

            do {
                $members = $this->handleResponse(
                    $this->get($this->baseUrl.self::API_PREFIX.'/group/member', [
                        'groupname' => $groupName,
                        'includeInactiveUsers' => false,
                        'startAt' => $startAt,
                        'maxResults' => config('services.jira.api_page_size'),
                    ])
                );

                $values = $members->json('values') ?? [];
                $pageSize = count($values);

                foreach ($values as $user) {
                    $userId = self::userId($user);

                    if ($userId !== '' && ! isset($seen[$userId])) {
                        $seen[$userId] = true;
                        $users[] = $user;
                    }
                }

                $startAt += $pageSize;
            } while (! $members->json('isLast', true) && $pageSize > 0 && count($users) < config('services.jira.max_listed_users'));
        }

        return array_slice($users, 0, config('services.jira.max_listed_users'));
    }

    /** @return array<int, array<string, mixed>> */
    public function getBoards(): array
    {
        $response = $this->handleResponse(
            $this->get($this->baseUrl.self::AGILE_API_PREFIX.'/board', ['maxResults' => config('services.jira.api_page_size')])
        );

        return $response->json('values') ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function getSprints(int $boardId): array
    {
        $response = $this->handleResponse(
            $this->get($this->baseUrl.self::AGILE_API_PREFIX.'/board/'.$boardId.'/sprint', [
                'maxResults' => config('services.jira.api_page_size'),
                'state' => 'future,active',
            ])
        );

        return $response->json('values') ?? [];
    }

    /** @return array{statuses: array<int, string>, status_rows: array<int, array{id: string, name: string}>, project_key: string} */
    public function getBoardConfiguration(int $boardId): array
    {
        return Cache::remember(
            $this->scoped(self::BOARD_CONFIG_CACHE_PREFIX.$boardId),
            now()->addMinutes(config('services.jira.cache_ttl_minutes')),
            function () use ($boardId): array {
                $response = $this->handleResponse(
                    $this->get($this->baseUrl.self::AGILE_API_PREFIX.'/board/'.$boardId.'/configuration')
                );

                $rows = $this->boardStatusRows($response->json('columnConfig.columns') ?? []);

                return [
                    'statuses' => array_column($rows, 'name'),
                    'status_rows' => $rows,
                    'project_key' => $this->getBoardProjectKey($boardId, (string) ($response->json('filter.id') ?? '')),
                ];
            }
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<int, array{id: string, name: string}>
     */
    private function boardStatusRows(array $columns): array
    {
        $inlineNames = [];
        $toResolve = [];

        foreach ($columns as $column) {
            foreach ($column['statuses'] ?? [] as $status) {
                $id = (string) ($status['id'] ?? '');

                if ($id === '') {
                    continue;
                }

                $inline = trim((string) ($status['name'] ?? ''));

                if ($inline !== '') {
                    $inlineNames[$id] = $inline;
                } else {
                    $toResolve[$id] = true;
                }
            }
        }

        $namesById = $inlineNames;

        foreach (array_keys($toResolve) as $id) {
            try {
                $resolved = trim((string) ($this->get($this->baseUrl.self::API_PREFIX.'/status/'.rawurlencode($id))->json('name') ?? ''));

                if ($resolved !== '') {
                    $namesById[$id] = $resolved;
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to resolve Jira status name', [
                    'status_id' => $id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $rows = [];
        $seen = [];

        foreach ($columns as $column) {
            foreach ($column['statuses'] ?? [] as $status) {
                $id = (string) ($status['id'] ?? '');
                $name = $namesById[$id] ?? '';
                $key = mb_strtolower($name);

                if ($name === '' || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $rows[] = ['id' => $id, 'name' => $name];
            }
        }

        return $rows;
    }

    private function getBoardProjectKey(int $boardId, string $filterId): string
    {
        return Cache::remember(
            self::BOARD_LOCATION_CACHE_PREFIX.$boardId,
            now()->addMinutes(config('services.jira.cache_ttl_minutes')),
            function () use ($boardId, $filterId): string {
                $projectKey = strtoupper((string) ($this->handleResponse(
                    $this->get($this->baseUrl.self::AGILE_API_PREFIX.'/board/'.$boardId)
                )->json('location.projectKey') ?? ''));

                if ($projectKey !== '' || $filterId === '') {
                    return $projectKey;
                }

                return $this->projectFromFilterJql($filterId);
            }
        );
    }

    private function projectFromFilterJql(string $filterId): string
    {
        try {
            $jql = (string) ($this->handleResponse(
                $this->get($this->baseUrl.self::API_PREFIX.'/filter/'.rawurlencode($filterId))
            )->json('jql') ?? '');

            if (preg_match('/\bproject\s*=\s*"?([A-Z0-9]+)"?/i', $jql, $matches) === 1) {
                return strtoupper($matches[1]);
            }

            Log::warning('Board filter JQL does not contain a project clause', ['filter_id' => $filterId]);
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve board project from filter', [
                'filter_id' => $filterId,
                'exception' => $e->getMessage(),
            ]);
        }

        return '';
    }

    public function getProjectBoard(string $projectKey): ?int
    {
        $normalized = strtoupper($projectKey);

        if (! self::isValidKeySegment($normalized)) {
            return null;
        }

        $boardId = Cache::remember(
            $this->scoped(self::PROJECT_BOARD_CACHE_PREFIX.$normalized),
            now()->addMinutes(config('services.jira.cache_ttl_minutes')),
            function () use ($normalized): ?int {
                $boards = $this->handleResponse(
                    $this->get($this->baseUrl.self::AGILE_API_PREFIX.'/board', [
                        'projectKeyOrId' => $normalized,
                        'maxResults' => 1,
                    ])
                )->json('values') ?? [];

                return isset($boards[0]['id']) ? (int) $boards[0]['id'] : null;
            }
        );

        return $boardId;
    }

    /** @return array<int, array<string, mixed>> */
    public function getIssueTransitions(string $issueKey): array
    {
        $response = $this->handleResponse(
            $this->get($this->baseUrl.self::API_PREFIX.'/issue/'.rawurlencode($issueKey).'/transitions')
        );

        return $response->json('transitions') ?? [];
    }

    /** @return array<string, mixed> */
    public function assignIssue(string $issueKey, string $userName): array
    {
        $this->handleResponse(
            $this->put($this->baseUrl.self::API_PREFIX.'/issue/'.rawurlencode($issueKey).'/assignee', [
                'name' => $userName,
            ])
        );

        return ['success' => true, 'issue' => $issueKey, 'userName' => $userName];
    }

    /** @return array<string, mixed> */
    public function unassignIssue(string $issueKey): array
    {
        $this->handleResponse(
            $this->put($this->baseUrl.self::API_PREFIX.'/issue/'.rawurlencode($issueKey).'/assignee', [
                'name' => null,
            ])
        );

        return ['success' => true, 'issue' => $issueKey];
    }

    /**
     * @param  array<string, mixed>  $extraFields
     * @return array<string, mixed>
     */
    public function createIssue(
        string $projectKey,
        string $summary,
        string $issueType = 'Task',
        ?string $description = null,
        ?string $assigneeUserName = null,
        ?string $reporterUserName = null,
        array $extraFields = [],
    ): array {
        $fields = array_merge([
            'project' => ['key' => $projectKey],
            'summary' => $summary,
            'issuetype' => ['name' => $issueType],
        ], $extraFields);

        if ($description !== null && $description !== '') {
            $fields['description'] = $description;
        }

        if ($assigneeUserName !== null) {
            $fields['assignee'] = ['name' => $assigneeUserName];
        }

        if ($reporterUserName !== null) {
            $fields['reporter'] = ['name' => $reporterUserName];
        }

        $response = $this->handleResponse(
            $this->post($this->baseUrl.self::API_PREFIX.'/issue', [
                'fields' => $fields,
            ])
        );

        return $response->json() ?? [];
    }

    /** @return array<string, mixed> */
    public function transitionIssue(string $issueKey, string $transitionId): array
    {
        $this->handleResponse(
            $this->post($this->baseUrl.self::API_PREFIX.'/issue/'.rawurlencode($issueKey).'/transitions', [
                'transition' => ['id' => $transitionId],
            ])
        );

        return ['success' => true, 'issue' => $issueKey, 'transition' => $transitionId];
    }

    /** @return array<string, mixed> */
    public function moveIssueToSprint(int $sprintId, string $issueKey): array
    {
        $this->handleResponse(
            $this->post($this->baseUrl.self::AGILE_API_PREFIX.'/sprint/'.$sprintId.'/issue', [
                'issues' => [$issueKey],
            ])
        );

        return ['success' => true, 'issue' => $issueKey, 'sprintId' => $sprintId];
    }

    public function updateDescription(string $issueKey, string $description): void
    {
        $this->updateIssue($issueKey, ['description' => $description]);
    }

    /** @return array<string, mixed> */
    public function addComment(string $issueKey, string $body): array
    {
        $response = $this->handleResponse(
            $this->post($this->baseUrl.self::API_PREFIX.'/issue/'.rawurlencode($issueKey).'/comment', [
                'body' => $body,
            ])
        );

        return $response->json() ?? [];
    }

    public function updateIssueField(string $issueKey, string $fieldId, string|int|array $fieldPayload): void
    {
        $this->updateIssue($issueKey, [$fieldId => $fieldPayload]);
    }

    private function updateIssue(string $issueKey, array $fields): void
    {
        $this->handleResponse(
            $this->put($this->baseUrl.self::API_PREFIX.'/issue/'.rawurlencode($issueKey), [
                'fields' => $fields,
            ])
        );
    }

    /** @return array<int, array{id: string, name: string, category: string}> */
    public function getProjectStatuses(string $projectKey): array
    {
        $normalized = strtoupper($projectKey);

        if (! self::isValidKeySegment($normalized)) {
            return [];
        }

        return Cache::remember(
            self::PROJECT_STATUSES_CACHE_PREFIX.$normalized,
            now()->addMinutes(config('services.jira.short_cache_ttl_minutes')),
            function () use ($normalized): array {
                try {
                    $statuses = $this->handleResponse(
                        $this->get($this->baseUrl.self::API_PREFIX.'/status/project/'.rawurlencode($normalized))
                    )->json() ?? [];
                } catch (RuntimeException $e) {
                    if (! str_contains($e->getMessage(), 'HTTP 404')) {
                        throw $e;
                    }

                    Log::info('Project-scoped statuses unavailable, falling back to instance statuses', [
                        'project' => $normalized,
                    ]);

                    $statuses = $this->handleResponse(
                        $this->get($this->baseUrl.self::API_PREFIX.'/status')
                    )->json() ?? [];
                }

                $mapped = [];

                foreach ($statuses as $status) {
                    $id = (string) ($status['id'] ?? '');
                    $name = trim((string) ($status['name'] ?? ''));

                    if ($id === '' || $name === '') {
                        continue;
                    }

                    $mapped[] = [
                        'id' => $id,
                        'name' => $name,
                        'category' => (string) ($status['statusCategory']['key'] ?? ''),
                    ];
                }

                return $mapped;
            }
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function getAssignableUsers(string $issueKey): array
    {
        return $this->handleResponse(
            $this->get($this->baseUrl.self::API_PREFIX.'/user/assignable/search', [
                'issueKey' => $issueKey,
                'maxResults' => config('services.jira.api_page_size'),
            ])
        )->json() ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function getAssignableUsersForProject(string $projectKey): array
    {
        return $this->handleResponse(
            $this->get($this->baseUrl.self::API_PREFIX.'/user/assignable/search', [
                'project' => $projectKey,
                'maxResults' => config('services.jira.api_page_size'),
            ])
        )->json() ?? [];
    }

    private function findCustomFieldIdByName(string $name): ?string
    {
        return Cache::remember(
            self::FIELD_ID_CACHE_PREFIX.md5($name),
            now()->addMinutes(config('services.jira.cache_ttl_minutes')),
            function () use ($name): ?string {
                $fields = $this->handleResponse(
                    $this->get($this->baseUrl.self::API_PREFIX.'/field')
                )->json() ?? [];

                foreach ($fields as $field) {
                    if (strcasecmp((string) ($field['name'] ?? ''), $name) === 0 && ! empty($field['custom'])) {
                        return (string) $field['id'];
                    }
                }

                return null;
            }
        );
    }

    public function customFieldId(string $name): ?string
    {
        try {
            return $this->findCustomFieldIdByName($name);
        } catch (\Throwable $e) {
            Log::warning("Custom field {$name} lookup failed: ".$e->getMessage());

            return null;
        }

    }

    /**
     * Allowed options of a select-type custom field on an issue, read from
     * its edit metadata and cached for an hour.
     *
     * @return array<int, array{id: string, value: string}>
     */
    public function fieldAllowedOptions(string $issueKey, string $fieldId): array
    {
        return Cache::remember(
            $this->scoped(self::FIELD_OPTIONS_CACHE_PREFIX.md5($issueKey.'|'.$fieldId)),
            now()->addMinutes(config('services.jira.cache_ttl_minutes')),
            function () use ($issueKey, $fieldId): array {
                $meta = $this->handleResponse(
                    $this->get($this->baseUrl.self::API_PREFIX.'/issue/'.rawurlencode($issueKey).'/editmeta')
                )->json('fields.'.$fieldId) ?? [];

                return array_values(array_map(
                    fn (array $option): array => [
                        'id' => (string) ($option['id'] ?? ''),
                        'value' => (string) ($option['value'] ?? ''),
                    ],
                    $meta['allowedValues'] ?? [],
                ));
            }
        );
    }

    /**
     * Allowed options of a select-type custom field for issue creation in a project,
     * read from createmeta (or fallback to recent issue editmeta) and cached.
     *
     * @return array<int, array{id: string, value: string}>
     */
    public function getCreateFieldAllowedOptions(string $projectKey, string $fieldId, ?string $issueType = null): array
    {
        $projectKey = $projectKey !== '' ? strtoupper($projectKey) : strtoupper((string) (config('services.jira.default_project') ?: 'TS'));
        $cacheKey = $this->scoped('jira_create_field_opts_dc_v2_'.md5($projectKey.'|'.$fieldId.'|'.($issueType ?? 'all')));

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(config('services.jira.cache_ttl_minutes')),
            function () use ($projectKey, $fieldId, $issueType): array {
                // 1. Jira Data Center REST API: /issue/createmeta/{projectIdOrKey}/issuetypes
                try {
                    $issueTypes = $this->handleResponse(
                        $this->get($this->baseUrl.self::API_PREFIX.'/issue/createmeta/'.rawurlencode($projectKey).'/issuetypes')
                    )->json('values') ?? [];

                    if ($issueType !== null && $issueType !== '') {
                        usort($issueTypes, function (array $a, array $b) use ($issueType): int {
                            $matchA = strcasecmp((string) ($a['name'] ?? ''), $issueType) === 0 || ((string) ($a['id'] ?? '')) === $issueType;
                            $matchB = strcasecmp((string) ($b['name'] ?? ''), $issueType) === 0 || ((string) ($b['id'] ?? '')) === $issueType;

                            return $matchB <=> $matchA;
                        });
                    }

                    foreach ($issueTypes as $it) {
                        $itId = (string) ($it['id'] ?? '');
                        if ($itId === '') {
                            continue;
                        }

                        if ($issueType !== null && $issueType !== '') {
                            $itName = (string) ($it['name'] ?? '');
                            if (strcasecmp($itName, $issueType) !== 0 && $itId !== $issueType) {
                                continue;
                            }
                        }

                        $fieldsResp = $this->handleResponse(
                            $this->get($this->baseUrl.self::API_PREFIX.'/issue/createmeta/'.rawurlencode($projectKey).'/issuetypes/'.rawurlencode($itId), [
                                'maxResults' => 100,
                            ])
                        );

                        $fields = $fieldsResp->json('values') ?? [];
                        foreach ($fields as $f) {
                            $fid = (string) ($f['fieldId'] ?? $f['id'] ?? '');
                            $fname = (string) ($f['name'] ?? '');
                            if (($fid === $fieldId || strcasecmp($fname, $fieldId) === 0) && ! empty($f['allowedValues'])) {
                                return array_values(array_map(
                                    fn (array $option): array => [
                                        'id' => (string) ($option['id'] ?? ''),
                                        'value' => (string) ($option['value'] ?? ''),
                                    ],
                                    $f['allowedValues'],
                                ));
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Data Center createmeta lookup failed for field {$fieldId} in project {$projectKey}: ".$e->getMessage());
                }

                // 2. Legacy createmeta endpoint (for older Jira Server instances < 8.4)
                try {
                    $query = [
                        'projectKeys' => $projectKey,
                        'expand' => 'projects.issuetypes.fields',
                    ];

                    if ($issueType !== null && $issueType !== '') {
                        $query['issuetypeNames'] = $issueType;
                    }

                    $response = $this->handleResponse(
                        $this->get($this->baseUrl.self::API_PREFIX.'/issue/createmeta', $query)
                    );

                    $projects = $response->json('projects') ?? [];
                    foreach ($projects as $project) {
                        foreach ($project['issuetypes'] ?? [] as $it) {
                            $fieldMeta = $it['fields'][$fieldId] ?? null;
                            if (isset($fieldMeta['allowedValues']) && is_array($fieldMeta['allowedValues']) && ! empty($fieldMeta['allowedValues'])) {
                                return array_values(array_map(
                                    fn (array $option): array => [
                                        'id' => (string) ($option['id'] ?? ''),
                                        'value' => (string) ($option['value'] ?? ''),
                                    ],
                                    $fieldMeta['allowedValues'],
                                ));
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Legacy createmeta lookup failed for field {$fieldId} in project {$projectKey}: ".$e->getMessage());
                }

                // 3. Fallback to recent issue editmeta
                try {
                    $recent = $this->getIssues("project = \"{$projectKey}\" ORDER BY created DESC", 1, 0);
                    $firstIssue = $recent['issues'][0] ?? null;
                    if ($firstIssue !== null && isset($firstIssue['key'])) {
                        $options = $this->fieldAllowedOptions((string) $firstIssue['key'], $fieldId);
                        if (! empty($options)) {
                            return $options;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Fallback editmeta lookup failed for field {$fieldId} in project {$projectKey}: ".$e->getMessage());
                }

                return [];
            }
        );
    }

    /**
     * Id of the field option whose label contains the given code
     * (e.g. "I1" matches "🔴 I1 - Extensive"), or null when absent.
     * Codes are unique per field, so a containment check is exact — labels
     * are decorated with emoji prefixes, which prefix-matching would miss.
     */
    public function optionIdForCode(string $issueKey, string $fieldId, string $code): ?string
    {
        foreach ($this->fieldAllowedOptions($issueKey, $fieldId) as $option) {
            if (str_contains($option['value'], $code)) {
                return $option['id'];
            }
        }

        return null;
    }

    /**
     * Fetch the issue history: status, assignee and owner changes from the
     * changelog plus every comment, as one chronological event list.
     *
     * @return array{
     *     createdAt: string,
     *     createdBy: ?string,
     *     currentStatus: string,
     *     currentAssignee: ?string,
     *     events: array<int, array{
     *         type: string,
     *         timestamp: string,
     *         author: ?string,
     *         from: ?string,
     *         to: ?string,
     *         body?: string
     *     }>
     * }
     */
    public function getIssueChangelog(string $issueKey): array
    {
        $encodedKey = rawurlencode($issueKey);

        $response = $this->handleResponse(
            $this->get($this->baseUrl.self::API_PREFIX."/issue/{$encodedKey}", [
                'expand' => 'changelog',
                'fields' => 'created,creator,reporter,status,assignee,comment',
            ])
        );

        $data = $response->json() ?? [];
        $fields = $data['fields'] ?? [];

        $createdAt = (string) ($fields['created'] ?? '');
        $createdBy = is_array($fields['reporter'] ?? null)
            ? (string) ($fields['reporter']['displayName'] ?? $fields['reporter']['name'] ?? null)
            : (is_array($fields['creator'] ?? null)
                ? (string) ($fields['creator']['displayName'] ?? $fields['creator']['name'] ?? null)
                : null);
        $currentStatus = is_array($fields['status'] ?? null)
            ? (string) ($fields['status']['name'] ?? '—')
            : '—';
        $currentAssignee = is_array($fields['assignee'] ?? null)
            ? (string) ($fields['assignee']['displayName'] ?? null)
            : null;

        $changelog = $data['changelog'] ?? [];
        $total = (int) ($changelog['total'] ?? 0);
        $histories = $changelog['histories'] ?? [];

        if ($total > count($histories)) {
            $startAt = count($histories);
            while ($startAt < $total) {
                try {
                    $pageResponse = $this->handleResponse(
                        $this->get(
                            $this->baseUrl.self::API_PREFIX."/issue/{$encodedKey}/changelog",
                            ['startAt' => $startAt, 'maxResults' => 100],
                        )
                    );
                    $page = $pageResponse->json() ?? [];
                    $values = $page['values'] ?? [];
                    if (empty($values)) {
                        break;
                    }
                    $histories = array_merge($histories, $values);
                    $startAt += count($values);
                    if (! empty($page['isLast'])) {
                        break;
                    }
                } catch (\Throwable) {
                    break;
                }
            }
        }

        $ownerFieldName = (string) config('services.jira.custom_fields.owner', 'Owner');
        $ownerFieldId = null;
        $ownerFieldIdResolved = false;

        $events = [];
        foreach ($histories as $history) {
            $timestamp = (string) ($history['created'] ?? '');
            $author = is_array($history['author'] ?? null)
                ? (string) ($history['author']['displayName'] ?? $history['author']['name'] ?? null)
                : null;

            foreach ($history['items'] ?? [] as $item) {
                $field = (string) ($item['field'] ?? '');
                $type = in_array($field, ['status', 'assignee'], true) ? $field : null;

                if ($type === null && ($item['fieldtype'] ?? '') === 'custom') {
                    if (strcasecmp($field, $ownerFieldName) === 0) {
                        $type = 'owner';
                    } elseif (isset($item['fieldId'])) {
                        if (! $ownerFieldIdResolved) {
                            $ownerFieldId = $this->customFieldId($ownerFieldName);
                            $ownerFieldIdResolved = true;
                        }

                        $type = $ownerFieldId !== null && $item['fieldId'] === $ownerFieldId ? 'owner' : null;
                    }
                }

                if ($type === null) {
                    continue;
                }

                $events[] = [
                    'type' => $type,
                    'timestamp' => $timestamp,
                    'author' => $author,
                    'from' => $item['fromString'] ?? null,
                    'to' => $item['toString'] ?? null,
                ];
            }
        }

        foreach ($this->issueComments($encodedKey, is_array($fields['comment'] ?? null) ? $fields['comment'] : []) as $comment) {
            $events[] = [
                'type' => 'comment',
                'timestamp' => (string) ($comment['created'] ?? ''),
                'author' => is_array($comment['author'] ?? null)
                    ? (string) ($comment['author']['displayName'] ?? $comment['author']['name'] ?? null)
                    : null,
                'from' => null,
                'to' => null,
                'body' => (string) ($comment['body'] ?? ''),
            ];
        }

        usort($events, fn (array $a, array $b): int => strtotime((string) ($a['timestamp'] ?? '')) <=> strtotime((string) ($b['timestamp'] ?? '')));

        return [
            'createdAt' => $createdAt,
            'createdBy' => $createdBy,
            'currentStatus' => $currentStatus,
            'currentAssignee' => $currentAssignee,
            'events' => $events,
        ];
    }

    /**
     * Comments embedded in the issue response, completed from the paged
     * comment endpoint when Jira truncated them.
     *
     * @param  array<string, mixed>  $embedded  the issue's `comment` field
     * @return array<int, array<string, mixed>>
     */
    private function issueComments(string $encodedKey, array $embedded): array
    {
        $comments = is_array($embedded['comments'] ?? null) ? array_values($embedded['comments']) : [];
        $total = (int) ($embedded['total'] ?? count($comments));

        while (count($comments) < $total) {
            try {
                $page = $this->handleResponse(
                    $this->get(
                        $this->baseUrl.self::API_PREFIX."/issue/{$encodedKey}/comment",
                        ['startAt' => count($comments), 'maxResults' => 100],
                    )
                )->json() ?? [];
            } catch (\Throwable) {
                break;
            }

            $values = is_array($page['comments'] ?? null) ? $page['comments'] : [];

            if ($values === []) {
                break;
            }

            $comments = array_merge($comments, array_values($values));
        }

        return $comments;
    }

    public static function isValidKeySegment(string $value): bool
    {
        return preg_match('/^[A-Z0-9]+$/', $value) === 1;
    }

    private function handleResponse(Response $response): Response
    {
        if ($response->failed()) {
            Log::error('Jira API error', [
                'url' => (string) $response->effectiveUri(),
                'status' => $response->status(),
                'body' => substr((string) $response->body(), 0, 500),
            ]);

            throw new RuntimeException('Jira API returned HTTP '.$response->status());
        }

        return $response;
    }
}
