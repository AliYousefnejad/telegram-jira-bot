<?php

namespace App\Services\Telegram;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Enums\CallbackAction;
use App\Enums\FlowCommand;
use App\Models\JiraUser;
use App\Resources\CommonMessageResource;
use App\Resources\FlowMessageResource;
use App\Resources\ReportsMessageResource;
use App\Services\AccountLinkService;
use App\Services\Jira\TaskService;
use App\Support\CallbackData;
use App\Support\TelegramChat;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Update;
use Throwable;

class UpdateProcessor
{
    private const UNLINKED_INFO_CALLBACKS = ['start', 'help'/* , 'about' */];

    private const REPORTS_CALLBACK_ACTIONS = [
        CallbackAction::RptMenu,
        CallbackAction::RptProject,
        CallbackAction::RptFilter,
        CallbackAction::RptPage,
        CallbackAction::RptTask,
        CallbackAction::RptAct,
        CallbackAction::RptSet,
        CallbackAction::RptConfirm,
        CallbackAction::RptDiscard,
        CallbackAction::RptCancel,
        CallbackAction::RptCancelTask,
        CallbackAction::RptCancelSection,
        CallbackAction::RptDescUserInfo,
        CallbackAction::RptTimeline,
        CallbackAction::RptExit,
    ];

    /** Commands that manage topic restrictions — exempt from topic restriction checks. */
    private const ADMIN_TOPIC_COMMANDS = ['/allow_topic', '/disallow_topic', '/list_allowed_topics', '/set_topic_name'];

    public function __construct(
        private AccessService $access,
        private TelegramMessengerInterface $messenger,
        private ConversationManager $conversation,
        private FlowService $flow,
        private CallbackService $callbacks,
        private BotInfoService $info,
        private AccountLinkService $accountLink,
        private JiraServiceInterface $jira,
        private Reports\TaskManagementService $reportsTasks,
        private TopicAccessService $topicAccess,
        private ReplyKeyboardService $replyKeyboard,
    ) {}

    public function process(Update $update): void
    {
        try {
            if ($this->handleForumServiceMessage($update)) {
                return;
            }

            if ($this->isTopicRestricted($update)) {
                return;
            }

            if ($update->getCallbackQuery()) {
                $this->handleCallbackQuery($update);
            } elseif (! $update->getMessage()->isEmpty()) {
                $this->handleMessage($update);
            }
        } catch (Throwable $e) {
            Log::error('Unhandled update error: '.$e->getMessage(), [
                'update_id' => $update->getUpdateId(),
                'trace' => $e->getFile().':'.$e->getLine(),
            ]);

            $this->sendErrorToUser($update, $e);
        }
    }

    /**
     * Extract the forum topic thread ID from an incoming update.
     *
     * Returns null for private chats, normal groups, and the General topic —
     * callers must NOT forward null as a thread ID to the Telegram API.
     */
    private static function extractMessageThreadId(Update $update): ?int
    {
        if ($callbackQuery = $update->getCallbackQuery()) {
            $threadId = $callbackQuery->getMessage()?->get('message_thread_id');
        } else {
            $threadId = $update->getMessage()->get('message_thread_id');
        }

        return $threadId !== null ? (int) $threadId : null;
    }

    /**
     * Build the extra params array that forwards the forum topic thread ID.
     *
     * @return array<string, mixed>
     */
    private static function threadExtra(?int $messageThreadId): array
    {
        return $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
    }

    /**
     * Handle forum_topic_created / forum_topic_edited service messages.
     *
     * Returns true if the update was consumed (caller should return early).
     */
    private function handleForumServiceMessage(Update $update): bool
    {
        $message = $update->getMessage();

        if ($message === null || $message->isEmpty()) {
            return false;
        }

        $forumCreated = $message->get('forum_topic_created');
        if ($forumCreated !== null) {
            $this->handleForumTopicCreated($update);

            return true;
        }

        $forumEdited = $message->get('forum_topic_edited');
        if ($forumEdited !== null) {
            $this->handleForumTopicEdited($update);

            return true;
        }

        return false;
    }

