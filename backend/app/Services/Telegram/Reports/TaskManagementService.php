<?php

namespace App\Services\Telegram\Reports;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Enums\CallbackAction;
use App\Enums\EditAction;
use App\Enums\ReportType;
use App\Enums\TaskImpact;
use App\Enums\TaskOwner;
use App\Enums\TaskSeverity;
use App\Models\JiraUser;
use App\Resources\CommonMessageResource;
use App\Resources\FlowMessageResource;
use App\Resources\PickerResource;
use App\Resources\ReportsMessageResource;
use App\Resources\TaskKeyboardResource;
use App\Resources\TaskMessageResource;
use App\Resources\TaskResource;
use App\Services\Domain\SharedTaskMessages;
use App\Services\Domain\TaskEditService;
use App\Services\Jira\JiraService;
use App\Services\Jira\TaskOwnerService;
use App\Services\Jira\TaskService;
use App\Services\Telegram\AccessService;
use App\Services\Telegram\ConversationManager;
use App\Services\Telegram\InteractiveMessages;
use App\Services\Telegram\ReplyKeyboardService;
use App\Support\CallbackData;
use App\Support\CallbackDebounce;
use App\Support\CommentFooter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Objects\CallbackQuery;
use Throwable;

class TaskManagementService
{
    private const PER_PAGE = 10;

    private const DEBOUNCE_SCOPE_ENTRY = 'rpt_entry';

    private const DEBOUNCE_SCOPE_CALLBACK = 'rpt_cb';

    private const CONTEXT_CACHE_PREFIX = 'rpt_ctx_';

    private const STATUS_MAP_CACHE_PREFIX = 'rpt_statuses_';

    private const DESCRIPTION_PENDING_PREFIX = 'rpt_desc_';

    private const FILTER_ALL_OPEN = 'open';

    private const PAGE_PREV = 'prev';

    private const PAGE_NEXT = 'next';

    private const ASSIGNEE_LIMIT = 50;

    private const DESCRIPTION_PREVIEW = 300;

    private const MIN_DESCRIPTION_LENGTH = 5;

    private const STAGED_LABEL_TRUNCATE = 40;

    private const DESC_USERINFO_PENDING_PREFIX = 'rpt_desc_ui_';

    private const TIMELINE_KEY_PENDING_PREFIX = 'rpt_timeline_pending_';

    private const COMMENT_PENDING_PREFIX = 'rpt_comment_';

    private const MIN_COMMENT_LENGTH = 2;

    public function __construct(
        private JiraServiceInterface $jira,
        private TelegramMessengerInterface $messenger,
        private AccessService $access,
        private InteractiveMessages $interactive,
        private ConversationManager $conversation,
        private ReplyKeyboardService $replyKeyboard,
        private ?TaskOwnerService $ownerService = null,
    ) {
        $this->ownerService = $ownerService ?? app(TaskOwnerService::class);
    }

    public function openProjectPicker(int $chatId, int $userId, int $messageId = 0, bool $isGroup = false, ?int $messageThreadId = null, string $mode = 'manage'): void
    {
        if (! $this->ensureAdminAndLinked($chatId, $userId, $messageThreadId)) {
            return;
        }

        CallbackDebounce::run(self::DEBOUNCE_SCOPE_ENTRY, $userId, 'open_picker', config('telegram.reports.callback_lock_seconds'),
            function () use ($chatId, $userId, $messageThreadId, $mode): void {
                if ($this->conversation->isActive($userId) || $this->interactive->hasTracked($userId)) {
                    $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
                    $this->messenger->sendMessage($chatId, FlowMessageResource::alreadyWaiting(), $extra);

                    return;
                }

                $this->startBrowsing(self::supportProject(), $chatId, $userId, $messageThreadId, $mode);
            });
    }

    public function openTimelineMenu(int $chatId, int $userId, int $messageId = 0, bool $isGroup = false, ?int $messageThreadId = null): void
    {
        if (! $this->ensureAdminAndLinked($chatId, $userId, $messageThreadId)) {
            return;
        }

        CallbackDebounce::run(self::DEBOUNCE_SCOPE_ENTRY, $userId, 'open_timeline_menu', config('telegram.reports.callback_lock_seconds'),
            function () use ($chatId, $userId, $messageThreadId): void {
                if ($this->conversation->isActive($userId) || $this->interactive->hasTracked($userId)) {
                    $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
                    $this->messenger->sendMessage($chatId, FlowMessageResource::alreadyWaiting(), $extra);

                    return;
                }

                $this->renderTimelineMenu($chatId, $userId, $messageThreadId);
            });
    }

    private function renderTimelineMenu(int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $this->cleanupInteractive($chatId, $userId);
        $this->updateContext($userId, ['mode' => 'timeline', 'project' => null]);

        $text = __('timeline.menu.title');
        $markup = [
            'inline_keyboard' => [
                [
                    ['text' => __('timeline.menu.by_status'), 'callback_data' => CallbackAction::RptTimeline->value.'status'],
                ],
                [
                    ['text' => __('timeline.menu.by_key'), 'callback_data' => CallbackAction::RptTimeline->value.'key'],
                ],
            ],
        ];

        $this->sendInteractive($chatId, $userId, $text, $markup, $messageThreadId, withBack: false);
    }

    public function handleCallback(CallbackQuery $callbackQuery, ?int $messageThreadId = null): void
    {
        $data = $callbackQuery->getData() ?? '';
        $chatId = (int) ($callbackQuery->getMessage()?->getChat()?->getId() ?? 0);
        $userId = (int) ($callbackQuery->getFrom()?->getId() ?? 0);

        $threadId = $callbackQuery->getMessage()?->get('message_thread_id');
        $messageThreadId = $threadId !== null ? (int) $threadId : null;

        $this->messenger->answerCallback($callbackQuery);

        if ($chatId === 0 || ! $this->ensureAdminAndLinked($chatId, $userId, $messageThreadId)) {
            return;
        }

        if ($this->conversation->isActive($userId)) {
            $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
            $this->messenger->sendMessage($chatId, FlowMessageResource::alreadyWaiting(), $extra);

            return;
        }

        CallbackDebounce::run(self::DEBOUNCE_SCOPE_CALLBACK, $userId, $data, config('telegram.reports.callback_lock_seconds'), function () use ($data, $chatId, $userId, $messageThreadId) {
            try {
                $this->dispatch($data, $chatId, $userId, $messageThreadId);
            } catch (Throwable $e) {
                Log::error('Task management error: '.$e->getMessage(), [
                    'data' => $data,
                    'at' => collect($e->getTrace())->take(4)->pluck('file', 'line')->all(),
                ]);
                $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
                $this->messenger->sendMessage($chatId, CommonMessageResource::internalError(), $extra);
            }
        });
    }

