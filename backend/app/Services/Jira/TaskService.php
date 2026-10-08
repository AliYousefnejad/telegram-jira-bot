<?php

namespace App\Services\Jira;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Enums\ReportType;
use App\Enums\TaskPriority;
use App\Models\JiraUser;
use App\Resources\CommonMessageResource;
use App\Resources\FlowMessageResource;
use App\Resources\PickerResource;
use App\Resources\TaskKeyboardResource;
use App\Resources\TaskMessageResource;
use App\Resources\TaskResource;
use App\Resources\TimelineResource;
use App\Services\Telegram\ConversationManager;
use App\Services\Telegram\InteractiveMessages;
use App\Services\Telegram\ReplyKeyboardService;
use App\Support\CallbackData;
use App\Support\ReplyMarkup;
use Illuminate\Support\Facades\Log;
use Throwable;

class TaskService
{
    private const LIST_PAGE_SIZE = 10;

    public function __construct(
        private JiraServiceInterface $jira,
        private TelegramMessengerInterface $messenger,
        private ConversationManager $conversation,
        private InteractiveMessages $interactive,
        private ReplyKeyboardService $replyKeyboard,
        private ?TaskOwnerService $ownerService = null,
    ) {
        $this->ownerService = $ownerService ?? app(TaskOwnerService::class);
    }

    public function sendTaskDetail(int $chatId, string $key, int $telegramUserId = 0, bool $manage = false, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $raw = strtoupper($key);

        if (! $manage && ctype_digit($raw)) {
            $defaultKey = $this->resolveDefaultProjectKey();

            if ($defaultKey === '') {
                $this->messenger->sendMessage($chatId, TaskMessageResource::taskKeyRequired(), $extra);

                return;
            }

            $key = "{$defaultKey}-{$raw}";
        } else {
            $key = $raw;
        }

        $task = $this->jira->getIssue($key);

        if (! $task || ! isset($task['key'])) {
            $this->messenger->sendMessage($chatId, TaskMessageResource::taskNotFound($key), $extra);

            return;
        }

        $text = TaskResource::toDetail($task);
        $report = ReportType::fromSummary((string) ($task['fields']['summary'] ?? ''));
        $isUserBug = $report === ReportType::UserBug;

        if ($manage) {
            $keyboard = TaskKeyboardResource::taskDetail(
                $key,
                manage: true,
                hasImpactField: $this->jira->customFieldId(config('services.jira.custom_fields.impact')) !== null,
                hasSeverityField: $this->jira->customFieldId(config('services.jira.custom_fields.severity')) !== null,
                hasOwnerField: true,
                hasCategoryField: $isUserBug && $this->jira->customFieldId(config('services.jira.custom_fields.category')) !== null,
            );

            $message = $this->messenger->sendMessage($chatId, $text, array_merge(
                $extra,
                $this->replyKeyboard->extra($keyboard, $telegramUserId, $chatId),
            ));
            $this->interactive->track($telegramUserId, (int) ($message?->getMessageId() ?? 0));

            return;
        }

        $markup = ReplyMarkup::toJson(TaskKeyboardResource::taskDetail($key));

        $this->messenger->sendMessage($chatId, $text, array_merge($extra, ['reply_markup' => $markup]));
    }

    /** @param  array<string, mixed>  $params  extra send params, e.g. the reply keyboard to show with it */
    public function sendTimeline(int $chatId, string $taskKey, ?int $messageThreadId = null, array $params = []): void
    {
        $extra = array_merge($messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [], $params);

        $changelog = $this->jira->getIssueChangelog($taskKey);

        $text = TimelineResource::format($taskKey, $changelog);

        $this->messenger->sendMessage($chatId, $text, $extra);
    }

    /** @param  array<string, mixed>  $params  extra send params, e.g. the reply keyboard to show with it */
    public function sendTimelineForCommand(int $chatId, string $key, ?int $messageThreadId = null, array $params = []): void
    {
        $extra = array_merge($messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [], $params);
        $raw = strtoupper(trim($key));

        if ($raw === '') {
            $this->messenger->sendMessage($chatId, TaskMessageResource::taskKeyRequired(), $extra);

            return;
        }

        if (ctype_digit($raw)) {
            $defaultKey = $this->resolveDefaultProjectKey();

            if ($defaultKey === '') {
                $this->messenger->sendMessage($chatId, TaskMessageResource::taskKeyRequired(), $extra);

                return;
            }

            $key = "{$defaultKey}-{$raw}";
        } else {
            $key = $raw;
        }

        $task = $this->jira->getIssue($key);

        if (! $task || ! isset($task['key'])) {
            $this->messenger->sendMessage($chatId, TaskMessageResource::taskNotFound($key), $extra);

            return;
        }

        $this->sendTimeline($chatId, $key, $messageThreadId, $params);
    }