    private function handleForumTopicCreated(Update $update): void
    {
        $message = $update->getMessage();
        $chat = $update->getChat();
        $chatId = (int) $chat->get('id', 0);
        $chatType = $chat->get('type', 'private');

        if (! TelegramChat::isGroup($chatType)) {
            return;
        }

        $messageThreadId = self::extractMessageThreadId($update);

        if ($messageThreadId === null) {
            return;
        }

        $forumCreated = $message->get('forum_topic_created');
        $topicName = $forumCreated->get('name') ?? null;

        if ($topicName === null) {
            return;
        }

        // Store the name if this topic is already in the allow-list,
        // or cache it for future use when the admin runs /allow_topic.
        $this->topicAccess->addTopic($chatId, $messageThreadId, $topicName);
    }

    private function handleForumTopicEdited(Update $update): void
    {
        $message = $update->getMessage();
        $chat = $update->getChat();
        $chatId = (int) $chat->get('id', 0);
        $chatType = $chat->get('type', 'private');

        if (! TelegramChat::isGroup($chatType)) {
            return;
        }

        $messageThreadId = self::extractMessageThreadId($update);

        if ($messageThreadId === null) {
            return;
        }

        $forumEdited = $message->get('forum_topic_edited');
        $topicName = $forumEdited->get('name') ?? null;

        if ($topicName === null) {
            return;
        }

        $this->topicAccess->updateTopicName($chatId, $messageThreadId, $topicName);
    }

    /**
     * Check if this update comes from a restricted topic that should be silently ignored.
     */
    private function isTopicRestricted(Update $update): bool
    {
        $chat = $update->getChat();
        if ($chat === null || $chat->isEmpty()) {
            return false;
        }

        $chatId = (int) $chat->get('id', 0);
        $chatType = $chat->get('type', 'private');

        if (! TelegramChat::isGroup($chatType)) {
            return false;
        }

        $messageThreadId = self::extractMessageThreadId($update);

        if ($this->topicAccess->isTopicAllowed($chatId, $messageThreadId)) {
            return false;
        }

        return ! $this->isAdminTopicCommand($update);
    }

    /**
     * Determine if the update is an admin topic management command.
     */
    private function isAdminTopicCommand(Update $update): bool
    {
        if ($callbackQuery = $update->getCallbackQuery()) {
            $data = $callbackQuery->getData() ?? '';

            if (! CallbackData::startsWith($data, CallbackAction::Flow)) {
                return false;
            }

            $payload = CallbackData::strip($data, CallbackAction::Flow);

            return in_array('/'.$payload, self::ADMIN_TOPIC_COMMANDS, true);
        }

        $message = $update->getMessage();
        if ($message->isEmpty()) {
            return false;
        }

        $text = trim((string) $message->get('text', ''));
        $parts = preg_split('/\s+/', $text) ?: [];
        $command = strtolower(explode('@', $parts[0] ?? '')[0]);

        return in_array($command, self::ADMIN_TOPIC_COMMANDS, true);
    }

    private function sendErrorToUser(Update $update, Throwable $e): void
    {
        $chatId = null;
        $messageId = null;
        $messageThreadId = self::extractMessageThreadId($update);

        if ($callbackQuery = $update->getCallbackQuery()) {
            $chatId = $callbackQuery->getMessage()?->getChat()?->getId();
            $messageId = $callbackQuery->getMessage()?->getMessageId();
        } elseif ($message = $update->getMessage()) {
            $chatId = $message->getChat()?->getId();
            $messageId = $message->getMessageId();
        }

        if ($chatId === null) {
            return;
        }

        $chatId = (int) $chatId;
        $userMessage = CommonMessageResource::forThrowable($e);
        $extra = self::threadExtra($messageThreadId);

        if ($messageId !== null) {
            $this->messenger->replyToMessage($chatId, $userMessage, (int) $messageId, $extra);
        } else {
            $this->messenger->sendMessage($chatId, $userMessage, $extra);
        }
    }

