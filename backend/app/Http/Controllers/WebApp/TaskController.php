<?php

namespace App\Http\Controllers\WebApp;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Enums\EditAction;
use App\Exceptions\JiraUserTokenRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebApp\UpdateTaskRequest;
use App\Http\Resources\WebApp\TaskPayload;
use App\Models\JiraUser;
use App\Resources\TaskShareMessageResource;
use App\Services\Domain\SharedTaskMessages;
use App\Services\Domain\TaskEditService;
use App\Services\Domain\TaskQueryService;
use App\Services\Jira\JiraService;
use App\Services\Jira\TaskOwnerService;
use App\Services\Jira\TaskService;
use App\Support\CommentFooter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

class TaskController extends Controller
{
    public function __construct(
        private TaskQueryService $tasks,
        private JiraServiceInterface $jira,
        private TelegramMessengerInterface $messenger,
        private TaskEditService $edits,
        private TaskOwnerService $owners,
        private SharedTaskMessages $shared,
    ) {}

    public function statuses(): JsonResponse
    {
        return response()->json($this->tasks->statusesWithCounts(self::project()));
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $status = is_string($status) && $status !== '' && $status !== 'all' ? $status : null;

        return response()->json([
            'tasks' => TaskPayload::collection($this->tasks->tasks(self::project(), $status)),
        ]);
    }

    public function show(string $key): JsonResponse
    {
        if (! self::isProjectKey($key)) {
            return response()->json(['error' => 'invalid_key'], 422);
        }

        $issue = $this->jira->getIssue(strtoupper($key));

        return $issue === null
            ? response()->json(['error' => 'not_found'], 404)
            : response()->json(['task' => TaskPayload::detail($issue)]);
    }

    /** People the task can be assigned to in this project, with their linked Telegram usernames. */
    public function assignees(): JsonResponse
    {
        $users = $this->jira->getAssignableUsersForProject(self::project());
        $ids = array_map(fn (array $user): string => JiraService::userId($user), $users);
        $handles = JiraUser::query()->whereIn('jira_account_id', $ids)
            ->whereNotNull('telegram_admin_id')
            ->pluck('telegram_username', 'jira_account_id');

        return response()->json(['assignees' => array_map(fn (array $user): array => [
            'id' => JiraService::userId($user),
            'name' => (string) ($user['displayName'] ?? JiraService::userId($user)),
            'telegram' => ($handle = ltrim((string) ($handles[JiraService::userId($user)] ?? ''), '@')) !== '' ? '@'.$handle : null,
        ], $users)]);
    }

    /**
     * What a task can be changed to: the board statuses reachable from its current status (each with the owner
     * it hands the task to) and, for user bug reports, the category options.
     */
    public function options(string $key): JsonResponse
    {
        if (! self::isProjectKey($key)) {
            return response()->json(['error' => 'invalid_key'], 422);
        }

        $key = strtoupper($key);
        $transitions = app(TaskService::class)->statusOptionsFor($key);

        return response()->json([
            'transitions' => array_map(fn (array $t): array => $t + ['owner' => $this->owners->resolveOwnerForStatus($t['name'])?->value], $transitions),
            'categories' => $this->edits->categoryOptions($key),
        ]);
    }

    /**
     * Applies the edits staged in the app, in the bot's order, and returns which ones Jira accepted plus the
     * task as it is now. A status change without an owner choice syncs the owner, as the bot does.
     */
    public function update(UpdateTaskRequest $request, string $key): JsonResponse
    {
        if (! self::isProjectKey($key)) {
            return response()->json(['error' => 'invalid_key'], 422);
        }

        $key = strtoupper($key);
        $edits = $request->validated();
        $results = [];

        foreach (EditAction::values() as $action) {
            if (! array_key_exists($action, $edits)) {
                continue;
            }

            $value = $edits[$action];

            if ($action === EditAction::Comment->value) {
                $value = $this->commentBody((string) $value, (int) $request->attributes->get('telegram_launch')['user']['id']);
            }

            try {
                $this->edits->apply($key, $action, $value === null ? null : (string) $value);
                $results[$action] = true;
            } catch (JiraUserTokenRejected $e) {
                throw $e;
            } catch (Throwable $e) {
                Log::error("App edit failed: {$key}/{$action}: ".$e->getMessage());
                $results[$action] = false;
            }
        }

        if (($results['status'] ?? false) && ! array_key_exists('owner', $edits)) {
            try {
                $this->owners->syncOwnerForIssue($key);
            } catch (Throwable $e) {
                Log::warning("Could not sync the owner of {$key} after a status edit: ".$e->getMessage());
            }
        }

        $this->tasks->forgetTasks();
        $issue = $this->jira->getIssue($key);

        if (in_array(true, $results, true)) {
            // Messages already sent to chats show the task as it is now; done after the response.
            defer(fn () => $this->shared->refresh($key));
        }

        return response()->json([
            'results' => $results,
            'task' => $issue === null ? null : TaskPayload::detail($issue),
        ], in_array(true, $results, true) || $results === [] ? 200 : 502);
    }

    /** With the bot's token Jira shows the bot as the author, so the comment names who wrote it (as the bot does). */
    private function commentBody(string $comment, int $telegramUserId): string
    {
        $link = JiraUser::query()->where('telegram_admin_id', $telegramUserId)->first();

        return $link === null || $link->hasPersonalToken() ? $comment : CommentFooter::append($comment, (string) $link->display_name);
    }

    /**
     * Posts the task to the chat the Mini App was opened from. With ?preview=1 it only
     * returns the message, so the app can show exactly what will be sent.
     */
    public function share(Request $request, string $key): JsonResponse
    {
        if (! self::isProjectKey($key)) {
            return response()->json(['error' => 'invalid_key'], 422);
        }

        $issue = $this->jira->getIssue(strtoupper($key));

        if ($issue === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $user = $request->attributes->get('telegram_launch')['user'];
        $text = TaskShareMessageResource::forIssue($issue, $user);

        if ($request->boolean('preview')) {
            return response()->json(['html' => $text]);
        }

        try {
            // The app is opened from the private chat with the bot, so "this chat" is the user's chat id.
            $sent = $this->messenger->sendMessage((int) $user['id'], $text, SharedTaskMessages::PARSE);
            $this->shared->remember(strtoupper($key), (int) $user['id'], $sent, $user);
        } catch (Throwable $e) {
            Log::warning("Sharing {$key} to Telegram failed: ".$e->getMessage());

            return response()->json(['error' => 'send_failed'], 502);
        }

        return response()->json(['sent' => true]);
    }

    public static function project(): string
    {
        return strtoupper((string) (config('services.jira.default_project') ?: 'TS'));
    }

    private static function isProjectKey(string $key): bool
    {
        [$project, $number] = array_pad(explode('-', strtoupper($key), 2), 2, '');

        return JiraService::isValidKeySegment($project) && ctype_digit($number) && $project === self::project();
    }
}