    public function sendTasksForProject(int $chatId, string $projectKey, bool $isGroup = false, int $messageId = 0, int $page = 1, ?int $editMessageId = null, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        if (! JiraService::isValidKeySegment($projectKey)) {
            $this->messenger->send($chatId, TaskMessageResource::invalidProjectKey(), $isGroup, $messageId, $extra);

            return;
        }

        $data = $this->fetchTasksPage($projectKey, $page);

        $this->sendTasksPage(
            $chatId,
            $projectKey,
            $data['page'],
            $data['issues'],
            $data['hasMore'],
            $isGroup,
            $messageId,
            $editMessageId,
            TaskMessageResource::noTasks(TaskMessageResource::tasksHeader($projectKey)),
            [TaskKeyboardResource::class, 'buildKeyboard'],
            'Edit tasks message failed, falling back to send',
            $messageThreadId,
        );
    }

    public function sendTasksForDetail(int $chatId, string $projectKey, int $page = 1, ?int $editMessageId = null, int $userId = 0, bool $isGroup = false, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        if ($projectKey === '') {
            $projectKey = $this->resolveDefaultProjectKey();
        }

        if ($projectKey === '') {
            $this->messenger->sendMessage($chatId, CommonMessageResource::noProjects(), $extra);

            return;
        }

        if (! JiraService::isValidKeySegment($projectKey)) {
            $this->messenger->sendMessage($chatId, TaskMessageResource::invalidProjectKey(), $extra);

            return;
        }

        $data = $this->fetchTasksPage($projectKey, $page);

        $this->sendTasksPage(
            $chatId,
            $projectKey,
            $data['page'],
            $data['issues'],
            $data['hasMore'],
            $isGroup,
            0,
            $editMessageId,
            TaskMessageResource::noTasks($projectKey),
            [TaskKeyboardResource::class, 'buildDetailKeyboard'],
            'Edit tasks detail message failed, falling back to send',
            $messageThreadId,
        );
    }

    public function showProjectPicker(int $chatId, string $callbackPrefix, bool $isGroup = false, int $messageId = 0, int $userId = 0, bool $interactiveTrack = false, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $projects = $this->jira->getProjects();
        $allowedKeys = config('services.jira.project_keys', []);

        if (! empty($allowedKeys)) {
            $projects = array_values(array_filter($projects, fn (array $p) => in_array($p['key'] ?? '', $allowedKeys, true)));
        }

        if (empty($projects)) {
            $this->messenger->send($chatId, CommonMessageResource::noProjects(), $isGroup, $messageId, $extra);

            return;
        }

        $markup = ReplyMarkup::toJson(PickerResource::projectPicker($projects, $callbackPrefix));

        if ($interactiveTrack && $userId > 0) {
            $message = $this->messenger->sendMessage($chatId, FlowMessageResource::flowPickProject(), array_merge($extra, ['reply_markup' => $markup]));
            $this->interactive->track($userId, (int) ($message?->getMessageId() ?? 0));

            return;
        }

        if ($userId > 0) {
            $this->messenger->sendTrackedMessage($chatId, $userId, FlowMessageResource::flowPickProject(), array_merge($extra, ['reply_markup' => $markup]));
        } else {
            $this->messenger->send($chatId, FlowMessageResource::flowPickProject(), $isGroup, $messageId, array_merge($extra, ['reply_markup' => $markup]));
        }
    }

    public function assignToTask(int $chatId, string $taskKey, string $userName, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $this->jira->assignIssue($taskKey, $userName);

        $assignable = collect($this->jira->getAssignableUsers($taskKey))
            ->first(fn (array $user) => JiraService::userId($user) === $userName);

        $this->messenger->sendMessage(
            $chatId,
            TaskMessageResource::taskAssigned($taskKey, $assignable['displayName'] ?? '?'),
            $extra,
        );
    }

    public function unassignTaskFromFlow(int $chatId, string $taskKey, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $this->jira->unassignIssue($taskKey);

        $this->messenger->sendMessage($chatId, TaskMessageResource::taskUnassigned($taskKey), $extra);
    }