    private function handleReportsMenu(string $payload, CallbackQuery $callbackQuery, int $telegramUserId): void
    {
        $chatId = (int) ($callbackQuery->getMessage()?->getChat()?->getId() ?? 0);
        $messageId = (int) ($callbackQuery->getMessage()?->getMessageId() ?? 0);
        $chatType = (string) ($callbackQuery->getMessage()?->getChat()?->get('type', 'private'));
        $isGroup = TelegramChat::isGroup($chatType);
        $messageThreadId = $callbackQuery->getMessage()?->get('message_thread_id');
        $messageThreadId = $messageThreadId !== null ? (int) $messageThreadId : null;

        $this->messenger->answerCallback($callbackQuery);

        if (! $this->access->isBotAdmin($telegramUserId)) {
            if ($chatId !== 0) {
                $this->messenger->replyToMessage($chatId, ReportsMessageResource::adminOnly(), $messageId, self::threadExtra($messageThreadId));
            }

            return;
        }

        match ($payload) {
            'report' => $this->flow->startFlow($telegramUserId, $chatId, $messageId, FlowCommand::Report, fn () => $this->reportInitialData(), $isGroup, $messageThreadId),
            'tasks' => $this->reportsTasks->openProjectPicker($chatId, $telegramUserId, $messageId, $isGroup, $messageThreadId),
            'timeline' => $this->reportsTasks->openTimelineMenu($chatId, $telegramUserId, $messageId, $isGroup, $messageThreadId),
            default => Log::warning('Unhandled reports menu payload', ['payload' => $payload]),
        };
    }

    /** @return array<string, bool> */
    private function reportInitialData(): array
    {
        // Severity/Impact pickers removed from the report flow — the Jira field lookups
        // below were only used to gate them. Uncomment to restore.
        // return [
        //     '_sev_ok' => $this->jira->customFieldId(config('services.jira.custom_fields.severity')) !== null,
        //     '_imp_ok' => $this->jira->customFieldId(config('services.jira.custom_fields.impact')) !== null,
        // ];

        return [
            '_cat_ok' => $this->jira->customFieldId((string) config('services.jira.custom_fields.category')) !== null,
        ];
    }

    private function handleCallbackQuery(Update $update): void
    {
        $this->routeCallback($update->getCallbackQuery(), self::extractMessageThreadId($update));
    }

    /**
     * Routes a callback — a real inline button press, or a reply keyboard
     * tap replayed as a virtual callback — to the handler owning its prefix.
     */
    private function routeCallback(CallbackQuery $callbackQuery, ?int $messageThreadId): void
    {
        $callbackUserId = $callbackQuery->getFrom()?->getId();
        $data = $callbackQuery->getData() ?? '';

        if ($callbackUserId === null) {
            return;
        }

        $this->syncTelegramUsername((int) $callbackUserId, (string) ($callbackQuery->getFrom()?->get('username') ?? ''), (string) ($callbackQuery->getFrom()?->get('first_name') ?? ''));

        if (! $this->access->isUserLinked((int) $callbackUserId) && ! $this->isCallbackOpenToUnlinked($data)) {
            $chatId = (int) ($callbackQuery->getMessage()?->getChat()?->getId() ?? 0);
            $messageId = (int) ($callbackQuery->getMessage()?->getMessageId() ?? 0);

            if ($chatId !== 0) {
                $this->messenger->replyToMessage($chatId, CommonMessageResource::notLinked(), $messageId, self::threadExtra($messageThreadId));
            }

            return;
        }

        if ($data === CallbackAction::LinkCancel->value) {
            $this->cancelLinkPicker($callbackQuery, (int) $callbackUserId, $messageThreadId);

            return;
        }

        if (CallbackData::startsWith($data, CallbackAction::Flow)) {
            $this->flow->handleFlowCallback($callbackQuery, (int) $callbackUserId, $messageThreadId);

            return;
        }

        if (($menuPayload = CallbackData::stripIf($data, CallbackAction::RptMenu)) !== null) {
            $this->handleReportsMenu($menuPayload, $callbackQuery, (int) $callbackUserId);

            return;
        }

        foreach (self::REPORTS_CALLBACK_ACTIONS as $action) {
            if (CallbackData::startsWith($data, $action)) {
                $this->reportsTasks->handleCallback($callbackQuery, $messageThreadId);

                return;
            }
        }

        if ($data === CallbackAction::Noop->value) {
            return;
        }

        $this->callbacks->handleCallbackQuery($callbackQuery, $messageThreadId);
    }

    private function isCallbackOpenToUnlinked(string $data): bool
    {
        if (CallbackData::startsWith($data, CallbackAction::LinkPick)
            || $data === CallbackAction::LinkCancel->value
            || $data === CallbackAction::FlowCancel->value) {
            return true;
        }

        if (! CallbackData::startsWith($data, CallbackAction::Flow)) {
            return false;
        }

        $payload = CallbackData::strip($data, CallbackAction::Flow);

        if ($payload === FlowCommand::Link->value || str_starts_with($payload, FlowCommand::Link->value.'_')) {
            return true;
        }

        return in_array($payload, self::UNLINKED_INFO_CALLBACKS, true);
    }

