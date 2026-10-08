<?php

namespace App\DTOs;

readonly class ReportData
{
    public function __construct(
        public string $project,
        public string $type,
        public string $description,
        public string $userName = '',
        public string $userIdentifier = '',
        public string $userMobile = '',
        public ?string $assignee = null,
        public ?string $priority = null,
        public ?string $severity = null,
        public ?string $impact = null,
    ) {}

    /** @param  array<string, mixed>  $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            project: $raw['project'] ?? '',
            type: $raw['type'] ?? '',
            description: $raw['description'] ?? '',
            userName: $raw['user_name'] ?? '',
            userIdentifier: $raw['user_identifier'] ?? '',
            userMobile: $raw['user_mobile'] ?? '',
            assignee: $raw['assignee'] ?? null,
            priority: $raw['priority'] ?? null,
            severity: $raw['severity'] ?? null,
            impact: $raw['impact'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'project' => $this->project,
            'type' => $this->type,
            'description' => $this->description,
            'user_name' => $this->userName,
            'user_identifier' => $this->userIdentifier,
            'user_mobile' => $this->userMobile,
            'assignee' => $this->assignee,
            'priority' => $this->priority,
            'severity' => $this->severity,
            'impact' => $this->impact,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
