<?php

namespace App\Services\Telegram\Reports;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Enums\ReportType;
use App\Enums\TaskImpact;
use App\Enums\TaskSeverity;
use App\Models\JiraUser;
use App\Resources\ReportsMessageResource;
use App\Services\Jira\TaskOwnerService;
use App\Services\Telegram\ReplyKeyboardService;
use App\Support\CallbackData;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ReportFlowService
{
    private const MIN_DESCRIPTION_LENGTH = 10;

    public function __construct(
        private JiraServiceInterface $jira,
        private TelegramMessengerInterface $messenger,
        private ReplyKeyboardService $replyKeyboard,
        private ?TaskOwnerService $ownerService = null,
    ) {
        $this->ownerService = $ownerService ?? app(TaskOwnerService::class);
    }

    /** @param  array<string, mixed>  $data */
    public function finalizeReport(int $chatId, array $data, int $telegramUserId, ?int $messageThreadId = null): void
    {
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $menuExtra = array_merge($extra, $this->replyKeyboard->mainMenu($telegramUserId, $chatId));

        $type = ReportType::tryFrom((string) ($data['type'] ?? ''));

        if ($type === null) {
            Log::warning('Report finalized with an unknown type', ['type' => $data['type'] ?? null]);
            $this->messenger->sendMessage($chatId, ReportsMessageResource::invalidType(), $menuExtra);

            return;
        }

        $description = trim((string) ($data['description'] ?? ''));

        if (mb_strlen($description) < self::MIN_DESCRIPTION_LENGTH) {
            $this->messenger->sendMessage($chatId, __('reports.description_too_short'), $menuExtra);

            return;
        }

        try {
            $created = $this->createReport($data, $telegramUserId);
        } catch (RuntimeException $e) {
            Log::error('Report creation failed: '.$e->getMessage());
            $this->messenger->sendMessage($chatId, ReportsMessageResource::createdError(), $menuExtra);

            return;
        }

        $issueKey = (string) ($created['key'] ?? '');

        $timelineRow = $issueKey !== ''
            ? [[['text' => __('keyboard.button.task_timeline', ['key' => $issueKey]), 'callback_data' => CallbackData::timeline($issueKey)]]]
            : [];

        $this->messenger->sendMessage($chatId, ReportsMessageResource::created($created, $data), array_merge(
            $extra,
            $this->replyKeyboard->mainMenu($telegramUserId, $chatId, $timelineRow),
        ));
    }

    /**
     * Creates the Jira issue for a validated report (type and description already
     * checked by the caller), applies severity/impact, category and owner, and
     * returns the issue as Jira now has it. Shared by the bot and the Mini App.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws RuntimeException when Jira rejects the issue
     */
    public function createReport(array $data, int $telegramUserId): array
    {
        $type = ReportType::from((string) $data['type']);
        $description = trim((string) ($data['description'] ?? ''));
        $projectKey = strtoupper((string) ($data['project'] ?? ''));
        $reporter = $this->linkedReporter($telegramUserId);
        $assignee = $data['assignee'] ?? null;

        $issue = $this->createIssue($projectKey, $type, $description, $data, $reporter, $assignee);
        $issueKey = (string) ($issue['key'] ?? '');

        if ($issueKey !== '') {
            $this->applySelectFields($issueKey, $data, $this->pendingSelectFields($data));
            if (($data['type'] ?? '') === ReportType::UserBug->value) {
                $this->applyCategoryField($issueKey, $data['category'] ?? null);
            }
            try {
                $this->ownerService?->syncOwnerForIssue($issueKey);
            } catch (Throwable $e) {
                Log::warning("Could not auto-sync owner for {$issueKey} after report creation: ".$e->getMessage());
            }
        }

        return $this->jira->getIssue($issueKey) ?? $issue;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function createIssue(string $projectKey, ReportType $type, string $description, array $data, ?string $reporter, ?string $assignee = null): array
    {
        $summary = $this->buildSummary($type, $data);
        $body = $this->buildDescription($type, $description, $data);
        $extraFields = $this->buildExtraFields($data);

        try {
            return $this->jira->createIssue($projectKey, $summary, $type->issueType(), $body, $assignee, $reporter, $extraFields);
        } catch (RuntimeException $e) {
            if ($type === ReportType::UserBug && str_contains($e->getMessage(), 'HTTP 400')) {
                Log::warning('Bug issue type rejected, retrying report as Task');

                return $this->jira->createIssue($projectKey, $summary, config('services.jira.default_issue_type'), $body, $assignee, $reporter, $extraFields);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildExtraFields(array $data): array
    {
        $extra = [];

        // Priority on reports disabled — uncomment to restore
        // (also re-add "use App\Enums\TaskPriority;").
        // if (($priority = TaskPriority::tryFrom((string) ($data['priority'] ?? ''))) !== null) {
        //     $extra['priority'] = ['name' => $priority->value];
        // }

        $customFields = config('services.jira.custom_fields');

        // Impact and Severity are Jira select fields resolved against the
        // created issue's edit metadata — applied after creation (see
        // applySelectFields), since option ids require an existing issue.
        foreach (['impact' => TaskImpact::class, 'severity' => TaskSeverity::class] as $dataKey => $enumClass) {
            $value = $data[$dataKey] ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            if ($enumClass::tryFrom($value) === null) {
                Log::warning("Report {$dataKey} omitted: unmappable value '{$value}'");
            }
        }

        return $extra;
    }

    /**
     * Select-type fields chosen in the report, as data-key => Jira field name.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function pendingSelectFields(array $data): array
    {
        $customFields = config('services.jira.custom_fields');
        $pending = [];

        foreach (['impact' => TaskImpact::class, 'severity' => TaskSeverity::class] as $dataKey => $enumClass) {
            if (is_string($data[$dataKey] ?? null) && $enumClass::tryFrom($data[$dataKey]) !== null) {
                $pending[$dataKey] = $customFields[$dataKey];
            }
        }

        return $pending;
    }

    /**
     * Apply the staged impact/severity selections to a freshly created
     * issue. Failures are logged per field and never fail the report.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $pendingSelectFields  data-key => Jira field name
     */
    private function applySelectFields(string $issueKey, array $data, array $pendingSelectFields): void
    {
        foreach ($pendingSelectFields as $dataKey => $fieldName) {
            $fieldId = $this->jira->customFieldId($fieldName);

            if ($fieldId === null) {
                Log::info("Report {$dataKey} omitted: field not defined on the Jira instance");

                continue;
            }

            $optionId = $this->jira->optionIdForCode($issueKey, $fieldId, (string) $data[$dataKey]);

            if ($optionId === null) {
                Log::warning("Report {$dataKey} omitted: option for '{$data[$dataKey]}' not found on the Jira instance");

                continue;
            }

            try {
                $payload = $dataKey === 'impact' ? [['id' => $optionId]] : ['id' => $optionId];

                $this->jira->updateIssueField($issueKey, $fieldId, $payload);
            } catch (Throwable $e) {
                Log::error("Report {$dataKey} could not be applied to {$issueKey}: ".$e->getMessage());
            }
        }
    }

    private function linkedReporter(int $telegramUserId): ?string
    {
        if ($telegramUserId <= 0) {
            return null;
        }

        return JiraUser::where('telegram_admin_id', $telegramUserId)->value('jira_account_id');
    }

    private function buildSummary(ReportType $type, array $data): string
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

    /** @param  array<string, mixed>  $data */
    private function buildDescription(ReportType $type, string $description, array $data): string
    {
        $body = 'h3. '.__('reports.jira.description_heading')."\n{$description}";

        if ($type !== ReportType::UserBug) {
            return $body;
        }

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

    private function ltrValue(string $value): string
    {
        return preg_match('/[\x{0600}-\x{06FF}]/u', $value) === 1 ? $value : '"'.$value.'"';
    }

    private function applyCategoryField(string $issueKey, mixed $categoryOptionId): void
    {
        if ($categoryOptionId === null || $categoryOptionId === '' || $categoryOptionId === 'skip') {
            return;
        }

        $fieldName = (string) config('services.jira.custom_fields.category', 'Ticket Category');
        $fieldId = $this->jira->customFieldId($fieldName);

        if ($fieldId === null) {
            return;
        }

        try {
            $this->jira->updateIssueField($issueKey, $fieldId, ['id' => (string) $categoryOptionId]);
        } catch (Throwable $e) {
            try {
                $this->jira->updateIssueField($issueKey, $fieldId, [['id' => (string) $categoryOptionId]]);
            } catch (Throwable $e2) {
                Log::error("Category could not be applied to {$issueKey}: ".$e2->getMessage());
            }
        }
    }
}
