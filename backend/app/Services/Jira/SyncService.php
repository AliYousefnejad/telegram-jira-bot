<?php

namespace App\Services\Jira;

use App\Models\JiraUser;

class SyncService
{
    /**
     * @param  array<int, array<string, mixed>>  $users
     * @return array<int, array<string, mixed>>
     */
    public function syncUserEmails(array $users): array
    {
        foreach ($users as $user) {
            $email = $user['emailAddress'] ?? '';
            $userId = JiraService::userId($user);

            if ($email !== '' && $userId !== '') {
                JiraUser::updateOrCreate(
                    ['jira_account_id' => $userId],
                    [
                        'email' => $email,
                        'display_name' => $user['displayName'] ?? '',
                    ]
                );
            }
        }

        $localEmails = JiraUser::whereNotNull('jira_account_id')
            ->whereNotNull('email')
            ->pluck('email', 'jira_account_id')
            ->toArray();

        return array_map(function (array $user) use ($localEmails): array {
            $email = $user['emailAddress'] ?? '';
            $userId = JiraService::userId($user);

            if ($email === '' && isset($localEmails[$userId])) {
                $user['emailAddress'] = $localEmails[$userId];
            }

            return $user;
        }, $users);
    }
}
