<?php

namespace App\Services\Telegram;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Enums\CallbackAction;
use App\Enums\FlowCommand;
use App\Enums\ReportType;
use App\Enums\TaskPriority;
use App\Resources\FlowMessageResource;
use App\Resources\PickerResource;
use App\Resources\ReportsMessageResource;
use App\Services\AccountLinkService;
use App\Services\Jira\TaskService;
use App\Services\Telegram\Reports\ReportFlowService;
use App\Support\CallbackData;
use App\Support\TelegramChat;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Objects\CallbackQuery;

class FlowService
{
    private const INFO_CALLBACKS = [
        'start',
        'help',
        // About / Projects / Boards removed — uncomment to restore.
        // 'about',
        // 'projects',
        // Users listing disabled for now.
        // 'boards',
    ];

    private const FLOW_START_COMMANDS = [
        FlowCommand::Link->value => FlowCommand::Link,
        // Sprints disabled — uncomment to restore.
        // FlowCommand::Sprints->value => FlowCommand::Sprints,
        // Tasks flow disabled — uncomment to restore.
        // FlowCommand::Tasks->value => FlowCommand::Tasks,
        // Task details disabled — uncomment to restore.
        // 'task' => FlowCommand::TaskDetail,
        // Assign disabled — uncomment to restore.
        // FlowCommand::Assign->value => FlowCommand::Assign,
        // Unassign disabled — uncomment to restore.
        // FlowCommand::Unassign->value => FlowCommand::Unassign,
        // Create task disabled — uncomment to restore.
        // FlowCommand::Create->value => FlowCommand::Create,
        // Change status disabled — uncomment to restore.
        // FlowCommand::Status->value => FlowCommand::Status,
        // Push to sprint disabled — uncomment to restore.
        // FlowCommand::Push->value => FlowCommand::Push,
    ];

    private const USER_INFO_FIELDS = ['user_name', 'user_identifier', 'user_mobile'];

    public function __construct(
        private ConversationManager $conversation,
        private TelegramMessengerInterface $messenger,
        private AccessService $access,
        private JiraServiceInterface $jira,
        private TaskService $tasks,
        private BotInfoService $info,
        private AccountLinkService $accountLink,
        private ReportFlowService $reports,
        private FlowPickerService $pickers,
        private InteractiveMessages $interactive,
        private TopicAccessService $topicAccess,
        private ReplyKeyboardService $replyKeyboard,
    ) {}

    public function handleFlowCallback(CallbackQuery $callbackQuery, int $telegramUserId, ?int $messageThreadId = null): void
    {
        $data = $callbackQuery->getData() ?? '';
        $chatId = (int) ($callbackQuery->getMessage()?->get('chat')?->get('id') ?? 0);
        $messageId = (int) ($callbackQuery->getMessage()?->get('message_id') ?? 0);

        if ($chatId === 0) {
            return;
        }

        $this->messenger->answerCallback($callbackQuery);
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        if ($data === CallbackAction::FlowCancel->value) {
            $this->conversation->deleteTrackedMessages($chatId, $telegramUserId);
            $this->conversation->clear($telegramUserId);
            $this->interactive->deleteAll($chatId, $telegramUserId);
            $this->messenger->sendMessage($chatId, FlowMessageResource::flowCancelled(), array_merge(
                $extra,
                $this->replyKeyboard->mainMenu($telegramUserId, $chatId),
            ));

            return;
        }

        if ($data === CallbackAction::FlowBack->value && $this->conversation->isActive($telegramUserId)) {
            $this->stepBack($telegramUserId, $chatId, $messageThreadId);

            return;
        }

        $payload = CallbackData::strip($data, CallbackAction::Flow);

        if (in_array($payload, self::INFO_CALLBACKS, true)) {
            $this->handleInfoCallback($payload, $chatId, $callbackQuery, $telegramUserId, $messageThreadId);

            return;
        }

        $command = self::FLOW_START_COMMANDS[$payload] ?? null;
        if ($command !== null) {
            $this->startFlow($telegramUserId, $chatId, $messageId, $command, messageThreadId: $messageThreadId);

            return;
        }

        $state = $this->conversation->get($telegramUserId);
        if ($state === null) {
            $this->notifyIfStaleFlowSelection($payload, $chatId, $telegramUserId, $messageThreadId);

            return;
        }

        $picked = CallbackData::flowPickProjectParts($data);
        if ($picked !== null) {
            $command = $picked[0];
            $this->conversation->setData($telegramUserId, ['project' => $picked[1]]);
            $freshState = $this->conversation->get($telegramUserId);
            $this->showFlowPicker($chatId, 'task', $command, $freshState['data'] ?? [], $telegramUserId, $messageThreadId);

            return;
        }

        $commandPrefix = CallbackData::flowPrefix((string) $state['command']);

        if (str_starts_with($data, $commandPrefix)) {
            $remainder = substr($data, strlen($commandPrefix));
            $flow = $this->conversation->getFlow((string) $state['command'], (bool) ($state['project_first'] ?? false));
            $split = $this->splitFlowSelection($flow ?? [], $remainder);

            if ($split !== null) {
                [$stepName, $value] = $split;

                if ($this->handlePrioritySelection($state, $telegramUserId, $chatId, $messageId, (string) $state['command'], $stepName, $value, $messageThreadId)) {
                    return;
                }

                if ($this->handleReportUserInfoReview($telegramUserId, $chatId, (string) $state['command'], $stepName, $value, $messageThreadId)) {
                    return;
                }

                $this->handleFlowSelection($telegramUserId, $chatId, (string) $state['command'], $stepName, $value, messageThreadId: $messageThreadId);
            }
        }
    }