    private function dispatch(string $data, int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        if (($projectKey = CallbackData::stripIf($data, CallbackAction::RptProject)) !== null) {
            $this->startBrowsing(strtoupper($projectKey), $chatId, $userId, $messageThreadId);

            return;
        }

        if (($statusReference = CallbackData::stripIf($data, CallbackAction::RptFilter)) !== null) {
            $this->applyFilter($statusReference, $chatId, $userId, $messageThreadId);

            return;
        }

        if (($direction = CallbackData::stripIf($data, CallbackAction::RptPage)) !== null) {
            $this->turnPage($direction === self::PAGE_NEXT ? 1 : -1, $chatId, $userId, $messageThreadId);

            return;
        }

        if (($taskKey = CallbackData::stripIf($data, CallbackAction::RptTask)) !== null) {
            $this->cleanupInteractive($chatId, $userId);
            $this->clearStagedEdits($userId);
            $this->updateContext($userId, [
                'current_task' => strtoupper($taskKey),
                'editing_task' => null,
                'editing_field' => null,
            ]);
            $context = $this->context($userId);
            if (($context['mode'] ?? 'manage') === 'timeline') {
                $this->resetContext($userId);
                $this->jiraTasks()->sendTimeline($chatId, strtoupper($taskKey), $messageThreadId, $this->replyKeyboard->mainMenu($userId, $chatId));

                return;
            }

            $this->jiraTasks()->sendTaskDetail($chatId, strtoupper($taskKey), $userId, manage: true, messageThreadId: $messageThreadId);

            return;
        }

        if (($remainder = CallbackData::stripIf($data, CallbackAction::RptAct)) !== null) {
            [$action, $taskKey] = array_pad(explode('_', $remainder, 2), 2, '');
            $this->updateContext($userId, [
                'editing_task' => strtoupper($taskKey),
                'editing_field' => $action,
                'current_task' => strtoupper($taskKey),
            ]);
            $this->startEditAction($action, strtoupper($taskKey), $chatId, $userId, $messageThreadId);

            return;
        }

        if (($remainder = CallbackData::stripIf($data, CallbackAction::RptSet)) !== null) {
            [$action, $taskKey, $value] = array_pad(explode('_', $remainder, 3), 3, '');
            $this->updateContext($userId, [
                'editing_task' => null,
                'editing_field' => null,
                'current_task' => strtoupper($taskKey),
            ]);
            $this->stageEditValue($action, strtoupper($taskKey), $value, $chatId, $userId, $messageThreadId);

            return;
        }

        if (($taskKey = CallbackData::stripIf($data, CallbackAction::RptConfirm)) !== null) {
            $this->confirmStagedEdits(strtoupper($taskKey), $chatId, $userId, $messageThreadId);

            return;
        }

        if (($taskKey = CallbackData::stripIf($data, CallbackAction::RptDiscard)) !== null) {
            $this->discardStagedEdits(strtoupper($taskKey), $chatId, $userId, $messageThreadId);

            return;
        }

        if (($remainder = CallbackData::stripIf($data, CallbackAction::RptDescUserInfo)) !== null) {
            $this->handleDescUserInfoCallback($remainder, $chatId, $userId, $messageThreadId);

            return;
        }

        if (($timelineOpt = CallbackData::stripIf($data, CallbackAction::RptTimeline)) !== null) {
            $this->handleTimelineOption($timelineOpt, $chatId, $userId, $messageThreadId);

            return;
        }

        if (($taskKey = CallbackData::stripIf($data, CallbackAction::RptCancelTask)) !== null) {
            $this->cancelTaskView($chatId, $userId, $messageThreadId);

            return;
        }

        if (($taskKey = CallbackData::stripIf($data, CallbackAction::RptCancelSection)) !== null) {
            $this->updateContext($userId, [
                'editing_task' => null,
                'editing_field' => null,
                'desc_ui_task' => null,
                'desc_ui_data' => null,
                'desc_ui_staging_field' => null,
            ]);
            $this->returnToTask(strtoupper($taskKey), $chatId, $userId, $messageThreadId);

            return;
        }

        if ($data === CallbackAction::RptCancel->value) {
            $this->handleCancel($chatId, $userId, $messageThreadId);

            return;
        }

        if ($data === CallbackAction::RptExit->value) {
            $this->exitSession($chatId, $userId, $messageThreadId);

            return;
        }

        Log::warning('Unhandled report callback payload', ['data' => $data]);
    }

    private function startBrowsing(string $projectKey, int $chatId, int $userId, ?int $messageThreadId = null, string $mode = 'manage'): void
    {
        if ($projectKey === '' || ! JiraService::isValidKeySegment($projectKey)) {
            return;
        }

        $this->cleanupInteractive($chatId, $userId);
        $this->updateContext($userId, [
            'mode' => $mode,
            'project' => $projectKey,
            'status_id' => null,
            'status_name' => null,
            'filter_all_open' => null,
            'page' => 1,
            'pending_task' => null,
            'pending' => null,
            'current_task' => null,
            'editing_task' => null,
            'editing_field' => null,
        ]);
        $this->renderStatusMenu($chatId, $userId, $messageThreadId);
    }

    private function renderStatusMenu(int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $context = $this->context($userId);
        $projectKey = strtoupper((string) ($context['project'] ?? ''));

        if ($projectKey === '') {
            return;
        }

        $statuses = $this->boardStatuses($projectKey);

        if (empty($statuses)) {
            $statuses = $this->jira->getProjectStatuses($projectKey);
        }

        if (empty($statuses)) {
            $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
            // $this->messenger->sendMessage($chatId, ReportsMessageResource::noStatuses(), $extra);

            return;
        }

        $buttons = array_map(
            fn (array $status): array => ['text' => $status['name'], 'callback_data' => CallbackAction::RptFilter->value.$status['id']],
            $statuses,
        );

        $rows = array_chunk($buttons, 2);
        $rows[] = [['text' => __('reports.manage.all_open'), 'callback_data' => CallbackAction::RptFilter->value.self::FILTER_ALL_OPEN]];

        $this->rememberStatusMap($userId, $statuses);

        $this->cleanupInteractive($chatId, $userId);
        $this->sendInteractive($chatId, $userId, ReportsMessageResource::filterLabel(), ['inline_keyboard' => $rows], $messageThreadId, withBack: ($context['mode'] ?? 'manage') === 'timeline');
    }

