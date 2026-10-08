<?php

namespace App\Services\Telegram;

use App\Enums\CallbackAction;
use App\Resources\KeyboardResource;
use App\Support\ReplyMarkup;
use Illuminate\Support\Facades\Cache;

/**
 * Renders flow screens on the reply keyboard (the keyboard area under the
 * input field) instead of inline buttons under a message.
 *
 * Screen builders keep producing inline-shaped rows (text + callback_data);
 * this service shows only the labels and remembers, per user, which action
 * each label stands for. A tapped button arrives as a plain text message and
 * {@see resolve()} turns it back into the original callback data, so the
 * existing callback handlers keep driving every flow.
 */
class ReplyKeyboardService
{
    /** Callback query id used for button taps replayed from the reply keyboard. */
    public const VIRTUAL_CALLBACK_ID = 'reply_keyboard';

    private const MAP_PREFIX = 'reply_kb_';

    private const LAST_MESSAGE_PREFIX = 'reply_kb_msg_';

    private const MAX_LABEL_LENGTH = 60;

    public function __construct(private AccessService $access) {}

    /**
     * Converts inline rows to a reply keyboard, remembers the label → action
     * map for the user and returns the send params (reply_markup, plus the
     * reply target that keeps a group keyboard visible to this user only).
     *
     * @param  array<int|string, mixed>  $markup  ['inline_keyboard' => rows] or the rows themselves
     * @return array<string, string>
     */
    public function extra(array $markup, int $userId, int $chatId): array
    {
        $rows = $markup['inline_keyboard'] ?? $markup;
        $keyboard = [];
        $map = [];

        foreach ($rows as $row) {
            $buttons = [];

            foreach ((array) $row as $button) {
                $action = (string) ($button['callback_data'] ?? '');

                if ($action === '' || $action === CallbackAction::Noop->value) {
                    continue;
                }

                $label = $this->uniqueLabel($this->normalizeLabel((string) ($button['text'] ?? '')), $map);
                $map[$label] = $action;
                $buttons[] = ['text' => $label];
            }

            if ($buttons !== []) {
                $keyboard[] = $buttons;
            }
        }

        if ($userId > 0) {
            Cache::put(self::MAP_PREFIX.$userId, $map, $this->expiry());
        }

        return $this->params($keyboard, $userId, $chatId);
    }

    /**
     * Send params that bring back the main menu keyboard, ending any flow
     * keyboard. Optional inline-shaped rows are shown above the menu and
     * stay tappable (e.g. the timeline button of a freshly created report).
     *
     * @param  array<int, array<int, array<string, mixed>>>  $rowsAbove
     * @return array<string, string>
     */
    public function mainMenu(int $userId, int $chatId, array $rowsAbove = []): array
    {
        $this->forget($userId);

        $menu = KeyboardResource::mainMenu($this->access->shouldShowLinkButton($userId));

        if ($rowsAbove === []) {
            return $this->params($menu['keyboard'], $userId, $chatId);
        }

        $extra = $this->extra($rowsAbove, $userId, $chatId);
        $above = json_decode($extra['reply_markup'], true)['keyboard'] ?? [];

        return $this->params(array_merge($above, $menu['keyboard']), $userId, $chatId);
    }

    /** The callback data behind a reply keyboard label the user tapped, if any. */
    public function resolve(int $userId, string $text): ?string
    {
        if ($userId <= 0 || $text === '') {
            return null;
        }

        $map = Cache::get(self::MAP_PREFIX.$userId, []);

        return is_array($map) && isset($map[$text]) ? (string) $map[$text] : null;
    }

    public function forget(int $userId): void
    {
        Cache::forget(self::MAP_PREFIX.$userId);
    }

    /**
     * Remembers the user's latest message in a group: bot keyboards reply to
     * it so that, being selective, only that user sees them.
     */
    public function rememberUserMessage(int $userId, int $chatId, int $messageId): void
    {
        if ($userId <= 0 || $messageId <= 0 || $chatId >= 0) {
            return;
        }

        Cache::put(self::LAST_MESSAGE_PREFIX.$userId.'_'.$chatId, $messageId, $this->expiry());
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $keyboard
     * @return array<string, string>
     */
    private function params(array $keyboard, int $userId, int $chatId): array
    {
        $markup = [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
        $params = [];

        if ($chatId < 0) {
            $markup['selective'] = true;
            $replyTo = (int) Cache::get(self::LAST_MESSAGE_PREFIX.$userId.'_'.$chatId, 0);

            if ($replyTo > 0) {
                $params['reply_parameters'] = ReplyMarkup::toJson([
                    'message_id' => $replyTo,
                    'allow_sending_without_reply' => true,
                ]);
            }
        }

        $params['reply_markup'] = ReplyMarkup::toJson($markup);

        return $params;
    }

    /** Keyboards live as long as a task-management session does. */
    private function expiry(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('telegram.reports.context_ttl_minutes'));
    }

    private function normalizeLabel(string $label): string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));

        if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            $label = rtrim(mb_substr($label, 0, self::MAX_LABEL_LENGTH - 1)).'…';
        }

        return $label !== '' ? $label : '?';
    }

    /** @param  array<string, string>  $map */
    private function uniqueLabel(string $label, array $map): string
    {
        if (! isset($map[$label])) {
            return $label;
        }

        $n = 2;

        while (isset($map["{$label} ({$n})"])) {
            $n++;
        }

        return "{$label} ({$n})";
    }
}
