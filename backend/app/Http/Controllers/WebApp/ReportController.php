<?php

namespace App\Http\Controllers\WebApp;

use App\Contracts\JiraServiceInterface;
use App\Enums\ReportType;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebApp\StoreReportRequest;
use App\Http\Resources\WebApp\TaskPayload;
use App\Services\Telegram\Reports\ReportFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ReportController extends Controller
{
    public function __construct(private ReportFlowService $reports) {}

    /** The category options of a new user bug report, read from the project's Jira create screen. */
    public function options(JiraServiceInterface $jira): JsonResponse
    {
        $fieldId = $jira->customFieldId((string) config('services.jira.custom_fields.category', 'Ticket Category'));
        $options = $fieldId !== null
            ? $jira->getCreateFieldAllowedOptions(TaskController::project(), $fieldId, ReportType::UserBug->issueType())
            : [];

        return response()->json(['categories' => array_values(array_map(
            fn (array $option): array => ['id' => (string) ($option['id'] ?? ''), 'value' => (string) ($option['value'] ?? '')],
            array_filter($options, 'is_array'),
        ))]);
    }

    public function store(StoreReportRequest $request): JsonResponse
    {
        $data = array_filter($request->validated(), fn ($value): bool => $value !== null && $value !== '');
        $data['project'] = TaskController::project();

        try {
            $issue = $this->reports->createReport($data, (int) $request->attributes->get('telegram_launch')['user']['id']);
        } catch (RuntimeException $e) {
            Log::error('Mini App report creation failed: '.$e->getMessage());

            return response()->json(['error' => 'jira_failed'], 502);
        }

        return response()->json(['task' => TaskPayload::detail($issue)], 201);
    }
}