    private function handleMessage(Update $update): void
    {
        $message = $update->getMessage();
        $chat = $update->getChat();
        $chatId = (int) $chat->get('id', 0);
        $chatType = $chat->get('type', 'private');
        $isGroup = TelegramChat::isGroup($chatType);

        $from = $message->get('from');

        // Channel posts and anonymous admins carry no sender to act for.
        if ($from === null) {
            return;
        }

        $telegramUserId = (int) $from->get('id');
        $firstName = (string) ($from->get('first_name') ?? '');
        $username = (string) ($from->get('username') ?? '');
        $text = trim((string) $message->get('text', ''));
        $messageId = (int) $message->get('message_id', 0);
        $messageThreadId = self::extractMessageThreadId($update);

        $this->replyKeyboard->rememberUserMessage($telegramUserId, $chatId, $messageId);

        // Main menu buttons always win: they leave whatever flow, pending
        // input or task-management session is in progress and start afresh.
        $keyboardCommand = $this->resolveKeyboardCommand($text);

        if ($keyboardCommand === null) {
            $action = $this->replyKeyboard->resolve($telegramUserId, $text);

            if ($action !== null) {
                $this->routeCallback($this->virtualCallback($message->toArray(), $action), $messageThreadId);

                return;
            }

            if ($this->reportsTasks->consumePendingText($telegramUserId, $chatId, $text, $messageThreadId)) {
                return;
            }
        }

        $this->syncTelegramUsername($telegramUserId, $username, $firstName);

        if ($keyboardCommand !== null) {
            $this->leaveEveryFlow($chatId, $telegramUserId);

            $command = $keyboardCommand;
            $arguments = [];
        } else {
            if ($this->conversation->isActive($telegramUserId)) {
                $lowerText = strtolower($text);
                if ($lowerText === '/cancel' || $lowerText === '/menu' || $text === __('keyboard.button.cancel', [], 'fa') || $text === __('keyboard.button.cancel', [], 'en')) {
                    $this->conversation->deleteTrackedMessages($chatId, $telegramUserId);
                    $this->conversation->clear($telegramUserId);
                    if ($lowerText === '/menu') {
                        $this->info->showMainMenu($chatId, $messageId, $messageThreadId, $telegramUserId);
                    } else {
                        $this->messenger->sendMessage($chatId, FlowMessageResource::flowCancelled(), array_merge(
                            self::threadExtra($messageThreadId),
                            $this->replyKeyboard->mainMenu($telegramUserId, $chatId),
                        ));
                    }

                    return;
                }

                if (! str_starts_with($text, '/')) {
                    Log::info('Flow text input received', [
                        'user_id' => $telegramUserId,
                        'chat_id' => $chatId,
                        'text_length' => mb_strlen($text),
                    ]);
                    $this->flow->handleFlowText($telegramUserId, $chatId, $messageId, $text, $messageThreadId);

                    return;
                }
            }

            if ($isGroup && ! str_starts_with($text, '/')) {
                return;
            }

            $parts = preg_split('/\s+/', $text) ?: [];
            $command = strtolower(explode('@', $parts[0] ?? '')[0]);
            $arguments = array_slice($parts, 1);
        }

        $exemptCommands = ['/link', '/start', '/help', '/menu' /* , '/about' */];

        if (! in_array($command, $exemptCommands, true) && ! $this->access->isUserLinked($telegramUserId)) {
            $this->messenger->replyToMessage($chatId, CommonMessageResource::notLinked(), $messageId, self::threadExtra($messageThreadId));

            return;
        }

        match ($command) {
            '/start' => $this->info->start($chatId, $firstName, $isGroup, $messageId, $messageThreadId, $telegramUserId),
            '/help' => $this->info->help($chatId, $isGroup, $messageId, $messageThreadId, $telegramUserId),
            '/menu' => $this->info->showMainMenu($chatId, $messageId, $messageThreadId, $telegramUserId),
            // About command removed — uncomment to restore.
            // '/about' => $this->info->about($chatId, $isGroup, $messageId, $messageThreadId),
            '/link' => $this->flow->startFlow($telegramUserId, $chatId, $messageId, FlowCommand::Link, ['email' => $arguments[0] ?? '', 'username' => $username], $isGroup, $messageThreadId),
            // Projects command removed — uncomment to restore.
            // '/projects' => $this->info->sendProjects($chatId, $isGroup, $messageId, $messageThreadId),
            // Tasks flow disabled — uncomment to restore.
            // '/tasks', '/myissues' => $this->flow->startFlow($telegramUserId, $chatId, $messageId, FlowCommand::Tasks, ['project' => strtoupper((string) ($arguments[0] ?? ''))], $isGroup, $messageThreadId),
            // Task details disabled — uncomment to restore.
            // '/task' => $this->flow->startFlow($telegramUserId, $chatId, $messageId, FlowCommand::TaskDetail, [], $isGroup, $messageThreadId),
            // '/users' => $this->info->listUsers($chatId, $isGroup, $messageId, $messageThreadId),
            // Assign disabled — uncomment to restore.
            // '/assign' => $this->flow->startFlow($telegramUserId, $chatId, $messageId, FlowCommand::Assign, ['task' => strtoupper((string) ($arguments[0] ?? ''))], $isGroup, $messageThreadId),
            // Unassign disabled — uncomment to restore.
            // '/unassign' => $this->flow->startFlow($telegramUserId, $chatId, $messageId, FlowCommand::Unassign, ['task' => strtoupper((string) ($arguments[0] ?? ''))], $isGroup, $messageThreadId),
            // Create task disabled — uncomment to restore.
            // '/create' => $this->flow->startFlow($telegramUserId, $chatId, $messageId, FlowCommand::Create, [
            //     'project' => strtoupper((string) ($arguments[0] ?? '')),
            //     'summary' => implode(' ', array_slice($arguments, 1)),
            // ], $isGroup, $messageThreadId),
            // Boards command removed — uncomment to restore.
            // '/boards' => $this->info->sendBoards($chatId, $isGroup, $messageId, $messageThreadId),
            // Sprints disabled — uncomment to restore.
            // '/sprints' => $this->flow->startFlow($telegramUserId, $chatId, $messageId, FlowCommand::Sprints, ['board' => (int) ($arguments[0] ?? 0)], $isGroup, $messageThreadId),
            // Push to sprint disabled — uncomment to restore.
            // '/push' => $this->flow->startFlow($telegramUserId, $chatId, $messageId, FlowCommand::Push, [], $isGroup, $messageThreadId),
            // Change status disabled — uncomment to restore.
            // '/status' => $this->flow->startFlow($telegramUserId, $chatId, $messageId, FlowCommand::Status, ['task' => strtoupper((string) ($arguments[0] ?? ''))], $isGroup, $messageThreadId),
            '/report' => $this->access->isBotAdmin($telegramUserId)
                ? $this->flow->startFlow($telegramUserId, $chatId, $messageId, FlowCommand::Report, fn () => $this->reportInitialData(), $isGroup, $messageThreadId)
                : $this->messenger->sendMessage($chatId, ReportsMessageResource::adminOnly(), self::threadExtra($messageThreadId)),
            '/managetasks' => $this->reportsTasks->openProjectPicker($chatId, $telegramUserId, $messageId, $isGroup, $messageThreadId),
            '/timeline' => $this->handleTimelineCommand($chatId, $telegramUserId, $messageId, $isGroup, $arguments, $messageThreadId),
            '/allow_topic' => $this->handleAllowTopic($chatId, $telegramUserId, $isGroup, $messageId, $messageThreadId, $update),
            '/disallow_topic' => $this->handleDisallowTopic($chatId, $telegramUserId, $isGroup, $messageId, $messageThreadId, $update),
            '/list_allowed_topics' => $this->handleListAllowedTopics($chatId, $telegramUserId, $isGroup, $messageId, $messageThreadId),
            '/set_topic_name' => $this->handleSetTopicName($chatId, $telegramUserId, $isGroup, $messageId, $messageThreadId, $arguments),
            default => $this->info->help($chatId, $isGroup, $messageId, $messageThreadId, $telegramUserId),
        };
    }