    /** @return array<int, array{id: string, name: string, category: string}> */
    private function boardStatuses(string $projectKey): array
    {
        try {
            $boardId = $this->jira->getProjectBoard($projectKey);

            if ($boardId === null) {
                return [];
            }

            return array_map(
                fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'category' => ''],
                $this->jira->getBoardConfiguration($boardId)['status_rows'],
            );
        } catch (Throwable $e) {
            Log::warning('Board status lookup failed: '.$e->getMessage());

            return [];
        }
    }

    private function applyFilter(string $statusReference, int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $context = $this->context($userId);

        if ($statusReference === self::FILTER_ALL_OPEN) {
            $this->updateContext($userId, [
                'status_id' => null,
                'status_name' => null,
                'filter_all_open' => true,
                'page' => 1,
                'project' => $context['project'] ?? '',
                'current_task' => null,
                'editing_task' => null,
                'editing_field' => null,
            ]);
            $this->renderOpenList($chatId, $userId, $messageThreadId);

            return;
        }

        $statusName = $this->knownStatusName($userId, $statusReference);

        if ($statusName === null) {
            return;
        }

        $this->updateContext($userId, [
            'status_id' => $statusReference,
            'status_name' => $statusName,
            'filter_all_open' => null,
            'page' => 1,
            'project' => $context['project'] ?? '',
            'current_task' => null,
            'editing_task' => null,
            'editing_field' => null,
        ]);
        $this->renderOpenList($chatId, $userId, $messageThreadId);
    }

    private function turnPage(int $delta, int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $context = $this->context($userId);
        $page = max(1, (int) ($context['page'] ?? 1) + $delta);

        $this->updateContext($userId, ['page' => $page]);
        $this->renderOpenList($chatId, $userId, $messageThreadId);
    }

    private function renderOpenList(int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $context = $this->context($userId);
        $projectKey = strtoupper((string) ($context['project'] ?? ''));

        if ($projectKey === '') {
            return;
        }

        $page = max(1, (int) ($context['page'] ?? 1));
        $jql = "project = \"{$projectKey}\"";

        if (! empty($context['status_name'])) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $context['status_name']);
            $jql .= ' AND status = "'.$escaped.'"';
        } else {
            $jql .= ' AND statusCategory != Done';
        }

        $jql .= ' ORDER BY updated DESC';

        $result = $this->jira->getIssues($jql, config('telegram.reports.fetch_limit'), 0, withCustomFields: true);
        $issues = $result['issues'];

        if (empty($issues)) {
            $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
            $this->messenger->sendMessage($chatId, ReportsMessageResource::noneOpen(), $extra);

            return;
        }

        $this->sortByCriticality($issues);

        $offset = ($page - 1) * self::PER_PAGE;
        $slice = array_slice($issues, $offset, self::PER_PAGE);

        if ($slice === []) {
            $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
            $this->messenger->sendMessage($chatId, ReportsMessageResource::noneOpen(), $extra);

            return;
        }

        $rows = array_map(
            fn (array $issue): array => [[
                'text' => trim(TaskResource::toManageListRow($issue).' '.$this->badges($issue)),
                'callback_data' => CallbackAction::RptTask->value.($issue['key'] ?? ''),
            ]],
            $slice,
        );

        $rows[] = $this->paginationRow($page, count($issues) > $page * self::PER_PAGE);

        $this->sendInteractive($chatId, $userId, ReportsMessageResource::openHeader($projectKey, $page), ['inline_keyboard' => $rows], $messageThreadId);
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    private function paginationRow(int $page, bool $hasMore): array
    {
        $row = [];

        if ($page > 1) {
            $row[] = ['text' => __('keyboard.button.previous_page'), 'callback_data' => CallbackAction::RptPage->value.self::PAGE_PREV];
        }

        if ($hasMore) {
            $row[] = ['text' => __('keyboard.button.next_page'), 'callback_data' => CallbackAction::RptPage->value.self::PAGE_NEXT];
        }

        return $row;
    }

    /** "[S2] [I3]" style badges from raw custom-field values; '' when both are unset. */
    private function badges(array $issue): string
    {
        $fields = $issue['fields'] ?? [];
        $badges = [];

        $severity = TaskSeverity::fromJiraOption($this->customFieldValue($fields, 'severity'));
        if ($severity !== null) {
            $badges[] = '['.$severity->badge().']';
        }

        $impact = TaskImpact::fromJiraOption($this->customFieldValue($fields, 'impact'));
        if ($impact !== null) {
            $badges[] = '['.$impact->badge().']';
        }

        $owner = TaskOwner::fromJiraOption($this->customFieldValue($fields, 'owner'));
        if ($owner !== null) {
            $badges[] = '['.$owner->badge().']';
        }

        return implode(' ', $badges);
    }

    private function customFieldValue(array $fields, string $action): ?string
    {
        $fieldId = $this->jira->customFieldId(config("services.jira.custom_fields.{$action}"));

        if ($fieldId === null) {
            return null;
        }

        $value = $fields[$fieldId] ?? null;

        if (is_array($value)) {
            $value = $value['value'] ?? null;
        }

        return $value === null ? null : (string) $value;
    }

    /** Most critical first: severity rank, then impact rank, newest as tiebreak. */
    private function sortByCriticality(array &$issues): void
    {
        usort($issues, fn (array $a, array $b): int => $this->compareByCriticality($a, $b));
    }

    private function compareByCriticality(array $a, array $b): int
    {
        $severityRank = fn (array $issue): int => $this->enumRank(
            TaskSeverity::fromJiraOption($this->customFieldValue($issue['fields'] ?? [], 'severity')),
        );
        $impactRank = fn (array $issue): int => $this->enumRank(
            TaskImpact::fromJiraOption($this->customFieldValue($issue['fields'] ?? [], 'impact')),
        );

        foreach ([$severityRank, $impactRank] as $rank) {
            if (($diff = $rank($a) <=> $rank($b)) !== 0) {
                return $diff;
            }
        }

        return strcasecmp((string) ($b['fields']['updated'] ?? ''), (string) ($a['fields']['updated'] ?? ''));
    }

    /** Enum cases are ordered most-critical first; unmapped values sink to the bottom. */
    private function enumRank(?object $enum): int
    {
        return $enum === null ? 99 : array_search($enum, $enum::cases(), true);
    }

    private function startEditAction(string $action, string $taskKey, int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        switch ($action) {
            case 'status':
                $transitions = $this->jiraTasks()->statusOptionsFor($taskKey);

                if (empty($transitions)) {
                    $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
                    $this->messenger->sendMessage($chatId, TaskMessageResource::noTransitions($taskKey), $extra);

                    return;
                }

                $buttons = array_map(
                    fn (array $t): array => [['text' => (string) ($t['name'] ?? '?'), 'callback_data' => CallbackAction::RptSet->value."status_{$taskKey}_".($t['id'] ?? '')]],
                    $transitions,
                );

                $this->sendInteractive($chatId, $userId, ReportsMessageResource::pickStatusTransition(), ['inline_keyboard' => $buttons], $messageThreadId);

                return;

            case 'assignee':
                $users = $this->jira->getAssignableUsers($taskKey);

                if (empty($users)) {
                    $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
                    $this->messenger->sendMessage($chatId, CommonMessageResource::noUsers(), $extra);

                    return;
                }

                $buttons = array_map(
                    fn (array $user): array => ['text' => (string) ($user['displayName'] ?? '?'), 'callback_data' => CallbackAction::RptSet->value."assignee_{$taskKey}_".JiraService::userId($user)],
                    array_slice($users, 0, self::ASSIGNEE_LIMIT),
                );

                $this->sendInteractive($chatId, $userId, ReportsMessageResource::pickAssignee(), ['inline_keyboard' => array_chunk($buttons, 2)], $messageThreadId);

                return;

                // Priority editing disabled — uncomment to restore
                // (also re-add "use App\Enums\TaskPriority;").
                // case 'priority':
                //     $this->sendInteractive(
                //         $chatId,
                //         $userId,
                //         ReportsMessageResource::actionLabel('priority'),
                //         PickerResource::optionPicker(TaskPriority::values(), CallbackAction::RptSet->value."priority_{$taskKey}_"),
                //     );
                //
                //     return;

            case 'impact':
                $this->offerEnumValues(TaskImpact::values(), 'impact', $taskKey, $chatId, $userId, TaskImpact::options(), $messageThreadId);

                return;

            case 'severity':
                $this->offerEnumValues(TaskSeverity::values(), 'severity', $taskKey, $chatId, $userId, TaskSeverity::options(), $messageThreadId);

                return;

            case 'owner':
                $this->offerEnumValues(TaskOwner::values(), 'owner', $taskKey, $chatId, $userId, TaskOwner::options(), $messageThreadId);

                return;

            case 'category':
                $issue = $this->jira->getIssue($taskKey);
                $summary = (string) ($issue['fields']['summary'] ?? '');
                if (ReportType::fromSummary($summary) !== ReportType::UserBug) {
                    $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
                    $this->messenger->sendMessage($chatId, ReportsMessageResource::fieldUnavailable(), $extra);

                    return;
                }

                $fieldId = $this->jira->customFieldId((string) config('services.jira.custom_fields.category', 'Ticket Category'));

                if ($fieldId === null) {
                    $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
                    $this->messenger->sendMessage($chatId, ReportsMessageResource::fieldUnavailable(), $extra);

                    return;
                }

                $options = $this->jira->fieldAllowedOptions($taskKey, $fieldId);
                if (empty($options)) {
                    $projectKey = strtoupper(explode('-', $taskKey)[0] ?: (config('services.jira.default_project') ?: 'TS'));
                    $options = $this->jira->getCreateFieldAllowedOptions($projectKey, $fieldId, 'Bug');
                }

                if (empty($options)) {
                    $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
                    $this->messenger->sendMessage($chatId, __('reports.manage.no_category_options'), $extra);

                    return;
                }

                $buttons = array_map(
                    fn (array $opt): array => ['text' => (string) ($opt['value'] ?? '?'), 'callback_data' => CallbackAction::RptSet->value."category_{$taskKey}_".($opt['id'] ?? '')],
                    $options,
                );

                $this->sendInteractive($chatId, $userId, __('reports.manage.pick_category'), ['inline_keyboard' => array_chunk($buttons, 2)], $messageThreadId);

                return;

            case 'description':
                $issue = $this->jira->getIssue($taskKey);
                $summary = (string) ($issue['fields']['summary'] ?? '');
                $reportType = ReportType::fromSummary($summary);

                if ($reportType === ReportType::UserBug) {
                    $this->startUserBugDescriptionEdit($taskKey, $issue, $chatId, $userId, $messageThreadId);

                    return;
                }

                $currentDescription = trim(TaskResource::toPlainText($issue['fields']['description'] ?? null));

                Cache::put(
                    $this->descriptionPendingKey($userId),
                    $taskKey,
                    now()->addMinutes(config('telegram.reports.description_pending_ttl_minutes')),
                );

                $prompt = ReportsMessageResource::enterDescription($taskKey);

                if ($currentDescription !== '') {
                    $prompt .= "\n———\n".mb_substr($currentDescription, 0, self::DESCRIPTION_PREVIEW);
                }

                $this->sendInteractive($chatId, $userId, $prompt, [], $messageThreadId);

                return;

            case 'comment':
                Cache::put(
                    $this->commentPendingKey($userId),
                    $taskKey,
                    now()->addMinutes(config('telegram.reports.description_pending_ttl_minutes')),
                );

                $this->sendInteractive($chatId, $userId, ReportsMessageResource::enterComment($taskKey), [], $messageThreadId);

                return;
        }
    }

    /**
     * @param  array<int, string>  $values
     * @param  array<string, string>  $labels
     */
    private function offerEnumValues(array $values, string $field, string $taskKey, int $chatId, int $userId, array $labels = [], ?int $messageThreadId = null): void
    {
        $fieldId = $this->jira->customFieldId(config('services.jira.custom_fields.'.$field));

        if ($fieldId === null) {
            $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
            $this->messenger->sendMessage($chatId, ReportsMessageResource::fieldUnavailable(), $extra);

            return;
        }

        $prefix = CallbackAction::RptSet->value."{$field}_{$taskKey}_";

        $keyboard = PickerResource::optionPicker($values, $prefix, labels: $labels, withCancel: false);
        // Skip button disabled — uncomment to restore.
        // $keyboard['inline_keyboard'][] = [
        //     ['text' => __('reports.manage.skip'), 'callback_data' => CallbackAction::RptSkip->value.$taskKey],
        // ];

        $this->sendInteractive($chatId, $userId, ReportsMessageResource::actionLabel($field), $keyboard, $messageThreadId);
    }

    private function stageEditValue(string $action, string $taskKey, string $value, int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $label = match (true) {
            $action === 'status' => $this->transitionNameById($taskKey, $value) ?? $value,
            $action === 'impact' => TaskImpact::tryFrom($value)?->label() ?? $value,
            $action === 'severity' => TaskSeverity::tryFrom($value)?->label() ?? $value,
            $action === 'owner' => TaskOwner::tryFrom($value)?->label() ?? $value,
            $action === 'category' => $this->categoryOptionNameById($taskKey, $value) ?? $value,
            default => $value,
        };

        $this->stageEdit($userId, $taskKey, $action, $value, $label);
        $this->renderStagedEditMenu($taskKey, $this->stagedLabels($userId), $chatId, $userId, $messageThreadId);
    }

    private function confirmStagedEdits(string $taskKey, int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $context = $this->context($userId);
        $pending = $context['pending'] ?? [];

        if (($context['pending_task'] ?? '') !== $taskKey || $pending === []) {
            $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
            $this->messenger->sendMessage($chatId, ReportsMessageResource::nothingStaged(), $extra);

            return;
        }

        $results = [];

        foreach (EditAction::values() as $action) {
            if (! isset($pending[$action])) {
                continue;
            }

            try {
                $this->applyStagedValue($taskKey, $action, (string) $pending[$action]['value']);
                $results[$action] = ['ok' => true, 'label' => (string) $pending[$action]['label']];
            } catch (Throwable $e) {
                Log::error("Staged edit failed: {$taskKey}/{$action}: ".$e->getMessage());
                $results[$action] = ['ok' => false, 'label' => (string) $pending[$action]['label']];
            }
        }

        if (isset($results['status']) && $results['status']['ok'] === true && ! isset($pending['owner'])) {
            try {
                $this->ownerService?->syncOwnerForIssue($taskKey);
            } catch (Throwable $e) {
                Log::warning("Could not auto-sync owner for {$taskKey} after status edit: ".$e->getMessage());
            }
        }

        $userInfo = $context['desc_ui_user_info'] ?? null;

        if ($userInfo !== null && isset($results['description']) && $results['description']['ok'] === true) {
            try {
                $issue = $this->jira->getIssue($taskKey);

                if ($issue !== null) {
                    $currentSummary = (string) ($issue['fields']['summary'] ?? '');
                    $reportType = ReportType::fromSummary($currentSummary);

                    if ($reportType === ReportType::UserBug) {
                        $newSummary = $this->buildReportSummary($reportType, $userInfo);
                        $this->jira->updateIssueField($taskKey, 'summary', $newSummary);
                    }
                }
            } catch (Throwable $e) {
                Log::error("Summary update failed for {$taskKey}: ".$e->getMessage());
            }
        }

        if (in_array(true, array_column($results, 'ok'), true)) {
            app(SharedTaskMessages::class)->refresh($taskKey);
        }

        $this->clearStagedEdits($userId);
        $this->cleanupInteractive($chatId, $userId);
        $this->resetContext($userId);
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $this->messenger->sendMessage($chatId, ReportsMessageResource::appliedSummary($taskKey, $results), array_merge(
            $extra,
            $this->replyKeyboard->mainMenu($userId, $chatId),
        ));
    }

    private function discardStagedEdits(string $taskKey, int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $context = $this->context($userId);

        if (($context['pending_task'] ?? '') !== $taskKey || empty($context['pending'])) {
            return;
        }

        $this->clearStagedEdits($userId);
        $this->cleanupInteractive($chatId, $userId);
        $this->resetContext($userId);
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $this->messenger->sendMessage($chatId, ReportsMessageResource::stagedDiscarded($taskKey), array_merge(
            $extra,
            $this->replyKeyboard->mainMenu($userId, $chatId),
        ));
    }

    private function applyStagedValue(string $taskKey, string $action, string $value): void
    {
        (new TaskEditService($this->jira))->apply($taskKey, $action, $value);
    }

    /**
     * @param  array<string, string>  $labels  action => pending display value
     */
    private function renderStagedEditMenu(string $taskKey, array $labels, int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $this->cleanupInteractive($chatId, $userId);

        $task = $this->jira->getIssue($taskKey);
        $summary = (string) ($task['fields']['summary'] ?? '');
        $isUserBug = ReportType::fromSummary($summary) === ReportType::UserBug;
        $detailText = ($task && isset($task['key'])) ? TaskResource::toDetail($task) : '';
        $header = ReportsMessageResource::stagedHeader($taskKey);
        $text = $detailText !== '' ? $detailText."\n".$header : $header;

        $this->sendInteractive(
            $chatId,
            $userId,
            $text,
            TaskKeyboardResource::stagedEditMenu(
                $taskKey,
                $labels,
                hasImpactField: $this->jira->customFieldId(config('services.jira.custom_fields.impact')) !== null,
                hasSeverityField: $this->jira->customFieldId(config('services.jira.custom_fields.severity')) !== null,
                hasOwnerField: true,
                hasCategoryField: $isUserBug && $this->hasCategoryField(),
            ),
            $messageThreadId,
            withBack: false,
        );
    }

    private function stageEdit(int $userId, string $taskKey, string $action, string $value, string $label): void
    {
        $context = $this->context($userId);
        $pending = ($context['pending_task'] ?? '') === $taskKey ? ($context['pending'] ?? []) : [];
        $pending[$action] = ['value' => $value, 'label' => $label];

        $this->updateContext($userId, ['pending_task' => $taskKey, 'pending' => $pending]);
    }

    private function stagedLabels(int $userId): array
    {
        $pending = $this->context($userId)['pending'] ?? [];

        return array_map(fn (array $edit): string => $edit['label'], $pending);
    }

    private function clearStagedEdits(int $userId): void
    {
        $this->updateContext($userId, ['pending_task' => null, 'pending' => null, 'desc_ui_user_info' => null]);
    }

    public function consumePendingText(int $userId, int $chatId, string $text, ?int $messageThreadId = null): bool
    {
        if ($this->conversation->isActive($userId)) {
            return false;
        }

        if ($this->consumeTimelineKeyPendingText($userId, $chatId, $text, $messageThreadId)) {
            return true;
        }

        if ($this->consumeDescUserInfoText($userId, $chatId, $text, $messageThreadId)) {
            return true;
        }

        if ($this->consumeDescUserInfoPendingText($userId, $chatId, $text, $messageThreadId)) {
            return true;
        }

        if ($this->consumeCommentPendingText($userId, $chatId, $text, $messageThreadId)) {
            return true;
        }

        $key = $this->descriptionPendingKey($userId);

        if (! Cache::has($key)) {
            return false;
        }

        $taskKey = (string) Cache::get($key, '');

        if ($taskKey === '') {
            Cache::forget($key);

            return false;
        }

        if (mb_strlen(trim($text)) < self::MIN_DESCRIPTION_LENGTH) {
            $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
            $this->messenger->sendMessage($chatId, __('reports.description_too_short'), $extra);

            return true;
        }

        Cache::forget($key);
        $this->stageEdit($userId, $taskKey, 'description', trim($text), mb_substr(trim($text), 0, self::STAGED_LABEL_TRUNCATE).'…');
        $this->renderStagedEditMenu($taskKey, $this->stagedLabels($userId), $chatId, $userId, $messageThreadId);

        return true;
    }

    private function consumeCommentPendingText(int $userId, int $chatId, string $text, ?int $messageThreadId = null): bool
    {
        $key = $this->commentPendingKey($userId);

        if (! Cache::has($key)) {
            return false;
        }

        $taskKey = (string) Cache::get($key, '');

        if ($taskKey === '') {
            Cache::forget($key);

            return false;
        }

        if (strtolower(trim($text)) === '/cancel') {
            Cache::forget($key);
            $this->returnToTask($taskKey, $chatId, $userId, $messageThreadId);

            return true;
        }

        $comment = trim($text);

        if (mb_strlen($comment) < self::MIN_COMMENT_LENGTH) {
            $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
            $this->messenger->sendMessage($chatId, __('reports.manage.comment_too_short'), $extra);

            return true;
        }

        Cache::forget($key);

        $this->stageEdit($userId, $taskKey, 'comment', $this->buildCommentBody($comment, $userId), mb_substr($comment, 0, self::STAGED_LABEL_TRUNCATE).'…');
        $this->renderStagedEditMenu($taskKey, $this->stagedLabels($userId), $chatId, $userId, $messageThreadId);

        return true;
    }

    /**
     * Comments posted with the bot's PAT credit the linked admin in a footer line. A user who signed
     * in with their own token is the real author in Jira, so no footer is added.
     */
    private function buildCommentBody(string $comment, int $userId): string
    {
        if (JiraUser::query()->where('telegram_admin_id', $userId)->first()?->hasPersonalToken()) {
            return $comment;
        }

        $author = $this->access->linkedJiraDisplayName($userId);

        if ($author === null) {
            return $comment;
        }

        return CommentFooter::append($comment, $author);
    }

    private function transitionNameById(string $taskKey, string $transitionId): ?string
    {
        foreach ($this->jira->getIssueTransitions($taskKey) as $transition) {
            if ((string) ($transition['id'] ?? '') === $transitionId) {
                return (string) ($transition['to']['name'] ?? $transition['name'] ?? null);
            }
        }

        return null;
    }

    private function hasCategoryField(): bool
    {
        return $this->jira->customFieldId((string) config('services.jira.custom_fields.category', 'Ticket Category')) !== null;
    }

    private function categoryOptionNameById(string $taskKey, string $optionId): ?string
    {
        $fieldId = $this->jira->customFieldId((string) config('services.jira.custom_fields.category', 'Ticket Category'));
        if ($fieldId === null) {
            return null;
        }

        $options = $this->jira->fieldAllowedOptions($taskKey, $fieldId);
        if (empty($options)) {
            $projectKey = strtoupper(explode('-', $taskKey)[0] ?: (config('services.jira.default_project') ?: 'TS'));
            $options = $this->jira->getCreateFieldAllowedOptions($projectKey, $fieldId, 'Bug');
        }

        foreach ($options as $opt) {
            if ((string) ($opt['id'] ?? '') === (string) $optionId) {
                return (string) ($opt['value'] ?? $optionId);
            }
        }

        return null;
    }

    private function ensureAdminAndLinked(int $chatId, int $userId, ?int $messageThreadId = null): bool
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        if (! $this->access->isBotAdmin($userId)) {
            $this->messenger->sendMessage($chatId, ReportsMessageResource::adminOnly(), $extra);

            return false;
        }

        if (! $this->access->isUserLinked($userId)) {
            $this->messenger->sendMessage($chatId, CommonMessageResource::notLinked(), $extra);

            return false;
        }

        return true;
    }

    /** @return array{project?: string, status_id?: ?int, status_name?: ?string, page?: int} */
    private function context(int $userId): array
    {
        return Cache::get(self::CONTEXT_CACHE_PREFIX."{$userId}", []) ?: [];
    }

    private function updateContext(int $userId, array $patch): void
    {
        Cache::put(self::CONTEXT_CACHE_PREFIX."{$userId}", array_merge($this->context($userId), $patch), now()->addMinutes(config('telegram.reports.context_ttl_minutes')));
    }

    /**
     * Sends a screen of the session on the reply keyboard, closed by a
     * "back" button (one level up, see handleCancel) and a "cancel" button
     * leaving the session; the first screen of a session has no "back".
     */
    private function sendInteractive(int $chatId, int $userId, string $text, array $markup, ?int $messageThreadId = null, bool $withBack = true): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $rows = $markup['inline_keyboard'] ?? $markup;
        $rows[] = self::navRow($withBack);

        $message = $this->messenger->sendMessage($chatId, $text, array_merge(
            $extra,
            $this->replyKeyboard->extra($rows, $userId, $chatId),
        ));
        $this->interactive->track($userId, (int) ($message?->getMessageId() ?? 0));
    }

    /** @return array<int, array<string, mixed>> */
    private static function navRow(bool $withBack): array
    {
        $cancel = ['text' => __('keyboard.button.cancel'), 'callback_data' => CallbackAction::RptExit->value];

        return $withBack
            ? [['text' => __('keyboard.button.back'), 'callback_data' => CallbackAction::RptCancel->value], $cancel]
            : [$cancel];
    }

    private function cleanupInteractive(int $chatId, int $userId): void
    {
        $this->interactive->deleteAll($chatId, $userId);
    }

    /** @param  array<int, array{id: string, name: string, category: string}>  $statuses */
    private function rememberStatusMap(int $userId, array $statuses): void
    {
        $map = [];

        foreach ($statuses as $status) {
            if (($status['id'] ?? '') !== '' && ($status['name'] ?? '') !== '') {
                $map[(string) $status['id']] = (string) $status['name'];
            }
        }

        Cache::put(self::STATUS_MAP_CACHE_PREFIX."{$userId}", $map, now()->addMinutes(config('telegram.reports.context_ttl_minutes')));
    }

    private function knownStatusName(int $userId, int|string $statusId): ?string
    {
        return $this->statusMap($userId)[(string) $statusId] ?? null;
    }

    /** @return array<string, string> */
    private function statusMap(int $userId): array
    {
        return Cache::get(self::STATUS_MAP_CACHE_PREFIX."{$userId}", []);
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function startUserBugDescriptionEdit(string $taskKey, array $issue, int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $this->updateContext($userId, [
            'desc_ui_task' => $taskKey,
            'desc_ui_data' => [],
            'desc_ui_staging_field' => '',
        ]);

        $this->renderUserInfoStaging($taskKey, $chatId, $userId, $messageThreadId);
    }

    private function renderUserInfoStaging(string $taskKey, int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $context = $this->context($userId);
        $data = $context['desc_ui_data'] ?? [];
        $prefix = CallbackAction::RptDescUserInfo->value;

        $rows = [];

        foreach (ReportsMessageResource::userFieldLabels() as $field => $label) {
            $value = trim((string) ($data[$field] ?? ''));
            $text = $value !== ''
                ? __('reports.user_info_staging.field_filled', ['label' => $label, 'value' => $value])
                : __('reports.user_info_staging.field_empty', ['label' => $label]);
            $rows[] = [['text' => $text, 'callback_data' => $prefix.$field.'_'.$taskKey]];
        }

        $rows[] = [['text' => __('reports.user_info_staging.confirm'), 'callback_data' => $prefix.'confirm_'.$taskKey]];

        $this->sendInteractive($chatId, $userId, ReportsMessageResource::userInfoReview($data), $rows, $messageThreadId);
    }

    private function handleDescUserInfoCallback(string $remainder, int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $lastUnderscore = strrpos($remainder, '_');
        $action = $lastUnderscore !== false ? substr($remainder, 0, $lastUnderscore) : $remainder;
        $taskKey = $lastUnderscore !== false ? substr($remainder, $lastUnderscore + 1) : '';

        if ($taskKey === '') {
            return;
        }

        $context = $this->context($userId);

        if (($context['desc_ui_task'] ?? '') !== $taskKey) {
            return;
        }

        if ($action === 'confirm') {
            $this->finishUserInfoStaging($taskKey, $chatId, $userId, $messageThreadId);

            return;
        }

        $validFields = ['user_name', 'user_identifier', 'user_mobile'];

        if (in_array($action, $validFields, true)) {
            $label = ReportsMessageResource::userFieldLabels()[$action] ?? $action;
            $this->updateContext($userId, ['desc_ui_staging_field' => $action]);
            $this->sendInteractive($chatId, $userId, __('reports.user_info_staging.prompt', ['label' => $label]), [], $messageThreadId);
        }
    }

    private function finishUserInfoStaging(string $taskKey, int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $context = $this->context($userId);
        $data = $context['desc_ui_data'] ?? [];
        $hasAny = collect(['user_name', 'user_identifier', 'user_mobile'])
            ->contains(fn (string $key): bool => trim((string) ($data[$key] ?? '')) !== '');

        if (! $hasAny) {
            $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
            $this->messenger->sendMessage($chatId, ReportsMessageResource::userInfoRequired(), $extra);
            $this->renderUserInfoStaging($taskKey, $chatId, $userId, $messageThreadId);

            return;
        }

        Cache::put(
            $this->descUserInfoPendingKey($userId),
            $taskKey,
            now()->addMinutes(config('telegram.reports.description_pending_ttl_minutes')),
        );

        $this->sendInteractive($chatId, $userId, ReportsMessageResource::enterReportDescription($taskKey), [], $messageThreadId);
    }

    private function consumeDescUserInfoText(int $userId, int $chatId, string $text, ?int $messageThreadId = null): bool
    {
        $context = $this->context($userId);
        $stagingField = (string) ($context['desc_ui_staging_field'] ?? '');

        $validFields = ['user_name', 'user_identifier', 'user_mobile'];

        if (! in_array($stagingField, $validFields, true)) {
            return false;
        }

        $this->updateContext($userId, [
            'desc_ui_data' => array_merge($context['desc_ui_data'] ?? [], [$stagingField => trim($text)]),
            'desc_ui_staging_field' => '',
        ]);

        $taskKey = (string) ($context['desc_ui_task'] ?? '');
        $this->renderUserInfoStaging($taskKey, $chatId, $userId, $messageThreadId);

        return true;
    }

    private function consumeDescUserInfoPendingText(int $userId, int $chatId, string $text, ?int $messageThreadId = null): bool
    {
        $key = $this->descUserInfoPendingKey($userId);

        if (! Cache::has($key)) {
            return false;
        }

        $taskKey = (string) Cache::get($key, '');

        if ($taskKey === '') {
            Cache::forget($key);

            return false;
        }

        if (mb_strlen(trim($text)) < self::MIN_DESCRIPTION_LENGTH) {
            $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
            $this->messenger->sendMessage($chatId, __('reports.description_too_short'), $extra);

            return true;
        }

        Cache::forget($key);
        $context = $this->context($userId);
        $data = $context['desc_ui_data'] ?? [];
        $description = trim($text);

        $this->updateContext($userId, ['desc_ui_task' => null, 'desc_ui_data' => null, 'desc_ui_staging_field' => null, 'desc_ui_user_info' => $data]);

        $builtDescription = $this->buildReportDescription($description, $data);

        $this->stageEdit($userId, $taskKey, 'description', $builtDescription, mb_substr($description, 0, self::STAGED_LABEL_TRUNCATE).'…');
        $this->renderStagedEditMenu($taskKey, $this->stagedLabels($userId), $chatId, $userId, $messageThreadId);

        return true;
    }

    private function buildReportDescription(string $description, array $data): string
    {
        $body = 'h3. '.__('reports.jira.description_heading')."\n{$description}";

        $fields = [
            __('reports.jira.user_name_label') => $data['user_name'] ?? '',
            __('reports.jira.user_identifier_label') => $data['user_identifier'] ?? '',
            __('reports.jira.user_mobile_label') => $data['user_mobile'] ?? '',
        ];

        $userInfo = collect($fields)
            ->filter(fn ($value) => trim((string) $value) !== '')
            ->map(fn ($value, $label): string => '* '.$label.': '.self::ltrValue((string) $value))
            ->implode("\n");

        if ($userInfo !== '') {
            $body .= "\n\nh3. ".__('reports.jira.user_info_heading')."\n".$userInfo;
        }

        return $body;
    }

    private function buildReportSummary(ReportType $type, array $data): string
    {
        $summary = $type->jiraLabel();

        $userParts = [];

        foreach (['user_name', 'user_identifier', 'user_mobile'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));

            if ($value !== '') {
                $userParts[] = $value;
            }
        }

        if ($userParts !== []) {
            $summary .= ': '.implode(', ', $userParts);
        }

        return $summary;
    }

    private static function ltrValue(string $value): string
    {
        return preg_match('/[\x{0600}-\x{06FF}]/u', $value) === 1 ? $value : '"'.$value.'"';
    }

    private function handleTimelineOption(string $option, int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        if ($option === 'status') {
            $this->startBrowsing(self::supportProject(), $chatId, $userId, $messageThreadId, mode: 'timeline');

            return;
        }

        if ($option === 'key') {
            $this->cleanupInteractive($chatId, $userId);
            Cache::put(
                $this->timelineKeyPendingKey($userId),
                true,
                now()->addMinutes((int) config('telegram.reports.description_pending_ttl_minutes')),
            );

            $this->sendInteractive($chatId, $userId, __('timeline.menu.enter_key_prompt'), [], $messageThreadId);

            return;
        }

        Log::warning('Unknown timeline option', ['option' => $option]);
    }

    private function consumeTimelineKeyPendingText(int $userId, int $chatId, string $text, ?int $messageThreadId = null): bool
    {
        $key = $this->timelineKeyPendingKey($userId);

        if (! Cache::has($key)) {
            return false;
        }

        Cache::forget($key);

        if (strtolower(trim($text)) === '/cancel') {
            $this->exitSession($chatId, $userId, $messageThreadId);

            return true;
        }

        $this->cleanupInteractive($chatId, $userId);
        $this->resetContext($userId);

        $normalized = strtr(trim($text), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        $this->jiraTasks()->sendTimelineForCommand($chatId, $normalized, $messageThreadId, $this->replyKeyboard->mainMenu($userId, $chatId));

        return true;
    }

    private function returnToTask(string $taskKey, int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $this->cleanupInteractive($chatId, $userId);
        Cache::forget($this->descriptionPendingKey($userId));
        Cache::forget($this->descUserInfoPendingKey($userId));
        Cache::forget($this->commentPendingKey($userId));

        $this->updateContext($userId, [
            'editing_task' => null,
            'editing_field' => null,
            'desc_ui_task' => null,
            'desc_ui_data' => null,
            'desc_ui_staging_field' => null,
            'current_task' => $taskKey,
        ]);

        $context = $this->context($userId);
        $pending = ($context['pending_task'] ?? '') === $taskKey ? ($context['pending'] ?? []) : [];

        if (! empty($pending)) {
            $this->renderStagedEditMenu($taskKey, $this->stagedLabels($userId), $chatId, $userId, $messageThreadId);

            return;
        }

        $this->jiraTasks()->sendTaskDetail($chatId, $taskKey, $userId, manage: true, messageThreadId: $messageThreadId);
    }

    private function cancelTaskView(int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $this->cleanupInteractive($chatId, $userId);
        $this->clearStagedEdits($userId);
        Cache::forget($this->descriptionPendingKey($userId));
        Cache::forget($this->descUserInfoPendingKey($userId));
        Cache::forget($this->commentPendingKey($userId));

        $context = $this->context($userId);

        $this->updateContext($userId, [
            'current_task' => null,
            'editing_task' => null,
            'editing_field' => null,
            'desc_ui_task' => null,
            'desc_ui_data' => null,
            'desc_ui_staging_field' => null,
            'pending_task' => null,
            'pending' => null,
        ]);

        if (! empty($context['status_id']) || ! empty($context['filter_all_open'])) {
            $this->renderOpenList($chatId, $userId, $messageThreadId);

            return;
        }

        $this->renderStatusMenu($chatId, $userId, $messageThreadId);
    }

    private function handleCancel(int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $context = $this->context($userId);

        if (Cache::has($this->timelineKeyPendingKey($userId))) {
            Cache::forget($this->timelineKeyPendingKey($userId));
            $this->renderTimelineMenu($chatId, $userId, $messageThreadId);

            return;
        }

        if (! empty($context['desc_ui_staging_field']) && ! empty($context['desc_ui_task'])) {
            $taskKey = (string) $context['desc_ui_task'];
            $this->updateContext($userId, ['desc_ui_staging_field' => '']);
            $this->renderUserInfoStaging($taskKey, $chatId, $userId, $messageThreadId);

            return;
        }

        $editingTask = $context['editing_task']
            ?? $context['desc_ui_task']
            ?? (Cache::get($this->descriptionPendingKey($userId)) ?: null)
            ?? (Cache::get($this->descUserInfoPendingKey($userId)) ?: null)
            ?? (Cache::get($this->commentPendingKey($userId)) ?: null);

        if ($editingTask !== null && $editingTask !== '') {
            $this->returnToTask((string) $editingTask, $chatId, $userId, $messageThreadId);

            return;
        }

        if (! empty($context['current_task'])) {
            $this->cancelTaskView($chatId, $userId, $messageThreadId);

            return;
        }

        if (! empty($context['status_id']) || ! empty($context['filter_all_open'])) {
            $this->cleanupInteractive($chatId, $userId);
            $this->updateContext($userId, [
                'status_id' => null,
                'status_name' => null,
                'filter_all_open' => null,
                'page' => 1,
            ]);
            $this->renderStatusMenu($chatId, $userId, $messageThreadId);

            return;
        }

        if (($context['mode'] ?? '') === 'timeline' && ! empty($context['project'])) {
            $this->renderTimelineMenu($chatId, $userId, $messageThreadId);

            return;
        }

        $this->exitSession($chatId, $userId, $messageThreadId);
    }

    /** Leaves the session from any screen and brings back the main menu. */
    private function exitSession(int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        $this->reset($chatId, $userId);

        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $this->messenger->sendMessage($chatId, FlowMessageResource::flowCancelled(), array_merge(
            $extra,
            $this->replyKeyboard->mainMenu($userId, $chatId),
        ));
    }

    /**
     * Drops every trace of a manage-tasks / timeline session — its screens,
     * pending text prompts and context — without notifying the user.
     */
    public function reset(int $chatId, int $userId): void
    {
        $this->cleanupInteractive($chatId, $userId);
        Cache::forget($this->timelineKeyPendingKey($userId));
        Cache::forget($this->descriptionPendingKey($userId));
        Cache::forget($this->descUserInfoPendingKey($userId));
        Cache::forget($this->commentPendingKey($userId));
        $this->resetContext($userId);
    }

    private function resetContext(int $userId): void
    {
        $this->updateContext($userId, [
            'mode' => null,
            'project' => null,
            'status_id' => null,
            'status_name' => null,
            'filter_all_open' => null,
            'page' => 1,
            'pending_task' => null,
            'pending' => null,
            'current_task' => null,
            'editing_task' => null,
            'editing_field' => null,
            'desc_ui_task' => null,
            'desc_ui_data' => null,
            'desc_ui_staging_field' => null,
            'desc_ui_user_info' => null,
        ]);
    }

    private function timelineKeyPendingKey(int $userId): string
    {
        return self::TIMELINE_KEY_PENDING_PREFIX."{$userId}";
    }

    private function descriptionPendingKey(int $userId): string
    {
        return self::DESCRIPTION_PENDING_PREFIX."{$userId}";
    }

    private function descUserInfoPendingKey(int $userId): string
    {
        return self::DESC_USERINFO_PENDING_PREFIX."{$userId}";
    }

    private function commentPendingKey(int $userId): string
    {
        return self::COMMENT_PENDING_PREFIX."{$userId}";
    }

    private function jiraTasks(): TaskService
    {
        return app(TaskService::class);
    }

    /** The Jira project the support board lives in (JIRA_DEFAULT_PROJECT, TS when unset). */
    private static function supportProject(): string
    {
        return strtoupper((string) (config('services.jira.default_project') ?: 'TS'));
    }
}