    private function notifyIfStaleFlowSelection(string $payload, int $chatId, int $userId, ?int $messageThreadId = null): void
    {
        if ($this->conversation->wasRecentlyConsumed($userId)) {
            Log::info('Duplicate flow callback after execution ignored', ['flow' => $payload]);

            return;
        }

        $command = collect(FlowCommand::cases())
            ->first(fn (FlowCommand $case): bool => str_starts_with($payload, $case->value.'_'));

        if ($payload === 'back' || $command !== null) {
            Log::info('Stale flow button clicked without an active conversation', ['flow' => $command?->value ?? $payload]);
            $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
            $this->messenger->sendMessage($chatId, FlowMessageResource::expired(), array_merge(
                $extra,
                $this->replyKeyboard->mainMenu($userId, $chatId),
            ));
        }
    }

    /** @param  array<string, mixed>  $state */
    private function handlePrioritySelection(
        array $state,
        int $userId,
        int $chatId,
        int $messageId,
        string $commandName,
        string $stepName,
        string $value,
        ?int $messageThreadId = null,
    ): bool {
        if ($stepName !== 'priority' || $messageId <= 0) {
            return false;
        }

        $flow = $this->conversation->getFlow($commandName, (bool) ($state['project_first'] ?? false));
        $currentStep = $flow[$state['step']] ?? null;

        if ($currentStep === null || ($currentStep['step'] ?? null) !== 'priority') {
            return false;
        }

        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        if ($value === 'confirm') {
            if (($state['data']['priority'] ?? null) === null) {
                $this->messenger->sendMessage($chatId, FlowMessageResource::flowInvalidInput(), $extra);

                return true;
            }

            $this->handleFlowSelection($userId, $chatId, $commandName, $stepName, $value, saveValue: false, messageThreadId: $messageThreadId);

            return true;
        }

        if ($value === 'skip') {
            $this->handleFlowSelection($userId, $chatId, $commandName, $stepName, $value, saveValue: false, messageThreadId: $messageThreadId);

            return true;
        }

        $priority = TaskPriority::tryFrom($value);

        if ($priority === null) {
            $this->messenger->sendMessage($chatId, FlowMessageResource::flowInvalidInput(), $extra);

            return true;
        }

        $this->conversation->setData($userId, ['priority' => $priority->value]);

        try {
            $this->pickers->renderPriorityPicker($chatId, $messageId, $commandName, $priority);
        } catch (\Throwable $e) {
            Log::warning('Failed to update priority picker: '.$e->getMessage());
        }

        return true;
    }

