<?php

return [
    'admin_only' => '⛔ This section is only available to bot admins.',

    'pick_type' => '📋 Select the report type:',

    'pick_category' => '📁 Select the report category:',

    'pick_user_ref_type' => '🎯 Select the user reference type:',

    'user_ref_type' => [
        'phone' => '📱 Phone number',
        'username' => '👤 Username',
        'identifier' => '🪪 User identifier',
    ],

    'type' => [
        'user_bug' => '🪲 User problem / bug report',
        'admin_panel_problem' => '🖥️ Admin panel problem report',
        'force_majeure' => '⚡ Urgent requirement (regulators, partners, management, ...)',
    ],

    'enter' => [
        // Optional user-info fields; the admin may fill one, two, or all —
        // upheld by the staging step that requires at least one field.
        'user_name' => "👤 Enter the user's username (if known):",
        'user_identifier' => '🪪 Enter the user identifier (username / national ID / customer code) (if known):',
        'user_mobile' => "📱 Enter the user's mobile number (if known):",
        'description' => '📝 Enter the full description of the problem or requirement:',
    ],

    'user_info_review' => [
        'title' => '👤 Entered user information:',
        'line' => ':label: :value',
        'empty' => '—',
    ],

    'user_info_staging' => [
        'field_filled' => ':label — :value ✓',
        'field_empty' => ':label',
        'confirm' => '✅ Confirm & continue',
        'prompt' => '⬇️ Enter :label:',
    ],

    'user_info_required' => '⚠️ Please provide at least one of: username / identifier / mobile number.',

    // 🕰️ Created in Jira: :jira_created — not shown (commented per request).
    'created' => "✅ Report registered successfully in Jira!\n\n🔑 :key\n🏷️ :summary\n━━━━━━━━━━━━━━━━\n📋 Type: :type\n🟢 Status: :status:category\n👑 Owner: :owner\n👤 Reporter: :reporter\n📅 Created: :jalaali:extra",
    'created_link' => '🔗 :url',
    'created_user_field' => ':label: :value',
    'created_assignee' => '👤 Assignee: :assignee',
    'created_description' => "━━━━━━━━━━━━━━━━\n📝 Report description:\n:description",

    'invalid_type' => 'Invalid report type, please try again.',
    'description_too_short' => 'The description is too short, please provide more detail.',

    'force_majeure_warning' => '⚠️ Covers urgent development requests, e.g. from regulators, partners or management.',

    'jira' => [
        'type' => [
            'user_bug' => 'User problem / bug report',
            'admin_panel_problem' => 'Admin panel problem report',
            'force_majeure' => 'Urgent requirement (regulators, partners, management, ...)',
        ],
        'description_heading' => 'Description',
        'user_info_heading' => 'User information',
        'user_name_label' => 'Username',
        'user_identifier_label' => 'User identifier',
        'user_mobile_label' => 'Mobile number',
        'comment_footer' => '— Posted by :name via the Telegram bot',
    ],

    'manage' => [
        'open_header' => '🔓 Open tasks of :project (page :page):',
        'none_open' => 'No open tasks in this project.',
        'filter_label' => 'Filter by status:',
        'action' => [
            'status' => '🔄 Change status',
            'assignee' => '👤 Change assignee',
            'priority' => '🔝 Change priority',
            'impact' => '💥 Change impact',
            'severity' => '⚡ Change severity',
            'owner' => '👑 Change owner',
            'category' => '📁 Change category',
            'description' => '📝 Change description',
            'comment' => '💬 Add comment',
        ],
        'skip' => 'Skip',
        'all_open' => '🔓 All Open Tasks',
        'no_statuses' => 'No statuses were found for this project.',
        'pick_status_transition' => 'Select the new status:',
        'pick_assignee' => 'Select the new assignee:',
        'pick_category' => 'Select the new category:',
        'no_category_options' => 'No category options found in Jira.',
        'enter_description' => ':key — enter the new description:',
        'enter_report_description' => ':key — enter the full description of the problem or requirement:',
        'enter_comment' => ':key — enter the comment text:',
        'comment_too_short' => 'The comment is too short, please write more.',
        'field' => [
            'status' => 'Status',
            'assignee' => 'Assignee',
            'priority' => 'Priority',
            'impact' => 'Impact',
            'severity' => 'Severity',
            'owner' => 'Owner',
            'category' => 'Category',
            'description' => 'Description',
            'comment' => 'Comment',
        ],
        'unavailable' => 'This option is not available on the Jira instance (field not defined).',
        'no_transitions' => 'No status transition is allowed for this task right now.',
        'confirm' => '✅ Confirm',
        'discard' => '🗑️ Discard changes',
        'staged_header' => ':key — staged changes (applied on «Confirm»):',
        'nothing_staged' => 'There are no staged changes to confirm.',
        'staged_discarded' => '♻️ Staged changes of :key were discarded.',
        'applied_summary' => ":key — result of applying the changes:\n:lines",
    ],
];
