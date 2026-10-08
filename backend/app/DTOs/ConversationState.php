<?php

namespace App\DTOs;

use App\Enums\FlowCommand;

readonly class ConversationState
{
    public function __construct(
        public string $command,
        public int $step,
        /** @var array<string, mixed> */
        public array $data,
        public int $chatId,
        public int $messageId,
        public bool $isGroup,
        public bool $projectFirst = false,
        public ?int $messageThreadId = null,
    ) {}

    public function commandEnum(): ?FlowCommand
    {
        return FlowCommand::tryFrom($this->command);
    }

    /** @param  array<string, mixed>  $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            command: $raw['command'] ?? '',
            step: $raw['step'] ?? 0,
            data: $raw['data'] ?? [],
            chatId: $raw['chat_id'] ?? 0,
            messageId: $raw['message_id'] ?? 0,
            isGroup: $raw['is_group'] ?? false,
            projectFirst: $raw['project_first'] ?? false,
            messageThreadId: $raw['message_thread_id'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'command' => $this->command,
            'step' => $this->step,
            'data' => $this->data,
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'is_group' => $this->isGroup,
            'project_first' => $this->projectFirst,
            'message_thread_id' => $this->messageThreadId,
        ];
    }
}
