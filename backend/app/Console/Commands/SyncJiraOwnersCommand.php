<?php

namespace App\Console\Commands;

use App\Services\Jira\TaskOwnerService;
use Illuminate\Console\Command;

class SyncJiraOwnersCommand extends Command
{
    protected $signature = 'jira:sync-owners
                            {--jql= : Custom JQL query to select issues}
                            {--limit=100 : Maximum issues to process}';

    protected $description = 'Automatically sync Jira issue Owner field based on configured status mappings';

    public function handle(TaskOwnerService $ownerService): int
    {
        $jqlOption = $this->option('jql');
        $jql = is_string($jqlOption) && trim($jqlOption) !== '' ? trim($jqlOption) : null;
        $limit = (int) $this->option('limit');

        $this->info('Starting Jira issue owner synchronization...');

        if (! $ownerService->hasOwnerField()) {
            $fieldName = (string) config('services.jira.custom_fields.owner', 'Owner');
            $this->warn("Owner custom field ('{$fieldName}') was not found on Jira. Skipping.");

            return self::SUCCESS;
        }

        $result = $ownerService->syncOwnersBatch($jql, $limit);

        $this->table(
            ['Total Inspected', 'Updated', 'Skipped', 'Errors'],
            [[$result['total'], $result['updated'], $result['skipped'], count($result['errors'])]]
        );

        if (! empty($result['errors'])) {
            $this->newLine();
            $this->error('Errors encountered:');
            foreach ($result['errors'] as $error) {
                $this->line("  ✘ {$error}");
            }

            return self::FAILURE;
        }

        $this->info('Jira issue owner synchronization completed successfully.');

        return self::SUCCESS;
    }
}
