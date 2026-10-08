<?php

namespace App\Services\Telegram;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Enums\FlowCommand;
use App\Enums\ReportType;
use App\Enums\TaskImpact;
use App\Enums\TaskPriority;
use App\Enums\TaskSeverity;
use App\Exceptions\JiraTimeoutException;
use App\Resources\CommonMessageResource;
use App\Resources\FlowMessageResource;
use App\Resources\PickerResource;
use App\Resources\ReportsMessageResource;
use App\Resources\SprintResource;
use App\Resources\TaskMessageResource;
use App\Services\Jira\JiraService;
use App\Services\Jira\TaskService;
use App\Support\CallbackData;
use App\Support\ReplyMarkup;
use Illuminate\Support\Facades\Log;

class FlowPickerService
{
    private const TASK_PICKER_LIMIT = 20;

    public function __construct(
        private TelegramMessengerInterface $messenger,
        private JiraServiceInterface $jira,
        private TaskService $tasks,
        private AccessService $access,
        private ConversationManager $conversation,
        private ReplyKeyboardService $replyKeyboard,
    ) {}

    public function showFlowPicker(int $chatId, string $picker, string $commandName = '', array $stateData = [], int $userId = 0, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        switch ($picker) {
            case 'project':
                $this->tasks->showProjectPicker($chatId, CallbackData::flowStepPrefix($commandName, 'project'), false, 0, $userId, false, $messageThreadId);

                break;

            case 'task':
                $this->sendFlowTaskPicker($chatId, $commandName, $userId, $this->resolveScopedProjectKey($stateData), $messageThreadId);

                break;

            case 'user':
                $this->sendFlowUserPicker($chatId, (string) ($stateData['task'] ?? ''), $commandName, $userId, $messageThreadId);

                break;

            case 'board':
                $this->sendFlowBoardPicker($chatId, $commandName, $userId, $messageThreadId);

                break;

            case 'sprint':
                $boardId = (int) ($stateData['board'] ?? 0);
                $this->sendFlowSprintPicker($chatId, $boardId, $commandName, $userId, $messageThreadId);

                break;

            case 'status':
                $taskKey = $stateData['task'] ?? '';
                $this->sendFlowStatusPicker($chatId, $taskKey, $commandName, $userId, (int) ($stateData['board'] ?? 0), $messageThreadId);

                break;

            case 'priority':
                $selected = TaskPriority::tryFrom($stateData['priority'] ?? '');
                $this->messenger->sendTrackedMessage($chatId, $userId, __('priority.select'), array_merge($extra, [
                    'reply_markup' => ReplyMarkup::toJson(
                        PickerResource::priorityPicker(
                            CallbackData::flowStepPrefix($commandName, 'priority'),
                            $selected,
                            $commandName === FlowCommand::Report->value,
                        ),
                    ),
                ]));

                break;

            case 'severity':
            case 'impact':
                $enumClass = $picker === 'impact' ? TaskImpact::class : TaskSeverity::class;
                $title = ReportsMessageResource::actionLabel($picker);
                $prefix = CallbackData::flowStepPrefix($commandName, $picker);

                $this->messenger->sendTrackedMessage($chatId, $userId, $title, array_merge($extra, [
                    'reply_markup' => ReplyMarkup::toJson(PickerResource::optionPicker($enumClass::values(), $prefix, withSkip: true, labels: $enumClass::options())),
                ]));

                break;

            case 'report_type':
                $this->messenger->sendTrackedMessage($chatId, $userId, __('reports.pick_type'), array_merge($extra, $this->replyKeyboard->extra(
                    PickerResource::reportTypePicker(CallbackData::flowStepPrefix($commandName, 'type')),
                    $userId,
                    $chatId,
                )));

                break;

            case 'user_info_staging':
                $this->sendUserInfoReview($chatId, $userId, $commandName, $stateData, $messageThreadId);

                break;

            case 'category':
                $this->sendCategoryPicker($chatId, $commandName, $userId, $stateData, $messageThreadId);

                break;

            case 'assignee':
                $projectKey = strtoupper((string) ($stateData['project'] ?? ''));

                if ($projectKey === '') {
                    break;
                }

                try {
                    $users = $this->jira->getAssignableUsersForProject($projectKey);

                    if (empty($users)) {
                        $this->messenger->sendMessage($chatId, CommonMessageResource::noUsers(), $extra);

                        break;
                    }

                    $prefix = CallbackData::flowStepPrefix($commandName, 'assignee');
                    $keyboard = PickerResource::userPicker($users, $prefix);
                    $keyboard['inline_keyboard'][] = [
                        ['text' => __('keyboard.button.skip'), 'callback_data' => $prefix.'skip'],
                    ];

                    $this->messenger->sendTrackedMessage($chatId, $userId, ReportsMessageResource::pickAssignee(), array_merge($extra, [
                        'reply_markup' => ReplyMarkup::toJson($keyboard),
                    ]));
                } catch (\Throwable $e) {
                    $this->handlePickerFailure($chatId, $userId, $e, 'Flow assignee picker error', FlowMessageResource::flowInvalidInput(), $messageThreadId);
                }

                break;

            default:
                $this->messenger->sendMessage($chatId, FlowMessageResource::flowInvalidInput(), $extra);
        }
    }