    private function handleTimelineCommand(int $chatId, int $telegramUserId, int $messageId, bool $isGroup, array $arguments, ?int $messageThreadId): void
    {
        if (! $this->access->isBotAdmin($telegramUserId)) {
            $this->messenger->sendMessage($chatId, ReportsMessageResource::adminOnly(), self::threadExtra($messageThreadId));

            return;
        }

        if (! empty($arguments[0])) {
            app(TaskService::class)->sendTimelineForCommand(
                $chatId,
                strtoupper((string) $arguments[0]),
                $messageThreadId,
            );

            return;
        }

        $this->reportsTasks->openTimelineMenu($chatId, $telegramUserId, $messageId, $isGroup, $messageThreadId);
    }

    private function handleAllowTopic(int $chatId, ?int $telegramUserId, bool $isGroup, int $messageId, ?int $messageThreadId, Update $update): void
    {
        if (! $isGroup) {
            $this->messenger->replyToMessage($chatId, __('common.topic.not_forum'), $messageId, self::threadExtra($messageThreadId));

            return;
        }

        if ($messageThreadId === null) {
            $this->messenger->replyToMessage($chatId, __('common.topic.not_forum'), $messageId, self::threadExtra($messageThreadId));

            return;
        }

        if (! $this->access->isGroupAdmin($chatId, (int) $telegramUserId)) {
            $this->messenger->replyToMessage($chatId, __('common.topic.not_admin'), $messageId, self::threadExtra($messageThreadId));

            return;
        }

        $message = $update->getMessage();
        $forumCreated = $message->get('forum_topic_created');
        $topicName = $forumCreated
            ? ($forumCreated->get('name') ?? null)
            : $this->topicAccess->getCachedTopicName($chatId, $messageThreadId);

        $this->topicAccess->addTopic($chatId, $messageThreadId, $topicName);

        $this->messenger->replyToMessage(
            $chatId,
            __('common.topic.allowed', ['name' => $topicName ?? (string) $messageThreadId]),
            $messageId,
            self::threadExtra($messageThreadId),
        );
    }

