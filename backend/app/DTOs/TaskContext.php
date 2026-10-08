<?php

namespace App\DTOs;

readonly class TaskContext
{
    public function __construct(
        public string $project,
        public ?string $statusId = null,
        public ?string $statusName = null,
        public int $page = 1,
        public ?string $pendingTask = null,
        /** @var array<string, mixed>|null */
        public ?array $pending = null,
    ) {}

    /** @param  array<string, mixed>  $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            project: $raw['project'] ?? '',
            statusId: $raw['status_id'] ?? null,
            statusName: $raw['status_name'] ?? null,
            page: $raw['page'] ?? 1,
            pendingTask: $raw['pending_task'] ?? null,
            pending: $raw['pending'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'project' => $this->project,
            'status_id' => $this->statusId,
            'status_name' => $this->statusName,
            'page' => $this->page,
            'pending_task' => $this->pendingTask,
            'pending' => $this->pending,
        ];
    }
}