    public function renderPriorityPicker(int $chatId, int $messageId, string $commandName, ?TaskPriority $selected, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $markup = ReplyMarkup::toJson(
            PickerResource::priorityPicker(CallbackData::flowStepPrefix($commandName, 'priority'), $selected),
        );

        $this->messenger->editMessageText($chatId, $messageId, __('priority.select'), array_merge($extra, [
            'reply_markup' => $markup,
        ]));
    }

    public function sendSprintsFromFlow(int $chatId, int $boardId, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $sprints = $this->jira->getSprints($boardId);

        if (empty($sprints)) {
            $this->messenger->sendMessage($chatId, CommonMessageResource::noSprints(), $extra);

            return;
        }

        $this->messenger->sendMessage($chatId, CommonMessageResource::sprintsHeader()."\n".SprintResource::toListMultiple($sprints), $extra);
    }

    /**
     * Staging screen of the user-bug report: shows the collected user-info
     * fields. Each field opens a text prompt; once filled it is marked with a
     * tick on the menu. The admin confirms to proceed to the description.
     *
     * @param  array<string, mixed>  $stateData
     */
    private function sendUserInfoReview(int $chatId, int $userId, string $commandName, array $stateData, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $prefix = CallbackData::flowStepPrefix($commandName, 'user_info_staging');

        $rows = [];

        foreach (ReportsMessageResource::userFieldLabels() as $field => $label) {
            $value = trim((string) ($stateData[$field] ?? ''));
            $text = $value !== ''
                ? __('reports.user_info_staging.field_filled', ['label' => $label, 'value' => $value])
                : __('reports.user_info_staging.field_empty', ['label' => $label]);
            $rows[] = [['text' => $text, 'callback_data' => $prefix.$field]];
        }

        $rows[] = [['text' => __('reports.user_info_staging.confirm'), 'callback_data' => $prefix.'confirm']];
        $rows[] = PickerResource::flowNavRow();

        $this->messenger->sendTrackedMessage($chatId, $userId, ReportsMessageResource::userInfoReview($stateData), array_merge(
            $extra,
            $this->replyKeyboard->extra($rows, $userId, $chatId),
        ));
    }