    private function handleInfoCallback(string $payload, int $chatId, CallbackQuery $callbackQuery, int $telegramUserId, ?int $messageThreadId = null): void
    {
        $chatType = (string) $callbackQuery->getMessage()?->get('chat')?->get('type', 'private');
        $isGroup = TelegramChat::isGroup($chatType);

        switch ($payload) {
            case 'start':
                $firstName = $callbackQuery->getFrom()?->getFirstName() ?? __('common.fallback.user_name');
                $this->info->start($chatId, $firstName, $isGroup, messageThreadId: $messageThreadId, userId: $telegramUserId);

                break;

            case 'help':
                $this->info->help($chatId, $isGroup, messageThreadId: $messageThreadId, userId: $telegramUserId);

                break;

                // About / Projects / Boards removed — uncomment to restore.
                // case 'about':
                //     $this->info->about($chatId, $isGroup, messageThreadId: $messageThreadId);
                //
                //     break;
                //
                // case 'projects':
                //     $this->info->sendProjects($chatId, $isGroup, messageThreadId: $messageThreadId);
                //
                //     break;

                // case 'users':
                //     $this->info->listUsers($chatId, messageThreadId: $messageThreadId);
                //
                //     break;

                // case 'boards':
                //     $this->info->sendBoards($chatId, $isGroup, messageThreadId: $messageThreadId);
                //
                //     break;
        }
    }

    /**
     * Handles callbacks of the report user-info review (staging) step:
     * "confirm" advances to the description once at least one field is set,
     * "edit_<field>" jumps back to that field to re-enter it.
     */
    private function handleReportUserInfoReview(int $userId, int $chatId, string $commandName, string $step, string $action, ?int $messageThreadId = null): bool
    {
        if ($step !== 'user_info_staging') {
            return false;
        }

        $state = $this->conversation->get($userId);

        if ($state === null) {
            return false;
        }

        $flow = $this->conversation->getFlow($commandName, (bool) ($state['project_first'] ?? false));
        $currentStepDef = $flow[$state['step']] ?? null;

        if (($currentStepDef['step'] ?? null) !== 'user_info_staging') {
            return false;
        }

        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        // A field button was clicked — prompt for its value without leaving
        // the staging step; the entered text is captured by handleFlowText and
        // the staging screen is re-shown once the value arrives.
        if (in_array($action, ['user_name', 'user_identifier', 'user_mobile'], true)) {
            $label = ReportsMessageResource::userFieldLabels()[$action] ?? $action;
            $this->conversation->setData($userId, ['_staging_field' => $action]);
            $this->messenger->sendTrackedMessage($chatId, $userId, __('reports.user_info_staging.prompt', ['label' => $label]), array_merge(
                $extra,
                $this->replyKeyboard->extra([PickerResource::flowNavRow()], $userId, $chatId),
            ));

            return true;
        }

        if ($action === 'confirm') {
            $data = $state['data'] ?? [];
            $hasAny = collect(['user_name', 'user_identifier', 'user_mobile'])
                ->contains(fn (string $key): bool => trim((string) ($data[$key] ?? '')) !== '');

            if (! $hasAny) {
                $this->messenger->sendTrackedMessage($chatId, $userId, ReportsMessageResource::userInfoRequired(), $extra);
                $freshData = ($this->conversation->get($userId))['data'] ?? [];
                $this->pickers->showFlowPicker($chatId, 'user_info_staging', $commandName, $freshData, $userId, $messageThreadId);

                return true;
            }

            $nextStepIndex = $state['step'] + 1;
            $this->conversation->setData($userId, ['_staging_field' => '']);
            $this->conversation->advance($userId, $nextStepIndex);
            $freshData = ($this->conversation->get($userId))['data'] ?? [];
            $nextStep = $this->nextVisibleStep($userId, $flow, $nextStepIndex, $freshData);

            if ($nextStep === null) {
                $this->executeFlowByName($userId, $chatId, $commandName, $messageThreadId);
            } else {
                $this->showFlowStep($userId, $chatId, $nextStep, $messageThreadId);
            }

            return true;
        }

        // Unknown action on the staging step — swallow to avoid advancing.
        return true;
    }