    private function handleDisallowTopic(int $chatId, ?int $telegramUserId, bool $isGroup, int $messageId, ?int $messageThreadId, Update $update): void
    {
        if (! $isGroup) {
            $this->messenger->replyToMessage($chatId, __('common.topic.not_forum'), $messageId, self::threadExtra($messageThreadId));

            return;
        }

        if ($messageThreadId === null) {
            $this->messenger->replyToMessage($chatId, __('common.topic.not_forum'), $messageId, self::threadExtra($messageThreadId));

            return;
        }

        if (! $this->access->isGroupAdmin($chatId, (int) $telegramUserId)) {
            $this->messenger->replyToMessage($chatId, __('common.topic.not_admin'), $messageId, self::threadExtra($messageThreadId));

            return;
        }

        $topics = $this->topicAccess->getAllowedTopics($chatId);
        $topic = $topics->firstWhere('message_thread_id', $messageThreadId);
        $topicName = $topic?->topic_name ?? (string) $messageThreadId;

        $this->topicAccess->removeTopic($chatId, $messageThreadId);

        $remaining = $this->topicAccess->getAllowedTopics($chatId);

        if ($remaining->isEmpty()) {
            $this->messenger->replyToMessage(
                $chatId,
                __('common.topic.disallowed_last'),
                $messageId,
                self::threadExtra($messageThreadId),
            );
        } else {
            $this->messenger->replyToMessage(
                $chatId,
                __('common.topic.disallowed', ['name' => $topicName]),
                $messageId,
                self::threadExtra($messageThreadId),
            );
        }
    }

    private function handleListAllowedTopics(int $chatId, ?int $telegramUserId, bool $isGroup, int $messageId, ?int $messageThreadId): void
    {
        if (! $isGroup) {
            $this->messenger->replyToMessage($chatId, __('common.topic.not_forum'), $messageId, self::threadExtra($messageThreadId));

            return;
        }

        if (! $this->access->isGroupAdmin($chatId, (int) $telegramUserId)) {
            $this->messenger->replyToMessage($chatId, __('common.topic.not_admin'), $messageId, self::threadExtra($messageThreadId));

            return;
        }

        $topics = $this->topicAccess->getAllowedTopics($chatId);

        if ($topics->isEmpty()) {
            $this->messenger->replyToMessage(
                $chatId,
                __('common.topic.list_empty'),
                $messageId,
                self::threadExtra($messageThreadId),
            );

            return;
        }

        $lines = [__('common.topic.list_header')];

        foreach ($topics as $topic) {
            $lines[] = __('common.topic.list_item', [
                'id' => $topic->message_thread_id,
                'name' => $topic->topic_name ?? __('common.topic.name_unknown'),
            ]);
        }

        $this->messenger->replyToMessage(
            $chatId,
            implode("\n", $lines),
            $messageId,
            self::threadExtra($messageThreadId),
        );
    }