    private function sendFlowTaskPicker(int $chatId, string $commandName = '', int $userId = 0, string $projectKey = '', ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        try {
            if ($projectKey === '') {
                if ($userId > 0 && $this->access->isBotAdmin($userId)) {
                    $this->tasks->showProjectPicker($chatId, CallbackData::flowPickProject($commandName), false, 0, $userId, false, $messageThreadId);

                    return;
                }

                $projectKey = '';
            }

            if ($projectKey === '') {
                $this->messenger->sendMessage($chatId, __('flow.select_project_first'), $extra);

                return;
            }

            if (! JiraService::isValidKeySegment($projectKey)) {
                $this->messenger->sendMessage($chatId, FlowMessageResource::flowInvalidInput(), $extra);

                return;
            }

            $jql = "project = \"{$projectKey}\"";
            if ($commandName === FlowCommand::Push->value) {
                $jql .= ' AND sprint IS EMPTY';
            }
            $jql .= ' ORDER BY key ASC';
            $result = $this->jira->getIssues($jql, self::TASK_PICKER_LIMIT);
            $tasks = $result['issues'];

            if (empty($tasks)) {
                $this->messenger->sendMessage($chatId, TaskMessageResource::noTasks(''), $extra);

                return;
            }

            $this->messenger->sendTrackedMessage($chatId, $userId, FlowMessageResource::flowPickTask(), array_merge($extra, [
                'reply_markup' => ReplyMarkup::toJson(PickerResource::taskPicker($tasks, CallbackData::flowStepPrefix($commandName, 'task'))),
            ]));
        } catch (\Throwable $e) {
            $this->handlePickerFailure($chatId, $userId, $e, 'Flow task picker error', TaskMessageResource::userPickerError(), $messageThreadId);
        }
    }

    private function sendFlowUserPicker(int $chatId, string $taskKey, string $commandName = '', int $userId = 0, ?int $messageThreadId = null): void
    {
        $this->sendFlowPicker(
            $chatId,
            $userId,
            FlowMessageResource::flowPickUser(),
            CommonMessageResource::noUsers(),
            'Flow user picker error',
            TaskMessageResource::userPickerError(),
            fn (): array => $this->jira->getAssignableUsers($taskKey),
            fn (array $users): array => PickerResource::userPicker($users, CallbackData::flowStepPrefix($commandName, 'user')),
            $messageThreadId,
        );
    }

    private function sendFlowBoardPicker(int $chatId, string $commandName = '', int $userId = 0, ?int $messageThreadId = null): void
    {
        $this->sendFlowPicker(
            $chatId,
            $userId,
            FlowMessageResource::flowPickBoard(),
            CommonMessageResource::noBoards(),
            'Flow board picker error',
            CommonMessageResource::noBoards(),
            fn (): array => $this->jira->getBoards(),
            fn (array $boards): array => PickerResource::boardPicker($boards, CallbackData::flowStepPrefix($commandName, 'board'), withCancel: true),
            $messageThreadId,
        );
    }

    private function sendFlowSprintPicker(int $chatId, int $boardId, string $commandName = '', int $userId = 0, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        if ($boardId <= 0) {
            $this->messenger->sendMessage($chatId, FlowMessageResource::flowPickBoard(), $extra);

            return;
        }

        $this->sendFlowPicker(
            $chatId,
            $userId,
            FlowMessageResource::flowPickSprint(),
            CommonMessageResource::noSprints(),
            'Flow sprint picker error',
            CommonMessageResource::noSprints(),
            fn (): array => $this->jira->getSprints($boardId),
            fn (array $sprints): array => PickerResource::sprintPicker($sprints, CallbackData::flowStepPrefix($commandName, 'sprint'), withCancel: true),
            $messageThreadId,
        );
    }

    private function sendFlowStatusPicker(int $chatId, string $taskKey, string $commandName = '', int $userId = 0, int $boardId = 0, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        if ($taskKey === '') {
            $this->messenger->sendMessage($chatId, FlowMessageResource::flowPickTask(), $extra);

            return;
        }

        $this->sendFlowPicker(
            $chatId,
            $userId,
            FlowMessageResource::flowPickStatus(),
            TaskMessageResource::noTransitions($taskKey),
            'Flow status picker error',
            TaskMessageResource::statusPickerError(),
            fn (): array => $this->tasks->reachableBoardTransitions($taskKey, $boardId),
            fn (array $transitions): array => PickerResource::statusPicker($transitions, CallbackData::flowStepPrefix($commandName, 'status')),
            $messageThreadId,
        );
    }