    /**
     * Splits a flow callback payload into the step name and the selected
     * value.  Step names such as "user_info_staging" contain underscores, so
     * a naive first-underscore split would mangle them; this instead matches
     * the longest known step-name prefix.
     *
     * @param  array<int, array<string, mixed>>  $flow
     * @return array{0: string, 1: string}|null
     */
    private function splitFlowSelection(array $flow, string $remainder): ?array
    {
        $steps = ['project'];

        foreach ($flow as $stepDef) {
            if (($stepDef['step'] ?? null) !== null) {
                $steps[] = $stepDef['step'];
            }
        }

        usort($steps, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($steps as $stepName) {
            $needle = $stepName.'_';

            if (str_starts_with($remainder, $needle)) {
                $value = substr($remainder, strlen($needle));

                if ($value !== '') {
                    return [$stepName, $value];
                }
            }
        }

        return null;
    }

    public function startFlow(int $userId, int $chatId, int $messageId, FlowCommand $command, array|\Closure $initialData = [], bool $isGroup = false, ?int $messageThreadId = null): void
    {
        $projectFirst = $this->resolveProjectFirst($userId, $command);

        if (! $this->conversation->startIfIdle($command, $userId, $chatId, $messageId, $isGroup, $projectFirst, $messageThreadId)) {
            $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
            $this->messenger->sendMessage($chatId, FlowMessageResource::alreadyWaiting(), $extra);

            return;
        }

        $this->bootFlow($userId, $chatId, $command, value($initialData), $projectFirst, $messageThreadId);
    }

    private function resolveProjectFirst(int $userId, FlowCommand $command): bool
    {
        return $this->access->isBotAdmin($userId)
            && in_array($command, FlowCommand::projectFirstCandidates(), true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $flow
     * @param  array<string, mixed>  $data
     */
    private function nextVisibleStep(int $userId, array $flow, int $candidateIndex, array $data): ?array
    {
        $index = $candidateIndex;

        while (($step = $flow[$index] ?? null) !== null) {
            if (($step['type'] ?? '') === 'execute') {
                return null;
            }

            if ($this->stepConditionsMet($step, $data)) {
                if ($index !== $candidateIndex) {
                    $this->conversation->advance($userId, $index);
                }

                return $step;
            }

            $index++;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $data
     */
    private function stepConditionsMet(array $step, array $data): bool
    {
        $when = $step['when'] ?? null;

        if ($when === null) {
            return true;
        }

        if (isset($when[0]) && is_array($when[0])) {
            foreach ($when as $condition) {
                if (($data[$condition['step']] ?? null) !== $condition['equals']) {
                    return false;
                }
            }

            return true;
        }

        return ($data[$when['step']] ?? null) === $when['equals'];
    }

    private function bootFlow(int $userId, int $chatId, FlowCommand $command, array $initialData, bool $projectFirst, ?int $messageThreadId = null): void
    {
        $flow = $this->conversation->getFlow($command->value, $projectFirst);

        if ($flow === null) {
            $this->conversation->clear($userId);

            return;
        }

        if ($command === FlowCommand::Report) {
            $this->conversation->setData($userId, ['project' => strtoupper((string) (config('services.jira.default_project') ?: 'TS'))]);
        }

        $stepIndex = 0;
        $consumedKeys = [];

        foreach ($initialData as $stepName => $value) {
            $stepDef = $flow[$stepIndex] ?? null;

            if ($stepDef === null
                || $stepDef['type'] === 'execute'
                || ($stepDef['step'] ?? null) !== $stepName
                || $value === null
                || $value === ''
                || $value === 0) {
                break;
            }

            $this->conversation->advance($userId, $stepIndex + 1, [$stepName => $value]);
            $consumedKeys[] = $stepName;
            $stepIndex++;
        }

        $leftover = array_diff_key($initialData, array_flip($consumedKeys));

        if ($leftover !== []) {
            $this->conversation->setData($userId, $leftover);
        }

        $data = ($this->conversation->get($userId))['data'] ?? [];
        $nextStep = $this->nextVisibleStep($userId, $flow, $stepIndex, $data);

        if ($nextStep === null) {
            $this->executeFlow($userId, $chatId, $command, $messageThreadId);
        } else {
            $this->showFlowStep($userId, $chatId, $nextStep, $messageThreadId);
        }
    }

    public function handleFlowText(int $telegramUserId, int $chatId, int $messageId, string $text, ?int $messageThreadId = null): void
    {
        $state = $this->conversation->get($telegramUserId);

        if ($state === null) {
            Log::info('Flow text input ignored: no active state', ['user_id' => $telegramUserId]);

            return;
        }

        $command = (string) $state['command'];
        $flow = $this->conversation->getFlow($command, (bool) ($state['project_first'] ?? false));
        $currentStep = $flow[$state['step']] ?? null;

        if ($currentStep === null) {
            Log::warning('Flow text input: current step not found', ['user_id' => $telegramUserId, 'step' => $state['step'], 'command' => $command]);
            $this->conversation->clear($telegramUserId);

            return;
        }

        Log::info('Flow text input processing', [
            'user_id' => $telegramUserId,
            'command' => $command,
            'step' => $state['step'],
            'step_type' => $currentStep['type'] ?? '?',
        ]);

        if ($this->handleStagingFieldText($telegramUserId, $chatId, $command, $text, $messageThreadId)) {
            return;
        }

        if (in_array($currentStep['type'], ['text', 'button_or_text'], true)) {
            $this->handleFlowTextInput($telegramUserId, $chatId, $command, $currentStep['step'], $text, $messageThreadId);
        }
    }

    /**
     * Captures a user-info field value typed while the report is on the
     * user-info staging screen, then re-shows the staging screen.  Returns
     * true only when a field value was actually in progress.
     */
    private function handleStagingFieldText(int $userId, int $chatId, string $commandName, string $text, ?int $messageThreadId = null): bool
    {
        $state = $this->conversation->get($userId);

        if ($state === null) {
            return false;
        }

        $stagingField = (string) ($state['data']['_staging_field'] ?? '');

        if (! in_array($stagingField, ['user_name', 'user_identifier', 'user_mobile'], true)) {
            return false;
        }

        $this->conversation->setData($userId, [$stagingField => $text, '_staging_field' => '']);
        $freshState = $this->conversation->get($userId);
        $freshData = $freshState['data'] ?? [];
        $this->pickers->showFlowPicker($chatId, 'user_info_staging', $commandName, $freshData, $userId, $messageThreadId);

        return true;
    }

    private function handleFlowSelection(int $userId, int $chatId, string $commandName, string $step, string $value, bool $saveValue = true, ?int $messageThreadId = null): void
    {
        $state = $this->conversation->get($userId);

        if ($state === null) {
            return;
        }

        $flow = $this->conversation->getFlow($commandName, (bool) ($state['project_first'] ?? false));

        if ($flow === null) {
            return;
        }

        $currentStepDef = $flow[$state['step']] ?? null;

        if ($currentStepDef === null) {
            return;
        }

        // While awaiting a user-info field value, tapping "skip" abandons the
        // input and returns to the staging screen instead of advancing.
        if (in_array((string) ($state['data']['_staging_field'] ?? ''), ['user_name', 'user_identifier', 'user_mobile'], true)) {
            $this->conversation->setData($userId, ['_staging_field' => '']);
            $freshData = ($this->conversation->get($userId))['data'] ?? [];
            $this->pickers->showFlowPicker($chatId, 'user_info_staging', $commandName, $freshData, $userId, $messageThreadId);

            return;
        }

        $saveValue = $saveValue && $value !== 'skip';

        $this->conversation->advance($userId, $state['step'] + 1, $saveValue ? [$step => $value] : []);

        $freshData = ($this->conversation->get($userId))['data'] ?? [];
        $nextStep = $this->nextVisibleStep($userId, $flow, $state['step'] + 1, $freshData);

        if ($nextStep === null) {
            $this->executeFlowByName($userId, $chatId, $commandName, $messageThreadId);

            return;
        }

        $this->showFlowStep($userId, $chatId, $nextStep, $messageThreadId);
    }

    private function handleFlowTextInput(int $userId, int $chatId, string $commandName, string $stepName, string $text, ?int $messageThreadId = null): void
    {
        $state = $this->conversation->get($userId);

        if ($state === null) {
            Log::info('Flow text input: state vanished during processing', ['user_id' => $userId]);

            return;
        }

        $flow = $this->conversation->getFlow($commandName, (bool) ($state['project_first'] ?? false));
        $currentStep = $flow[$state['step']] ?? null;

        if ($currentStep === null) {
            Log::warning('Flow text input: step disappeared', ['user_id' => $userId, 'step' => $state['step']]);

            return;
        }

        $minLength = (int) ($currentStep['min'] ?? 0);

        if ($minLength > 0 && mb_strlen(trim($text)) < $minLength) {
            $hintKey = $currentStep['short_hint'] ?? null;
            $message = $hintKey !== null ? __($hintKey) : FlowMessageResource::flowInvalidInput();
            $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

            Log::info('Flow text input: too short', ['user_id' => $userId, 'min' => $minLength, 'got' => mb_strlen(trim($text))]);
            $this->messenger->sendTrackedMessage($chatId, $userId, $message, $extra);

            return;
        }

        $nextStepIndex = $state['step'] + 1;

        $this->conversation->advance($userId, $nextStepIndex, [$stepName => $text]);

        $freshData = ($this->conversation->get($userId))['data'] ?? [];
        $nextStep = $this->nextVisibleStep($userId, $flow, $nextStepIndex, $freshData);

        Log::info('Flow text input: advanced', [
            'user_id' => $userId,
            'command' => $commandName,
            'step_name' => $stepName,
            'next_step_index' => $nextStepIndex,
            'has_next' => $nextStep !== null,
        ]);

        if ($nextStep === null) {
            $this->executeFlowByName($userId, $chatId, $commandName, $messageThreadId);

            return;
        }

        $this->showFlowStep($userId, $chatId, $nextStep, $messageThreadId);
    }

    private function showFlowStep(int $userId, int $chatId, array $step, ?int $messageThreadId = null): void
    {
        $state = $this->conversation->get($userId);
        if ($state === null) {
            return;
        }

        $command = (string) $state['command'];
        $picker = $step['picker'] ?? null;
        $prompt = $step['prompt'] ?? null;
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        if ($picker !== null) {
            $this->pickers->showFlowPicker($chatId, $picker, $command, $state['data'] ?? [], $userId, $messageThreadId);
        } elseif ($prompt !== null) {
            $text = $this->resolvePromptText($step, $state['data'] ?? []);
            $rows = [];

            if (($step['type'] ?? '') === 'button_or_text' && ($step['skipable'] ?? false)) {
                $skipCallback = CallbackData::flowStepPrefix((string) $state['command'], (string) $step['step']).'skip';
                $rows[] = [['text' => __('keyboard.button.skip'), 'callback_data' => $skipCallback]];
            }

            $rows[] = $this->hasPreviousStep($userId)
                ? PickerResource::flowNavRow()
                : [['text' => __('keyboard.button.cancel'), 'callback_data' => CallbackAction::FlowCancel->value]];

            $this->messenger->sendTrackedMessage($chatId, $userId, $text, array_merge(
                $extra,
                $this->replyKeyboard->extra($rows, $userId, $chatId),
            ));
        }
    }

    /**
     * Steps the conversation back to the previous visible step, dropping the
     * values collected from there on (the user-info fields stay when going
     * back to the staging screen, so they can be corrected rather than
     * retyped). From the first step, or with no step behind, it cancels.
     */
    private function stepBack(int $userId, int $chatId, ?int $messageThreadId = null): void
    {
        $state = $this->conversation->get($userId);

        if ($state === null) {
            return;
        }

        $command = (string) $state['command'];
        $flow = $this->conversation->getFlow($command, (bool) ($state['project_first'] ?? false)) ?? [];
        $data = $state['data'] ?? [];

        // Leaving a user-info field prompt returns to the staging screen.
        if (in_array((string) ($data['_staging_field'] ?? ''), self::USER_INFO_FIELDS, true)) {
            $this->conversation->setData($userId, ['_staging_field' => '']);
            $freshData = ($this->conversation->get($userId))['data'] ?? [];
            $this->pickers->showFlowPicker($chatId, 'user_info_staging', $command, $freshData, $userId, $messageThreadId);

            return;
        }

        $previous = $this->previousVisibleStepIndex($flow, (int) $state['step'], $data);

        if ($previous === null) {
            $this->conversation->deleteTrackedMessages($chatId, $userId);
            $this->conversation->clear($userId);
            $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
            $this->messenger->sendMessage($chatId, FlowMessageResource::flowCancelled(), array_merge(
                $extra,
                $this->replyKeyboard->mainMenu($userId, $chatId),
            ));

            return;
        }

        $dropped = [];

        foreach (array_slice($flow, $previous + 1) as $later) {
            $name = (string) ($later['step'] ?? '');

            if ($name === 'user_info_staging') {
                array_push($dropped, ...self::USER_INFO_FIELDS);
            } elseif ($name !== '') {
                $dropped[] = $name;
            }
        }

        $this->conversation->forgetData($userId, $dropped);
        $this->conversation->setStep($userId, $previous, ['_staging_field' => '']);
        $this->conversation->deleteTrackedMessages($chatId, $userId);
        $this->showFlowStep($userId, $chatId, $flow[$previous], $messageThreadId);
    }

    /**
     * @param  array<int, array<string, mixed>>  $flow
     * @param  array<string, mixed>  $data
     */
    private function previousVisibleStepIndex(array $flow, int $current, array $data): ?int
    {
        for ($index = $current - 1; $index >= 0; $index--) {
            $step = $flow[$index] ?? null;

            if ($step !== null && ($step['type'] ?? '') !== 'execute' && $this->stepConditionsMet($step, $data)) {
                return $index;
            }
        }

        return null;
    }

    private function hasPreviousStep(int $userId): bool
    {
        $state = $this->conversation->get($userId);

        if ($state === null) {
            return false;
        }

        $flow = $this->conversation->getFlow((string) $state['command'], (bool) ($state['project_first'] ?? false)) ?? [];

        return $this->previousVisibleStepIndex($flow, (int) $state['step'], $state['data'] ?? []) !== null;
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $data
     */
    private function resolvePromptText(array $step, array $data): string
    {
        $text = call_user_func([FlowMessageResource::class, (string) $step['prompt']]);

        if (($step['step'] ?? '') === 'description' && ($data['type'] ?? '') === ReportType::ForceMajeure->value) {
            $text = ReportsMessageResource::forceMajeureWarning()."\n\n".$text;
        }

        return $text;
    }

    private function executeFlowByName(int $userId, int $chatId, string $commandName, ?int $messageThreadId = null): void
    {
        $command = FlowCommand::tryFrom($commandName);

        if ($command === null) {
            $state = $this->conversation->get($userId);

            if ($state !== null) {
                $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
                $this->conversation->deleteTrackedMessages($chatId, $userId);
                $this->messenger->sendMessage($chatId, FlowMessageResource::flowInvalidInput(), $extra);
                $this->conversation->clear($userId);
            }

            return;
        }

        $this->executeFlow($userId, $chatId, $command, $messageThreadId);
    }

    private function executeFlow(int $userId, int $chatId, FlowCommand $command, ?int $messageThreadId = null): void
    {
        $state = $this->conversation->consume($userId);

        if ($state === null) {
            Log::info('Flow execution skipped: state already consumed or expired', ['flow' => $command->value]);

            return;
        }

        $data = $state->data;
        $isGroup = $state->isGroup;
        $threadId = $messageThreadId ?? $state->messageThreadId;

        $this->conversation->deleteTrackedMessages($chatId, $userId, $state);

        switch ($command) {
            case FlowCommand::Tasks:
                $projectKey = $data['project'] ?? '';
                if ($projectKey !== '') {
                    $this->tasks->sendTasksForProject($chatId, $projectKey, messageThreadId: $threadId);
                } else {
                    $this->invalidFlowData($chatId, $command, $threadId);
                }

                break;

            case FlowCommand::TaskDetail:
                $projectKey = $data['project'] ?? '';
                if ($projectKey !== '') {
                    $this->tasks->sendTasksForDetail($chatId, $projectKey, 1, null, $userId, $isGroup, $threadId);
                } else {
                    $this->invalidFlowData($chatId, $command, $threadId);
                }

                break;

            case FlowCommand::Sprints:
                $boardId = (int) ($data['board'] ?? 0);
                if ($boardId > 0) {
                    $this->pickers->sendSprintsFromFlow($chatId, $boardId, $threadId);
                } else {
                    $this->invalidFlowData($chatId, $command, $threadId);
                }

                break;

            case FlowCommand::Link:
                $email = $data['email'] ?? '';
                if ($email !== '') {
                    $this->accountLink->linkAccount($chatId, $email, $userId, $isGroup, username: $data['username'] ?? '', messageThreadId: $threadId);
                } else {
                    $this->invalidFlowData($chatId, $command, $threadId);
                }

                break;

            case FlowCommand::Create:
                $projectKey = $data['project'] ?? '';
                $summary = $data['summary'] ?? '';
                $description = trim((string) ($data['description'] ?? ''));
                if ($projectKey !== '' && $summary !== '') {
                    $priority = isset($data['priority']) ? TaskPriority::tryFrom($data['priority']) : null;
                    $this->tasks->createTaskFromFlow(
                        $chatId,
                        $projectKey,
                        $summary,
                        $userId,
                        $priority,
                        $description !== '' ? $description : null,
                        $threadId,
                    );
                } else {
                    $this->invalidFlowData($chatId, $command, $threadId);
                }

                break;

            case FlowCommand::Assign:
                $taskKey = $data['task'] ?? '';
                $userName = $data['user'] ?? '';
                if ($taskKey !== '' && $userName !== '') {
                    $this->tasks->assignToTask($chatId, $taskKey, $userName, $threadId);
                } else {
                    $this->invalidFlowData($chatId, $command, $threadId);
                }

                break;

            case FlowCommand::Unassign:
                $taskKey = $data['task'] ?? '';
                if ($taskKey !== '') {
                    $this->tasks->unassignTaskFromFlow($chatId, $taskKey, $threadId);
                } else {
                    $this->invalidFlowData($chatId, $command, $threadId);
                }

                break;

            case FlowCommand::Status:
                $taskKey = $data['task'] ?? '';
                $transitionId = $data['status'] ?? '';
                if ($taskKey !== '' && $transitionId !== '') {
                    $this->tasks->changeStatus($chatId, $taskKey, $transitionId, $threadId);
                } else {
                    $this->invalidFlowData($chatId, $command, $threadId);
                }

                break;

            case FlowCommand::Push:
                $taskKey = $data['task'] ?? '';
                $sprintId = (int) ($data['sprint'] ?? 0);
                if ($taskKey !== '' && $sprintId > 0) {
                    $this->tasks->moveToSprint($chatId, $taskKey, $sprintId, $threadId);
                } else {
                    $this->invalidFlowData($chatId, $command, $threadId);
                }

                break;

            case FlowCommand::Report:
                $this->reports->finalizeReport($chatId, $data, $userId, $threadId);

                break;

            case FlowCommand::SetTopicName:
                $topicName = trim((string) ($data['topic_name'] ?? ''));
                if ($topicName !== '' && $threadId !== null) {
                    $this->topicAccess->updateTopicName($chatId, $threadId, $topicName);
                    $this->messenger->sendMessage($chatId, __('common.topic.name_set', ['name' => $topicName]), ['message_thread_id' => $threadId]);
                } else {
                    $this->invalidFlowData($chatId, $command, $threadId);
                }

                break;
        }
    }

    private function invalidFlowData(int $chatId, FlowCommand $command, ?int $messageThreadId = null): void
    {
        Log::warning('Flow executed with incomplete data', ['flow' => $command->value]);
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $this->messenger->sendMessage($chatId, FlowMessageResource::flowInvalidInput(), $extra);
    }
}
