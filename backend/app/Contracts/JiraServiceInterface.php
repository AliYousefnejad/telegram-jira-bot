<?php

namespace App\Contracts;

interface JiraServiceInterface
{
    /** @return array<string, mixed> */
    public function getProjects(): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array{issues: array<int, mixed>, total: int, hasMore: bool}
     */
    public function getIssues(string $jql, int $maxResults = 20, int $startAt = 0, bool $withCustomFields = false): array;

    public function getIssue(string $key): ?array;

    /** @return array<int, mixed> */
    public function getUsers(?string $query = null): array;

    /** @return array<int, mixed> */
    public function getBoards(): array;

    /** @return array<int, mixed> */
    public function getSprints(int $boardId): array;

    /** @return array<string, mixed> */
    public function getBoardConfiguration(int $boardId): array;

    public function getProjectBoard(string $projectKey): ?int;

    /** @return array<int, mixed> */
    public function getIssueTransitions(string $issueKey): array;

    /** @return array<string, mixed> */
    public function assignIssue(string $issueKey, string $userName): array;

    /** @return array<string, mixed> */
    public function unassignIssue(string $issueKey): array;

    /**
     * @param  array<string, mixed>  $extraFields
     * @return array<string, mixed>
     */
    public function createIssue(
        string $projectKey,
        string $summary,
        string $issueType = 'Task',
        ?string $description = null,
        ?string $assigneeUserName = null,
        ?string $reporterUserName = null,
        array $extraFields = [],
    ): array;

    /** @return array<string, mixed> */
    public function transitionIssue(string $issueKey, string $transitionId): array;

    /** @return array<string, mixed> */
    public function moveIssueToSprint(int $sprintId, string $issueKey): array;

    public function updateDescription(string $issueKey, string $description): void;

    /**
     * Add a comment (Jira wiki markup) to an issue.
     *
     * @return array<string, mixed> the created comment
     */
    public function addComment(string $issueKey, string $body): array;

    /**
     * @param  string|int|array<string, mixed>  $fieldPayload
     */
    public function updateIssueField(string $issueKey, string $fieldId, string|int|array $fieldPayload): void;

    /** @return array<int, mixed> */
    public function getProjectStatuses(string $projectKey): array;

    /** @return array<int, mixed> */
    public function getAssignableUsers(string $issueKey): array;

    /** @return array<int, mixed> */
    public function getAssignableUsersForProject(string $projectKey): array;

    /**
     * One Jira user by id (Data Center username), with displayName, emailAddress, avatarUrls and timeZone.
     *
     * @return array<string, mixed>|null
     */
    public function getUser(string $userId): ?array;

    /**
     * Download an avatar image from this Jira instance (avatars need authentication).
     *
     * @return array{body: string, type: string}|null
     */
    public function fetchAvatar(string $url): ?array;

    /**
     * The Jira user who owns a Personal Access Token (GET /myself with it), or null when Jira refuses it.
     *
     * @return array<string, mixed>|null
     */
    public function verifyToken(string $token): ?array;

    public function customFieldId(string $name): ?string;

    /** @return array<int, mixed> */
    public function fieldAllowedOptions(string $issueKey, string $fieldId): array;

    /**
     * Allowed options of a custom field for creating issues in a project.
     *
     * @return array<int, array{id: string, value: string}>
     */
    public function getCreateFieldAllowedOptions(string $projectKey, string $fieldId, ?string $issueType = null): array;

    public function optionIdForCode(string $issueKey, string $fieldId, string $code): ?string;

    /**
     * Fetch the issue history: status, assignee and owner changes from the
     * changelog plus every comment, as one chronological event list.
     *
     * @return array{
     *     createdAt: string,
     *     createdBy: ?string,
     *     currentStatus: string,
     *     currentAssignee: ?string,
     *     events: array<int, array{
     *         type: string,
     *         timestamp: string,
     *         author: ?string,
     *         from: ?string,
     *         to: ?string,
     *         body?: string
     *     }>
     * }
     */
    public function getIssueChangelog(string $issueKey): array;
}