    private function resolveScopedProjectKey(array $stateData): string
    {
        $projectKey = strtoupper((string) ($stateData['project'] ?? ''));

        if ($projectKey !== '' || ! isset($stateData['board'])) {
            return $projectKey;
        }

        try {
            return strtoupper((string) ($this->jira->getBoardConfiguration((int) $stateData['board'])['project_key'] ?? ''));
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve board project: '.$e->getMessage());

            return '';
        }
    }

    /**
     * @param  callable(): array<int, array<string, mixed>>  $fetchOptions
     * @param  callable(array<int, array<string, mixed>>): array<string, mixed>  $buildKeyboard
     */
    private function sendFlowPicker(
        int $chatId,
        int $userId,
        string $text,
        string $emptyText,
        string $errorLogContext,
        string $errorText,
        callable $fetchOptions,
        callable $buildKeyboard,
        ?int $messageThreadId = null,
    ): void {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        try {
            $options = $fetchOptions();

            if (empty($options)) {
                $this->messenger->sendMessage($chatId, $emptyText, $extra);

                return;
            }

            $this->messenger->sendTrackedMessage($chatId, $userId, $text, array_merge($extra, [
                'reply_markup' => ReplyMarkup::toJson($buildKeyboard($options)),
            ]));
        } catch (\Throwable $e) {
            $this->handlePickerFailure($chatId, $userId, $e, $errorLogContext, $errorText, $messageThreadId);
        }
    }

    private function handlePickerFailure(int $chatId, int $userId, \Throwable $e, string $logContext, string $fallbackText, ?int $messageThreadId = null): void
    {
        Log::error($logContext.': '.$e->getMessage());

        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        if ($e instanceof JiraTimeoutException) {
            $this->conversation->deleteTrackedMessages($chatId, $userId);
            $this->conversation->clear($userId);
            $extra = array_merge($extra, $this->replyKeyboard->mainMenu($userId, $chatId));
        }

        $this->messenger->sendMessage($chatId, CommonMessageResource::forThrowable($e, $fallbackText), $extra);
    }

    /**
     * @param  array<string, mixed>  $stateData
     */
    private function sendCategoryPicker(int $chatId, string $commandName, int $userId, array $stateData, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $fieldName = (string) config('services.jira.custom_fields.category', 'Ticket Category');
        $fieldId = $this->jira->customFieldId($fieldName);

        $projectKey = strtoupper((string) (($stateData['project'] ?? null) ?: config('services.jira.default_project') ?: 'TS'));
        $issueType = ($stateData['type'] ?? '') === ReportType::UserBug->value ? ReportType::UserBug->issueType() : 'Bug';
        $options = $fieldId !== null ? $this->jira->getCreateFieldAllowedOptions($projectKey, $fieldId, $issueType) : [];

        $skipRow = [['text' => __('keyboard.button.skip'), 'callback_data' => CallbackData::flowStepPrefix($commandName, 'category').'skip']];

        if (empty($options)) {
            $this->messenger->sendTrackedMessage($chatId, $userId, __('reports.manage.no_category_options'), array_merge(
                $extra,
                $this->replyKeyboard->extra([$skipRow, PickerResource::flowNavRow()], $userId, $chatId),
            ));

            return;
        }

        $buttons = array_map(
            fn (array $opt): array => [
                'text' => (string) ($opt['value'] ?? ''),
                'callback_data' => CallbackData::flowStepPrefix($commandName, 'category').($opt['id'] ?? ''),
            ],
            $options
        );

        $rows = array_chunk($buttons, 2);
        $rows[] = $skipRow;
        $rows[] = PickerResource::flowNavRow();

        $this->messenger->sendTrackedMessage($chatId, $userId, __('reports.pick_category'), array_merge(
            $extra,
            $this->replyKeyboard->extra($rows, $userId, $chatId),
        ));
    }
}