    public function createTaskFromFlow(int $chatId, string $projectKey, string $summary, int $telegramUserId, ?TaskPriority $priority = null, ?string $description = null, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $newKey = $this->createTaskCore($projectKey, $summary, $telegramUserId, $priority, $description);

        $this->messenger->sendMessage($chatId, TaskMessageResource::taskCreated($newKey, $summary), $extra);
    }

    public function moveToSprint(int $chatId, string $taskKey, int $sprintId, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $this->jira->moveIssueToSprint($sprintId, $taskKey);
        $this->messenger->sendMessage($chatId, TaskMessageResource::sprintAdded($taskKey, $sprintId), $extra);
    }

    public function changeStatus(int $chatId, string $taskKey, string $transitionId, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $this->jira->transitionIssue($taskKey, $transitionId);

        try {
            $this->ownerService?->syncOwnerForIssue($taskKey);
        } catch (Throwable $e) {
            Log::warning("Could not auto-sync owner for {$taskKey} after status change: ".$e->getMessage());
        }

        $this->messenger->sendMessage($chatId, TaskMessageResource::statusChangedCallback($taskKey), $extra);
    }

    public function sendUserPickerForKeyboard(int $chatId, string $taskKey, string $prefix, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $users = $this->jira->getAssignableUsers($taskKey);

        if (empty($users)) {
            $this->messenger->sendMessage($chatId, CommonMessageResource::noUsers(), $extra);

            return;
        }

        $this->messenger->sendMessage($chatId, TaskMessageResource::userPickerHeader($taskKey), array_merge($extra, [
            'reply_markup' => ReplyMarkup::toJson(PickerResource::userPicker($users, $prefix, $taskKey)),
        ]));
    }

    public function sendStatusPickerForKeyboard(int $chatId, string $taskKey, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $transitions = $this->reachableBoardTransitions($taskKey, $this->resolveTaskBoardId($taskKey));

        if (empty($transitions)) {
            $this->messenger->sendMessage($chatId, TaskMessageResource::noTransitions($taskKey), $extra);

            return;
        }

        $this->messenger->sendMessage($chatId, TaskMessageResource::statusPickerHeader($taskKey), array_merge($extra, [
            'reply_markup' => ReplyMarkup::toJson(PickerResource::statusPicker($transitions, CallbackData::pickStatusPrefix($taskKey))),
        ]));
    }

    /** @return array<int, array{id: string, name: string}> */
    public function reachableBoardTransitions(string $taskKey, int $boardId): array
    {
        $transitions = $this->jira->getIssueTransitions($taskKey);
        if ($boardId <= 0) {
            return array_map(
                fn (array $t): array => ['id' => (string) ($t['id'] ?? ''), 'name' => (string) ($t['name'] ?? '?')],
                $transitions,
            );
        }

        $boardStatuses = $this->jira->getBoardConfiguration($boardId)['statuses'];

        $columnOrder = [];
        foreach ($boardStatuses as $position => $statusName) {
            $columnOrder[mb_strtolower($statusName)] = $position;
        }

        $filtered = [];

        foreach ($transitions as $transition) {
            $targetName = trim((string) ($transition['to']['name'] ?? $transition['name'] ?? ''));

            if ($targetName !== '' && isset($columnOrder[mb_strtolower($targetName)])) {
                $filtered[] = ['id' => (string) ($transition['id'] ?? ''), 'name' => $targetName];
            }
        }

        usort(
            $filtered,
            fn (array $a, array $b): int => $columnOrder[mb_strtolower($a['name'])] <=> $columnOrder[mb_strtolower($b['name'])],
        );

        return $filtered;
    }

    /** @return array<int, array{id: string, name: string}> */
    public function statusOptionsFor(string $taskKey): array
    {
        return $this->reachableBoardTransitions($taskKey, $this->resolveTaskBoardId($taskKey));
    }

    private function resolveTaskBoardId(string $taskKey): int
    {
        [$projectKey] = array_pad(explode('-', strtoupper($taskKey), 2), 2, '');

        if (! JiraService::isValidKeySegment($projectKey)) {
            return 0;
        }

        try {
            return $this->jira->getProjectBoard($projectKey) ?? 0;
        } catch (Throwable $e) {
            Log::warning('Failed to resolve project board: '.$e->getMessage());

            return 0;
        }
    }

