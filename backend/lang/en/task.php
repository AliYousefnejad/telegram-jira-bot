<?php

return [
    'list' => [
        'none' => 'No tasks found (:label).',
        'header' => 'Tasks of project :project',
        'page_label' => 'page',
    ],

    'success' => [
        'created' => '✅ Task :key created.'."\n".'Summary: :summary',
        'assigned' => '✅ Task :key assigned to :name.',
        'unassigned' => '✅ Assignment of task :key removed.',
        'sprint_added' => '✅ Task :key added to sprint :sprint.',
        'status_changed' => '✅ Status of :key changed.',
    ],

    'error' => [
        'assign' => '❌ Error assigning task :key.',
        'status' => '❌ Error changing status of :key.',
        'sprint' => '❌ Error adding :key to sprint.',
        'users_list' => '❌ Error fetching the user list.',
        'statuses_list' => '❌ Error fetching statuses.',
    ],

    'validation' => [
        'not_found' => 'Task :key not found.',
        'no_transitions' => 'No status transitions available for :key.',
        'default_project_missing' => 'Default project is not configured.'."\n".'Use a full task key: :example_command',
        'invalid_project_key' => 'Invalid project key.',
    ],

    'picker' => [
        'user_header' => 'Select the user for task :key:',
        'status_header' => 'Select the new status for :key:',
        'board_header' => 'Select a board for sprinting task :key:',
        'sprint_header' => 'Select the sprint for task :key:',
    ],

    'detail' => [
        'type' => '📋 Type: :value',
        'status' => '🟢 Status: :value',
        'priority' => '🏷️ Priority: :value',
        'severity' => '⚡ Severity: :value',
        'impact' => '💥 Impact: :value',
        'owner' => '👑 Owner: :value',
        'category' => '📁 Category: :value',
        'assignee' => '👤 Assignee: :value',
        'reporter' => '👤 Reporter: :value',
        'created' => '🗓️ Created time: :value',
        'created_jalaali' => '📅 Created: :value',
        'updated' => '🔄 Updated: :value',
        'labels' => '🏷️ Labels: :value',
        'link' => '🔗 Link: :value',
        'report_user' => '👤 User: :value',
        'description' => '📝 Report description:'."\n".':description',
    ],
];