    private function handleSetTopicName(int $chatId, ?int $telegramUserId, bool $isGroup, int $messageId, ?int $messageThreadId, array $arguments): void
    {
        if (! $isGroup) {
            $this->messenger->replyToMessage($chatId, __('common.topic.not_forum'), $messageId, self::threadExtra($messageThreadId));

            return;
        }

        if ($messageThreadId === null) {
            $this->messenger->replyToMessage($chatId, __('common.topic.not_forum'), $messageId, self::threadExtra($messageThreadId));

            return;
        }

        if (! $this->access->isGroupAdmin($chatId, (int) $telegramUserId)) {
            $this->messenger->replyToMessage($chatId, __('common.topic.not_admin'), $messageId, self::threadExtra($messageThreadId));

            return;
        }

        if (! $this->topicAccess->topicExists($chatId, $messageThreadId)) {
            $this->messenger->replyToMessage($chatId, __('common.topic.name_not_allowed'), $messageId, self::threadExtra($messageThreadId));

            return;
        }

        $this->flow->startFlow($telegramUserId, $chatId, $messageId, FlowCommand::SetTopicName, [], $isGroup, $messageThreadId);
    }

    private function syncTelegramUsername(int $telegramUserId, string $username, string $firstName): void
    {
        $jiraUser = JiraUser::where('telegram_admin_id', $telegramUserId)->first();

        if (! $jiraUser) {
            return;
        }

        $resolvedUsername = $username !== '' ? $username : $firstName;

        if ($resolvedUsername === $jiraUser->telegram_username) {
            return;
        }

        $jiraUser->update(['telegram_username' => $resolvedUsername]);
    }

    /** Clears every flow state of the user so a main menu command starts clean. */
    private function leaveEveryFlow(int $chatId, int $telegramUserId): void
    {
        if ($this->conversation->isActive($telegramUserId)) {
            $this->conversation->deleteTrackedMessages($chatId, $telegramUserId);
            $this->conversation->clear($telegramUserId);
        }

        $this->reportsTasks->reset($chatId, $telegramUserId);
        $this->replyKeyboard->forget($telegramUserId);
    }

    /**
     * A reply keyboard tap replayed as a callback query carrying the action
     * the tapped label stands for; its message is the user's own message.
     *
     * @param  array<string, mixed>  $message
     */
    private function virtualCallback(array $message, string $action): CallbackQuery
    {
        return new CallbackQuery([
            'id' => ReplyKeyboardService::VIRTUAL_CALLBACK_ID,
            'from' => $message['from'] ?? [],
            'message' => $message,
            'chat_instance' => '',
            'data' => $action,
        ]);
    }

    private function cancelLinkPicker(CallbackQuery $callbackQuery, int $telegramUserId, ?int $messageThreadId): void
    {
        $chatId = (int) ($callbackQuery->getMessage()?->getChat()?->getId() ?? 0);

        $this->messenger->answerCallback($callbackQuery);

        if ($chatId === 0) {
            return;
        }

        $this->messenger->sendMessage($chatId, FlowMessageResource::flowCancelled(), array_merge(
            self::threadExtra($messageThreadId),
            $this->replyKeyboard->mainMenu($telegramUserId, $chatId),
        ));
    }

    private function resolveKeyboardCommand(string $text): ?string
    {
        $map = [
            (string) __('keyboard.button.link_account', [], 'fa') => '/link',
            (string) __('keyboard.button.link_account', [], 'en') => '/link',
            (string) __('keyboard.button.register_report', [], 'fa') => '/report',
            (string) __('keyboard.button.register_report', [], 'en') => '/report',
            (string) __('keyboard.button.manage_tasks', [], 'fa') => '/managetasks',
            (string) __('keyboard.button.manage_tasks', [], 'en') => '/managetasks',
            (string) __('keyboard.button.timeline', [], 'fa') => '/timeline',
            (string) __('keyboard.button.timeline', [], 'en') => '/timeline',
            (string) __('keyboard.button.help', [], 'fa') => '/help',
            (string) __('keyboard.button.help', [], 'en') => '/help',
        ];

        return $map[$text] ?? null;
    }
}