    public function sendBoardPickerForKeyboard(int $chatId, string $taskKey, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $boards = $this->jira->getBoards();

        if (empty($boards)) {
            $this->messenger->sendMessage($chatId, CommonMessageResource::noBoards(), $extra);

            return;
        }

        $this->messenger->sendMessage($chatId, TaskMessageResource::boardPickerHeader($taskKey), array_merge($extra, [
            'reply_markup' => ReplyMarkup::toJson(PickerResource::boardPicker($boards, CallbackData::pickBoardPrefix($taskKey))),
        ]));
    }

    public function sendSprintPickerForBoard(int $chatId, string $taskKey, int $boardId, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $sprints = $this->jira->getSprints($boardId);

        if (empty($sprints)) {
            $this->messenger->sendMessage($chatId, CommonMessageResource::noSprints(), $extra);

            return;
        }

        $this->messenger->sendMessage($chatId, TaskMessageResource::sprintPickerHeader($taskKey), array_merge($extra, [
            'reply_markup' => ReplyMarkup::toJson(PickerResource::sprintPicker($sprints, CallbackData::pickSprintPrefix($taskKey))),
        ]));
    }

    private function resolveDefaultProjectKey(): string
    {
        return strtoupper((string) (config('services.jira.default_project') ?: 'TS'));
    }

    /** @return array{issues: array<int, array<string, mixed>>, hasMore: bool, page: int} */
    private function fetchTasksPage(string $projectKey, int $page): array
    {
        $perPage = self::LIST_PAGE_SIZE;
        $startAt = ($page - 1) * $perPage;
        $jql = "project = \"{$projectKey}\" ORDER BY key ASC";

        $result = $this->jira->getIssues($jql, $perPage, $startAt);

        return [
            'issues' => $result['issues'],
            'hasMore' => $result['hasMore'] ?? false,
            'page' => $page,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @param  callable(array<int, array<string, mixed>>, string, ?int, ?int): array  $keyboardBuilder
     */
    private function sendTasksPage(
        int $chatId,
        string $projectKey,
        int $page,
        array $tasks,
        bool $hasMore,
        bool $isGroup,
        int $messageId,
        ?int $editMessageId,
        string $emptyMessage,
        callable $keyboardBuilder,
        string $editFallbackLog,
        ?int $messageThreadId = null,
    ): void {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        if (empty($tasks)) {
            $this->messenger->send($chatId, $emptyMessage, $isGroup, $messageId, $extra);

            return;
        }

        $text = TaskMessageResource::pageHeader($projectKey, $page).":\n".TaskResource::toListMultiple($tasks);
        $prevPage = $page > 1 ? $page - 1 : null;
        $nextPage = $hasMore ? $page + 1 : null;
        $markup = ReplyMarkup::toJson($keyboardBuilder($tasks, $projectKey, $prevPage, $nextPage));

        if ($this->tryEditListMessage($chatId, $text, $markup, $editMessageId, $editFallbackLog, $messageThreadId)) {
            return;
        }

        $this->messenger->send($chatId, $text, $isGroup, $messageId, array_merge($extra, ['reply_markup' => $markup]));
    }

    private function tryEditListMessage(int $chatId, string $text, string $markup, ?int $editMessageId, string $fallbackLog, ?int $messageThreadId = null): bool
    {
        if ($editMessageId === null) {
            return false;
        }

        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        try {
            $this->messenger->editMessageText($chatId, $editMessageId, $text, array_merge($extra, ['reply_markup' => $markup]));

            return true;
        } catch (Throwable $e) {
            Log::warning($fallbackLog.': '.$e->getMessage());

            return false;
        }
    }

    private function createTaskCore(string $projectKey, string $summary, ?int $telegramUserId, ?TaskPriority $priority = null, ?string $description = null): string
    {
        $reporterUserName = null;

        if ($telegramUserId) {
            $linkedUser = JiraUser::where('telegram_admin_id', $telegramUserId)->first();
            $reporterUserName = $linkedUser?->jira_account_id;
        }

        $extraFields = [];
        if ($priority !== null) {
            $extraFields['priority'] = ['name' => $priority->value];
        }

        $result = $this->jira->createIssue($projectKey, $summary, config('services.jira.default_issue_type'), $description, null, $reporterUserName, $extraFields);

        return $result['key'] ?? '?';
    }

    private function unassignCore(string $taskKey, ?int $telegramUserId): void
    {
        $this->jira->unassignIssue($taskKey);

        $task = Task::where('jira_issue_key', $taskKey)->first();
        if ($task) {
            $task->update([
                'assignee_id' => null,
                'assigned_by_telegram_id' => $telegramUserId,
            ]);
        }
    }
}
