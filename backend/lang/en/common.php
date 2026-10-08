<?php

return [
    'empty' => [
        'projects' => 'No projects found.',
        'users' => 'No users found.',
        'boards' => 'No boards found.',
        'sprints' => 'No sprints found.',
    ],

    'error' => [
        'jira' => '❌ Error communicating with Jira.',
        'internal' => 'An internal error occurred, please try again.',
        'timeout' => '⏱ The request took too long to complete, please try again.',
        'command_failed' => 'Error running command: :error',
    ],

    'auth' => [
        'not_authorized_user' => '⛔ Only registered admins can use this bot.',
        'not_authorized_group' => '⛔ This group is not registered.'."\n".'Register its ID in TELEGRAM_ALLOWED_GROUP_IDS first.',
        'not_linked' => '⛔ You must first link your Jira account.'."\n".'Use the :link_command command.',
    ],

    'header' => [
        'projects' => 'Jira projects:',
        'users' => 'Jira users:',
        'boards' => 'Boards:',
        'sprints' => 'Sprints:',
    ],

    'welcome' => [
        'greeting' => 'Hello 👋'."\n".'Welcome to the Technical Support Bot.'
        ."\n".'Through this bot, you can submit and track technical issues and requests.',
        'choose' => "\n".'Please select one of the options below:',
        // 'greeting' => '💡 Please select one of the options below:',
        // Old greeting kept for restore.
        // 'greeting' => 'Hello 👋'."\n".'Welcome to the Technical Support Bot.'
        // ."\n".'Through this bot, you can submit and track technical issues and requests.',

        'about' => 'This bot simplifies how admins work with Jira.'."\n \n \n".'Developed by : Ali-Un',
    ],

    'help' => [
        'text' => '⚠️ Note: you must first link your Jira account',
        // The text command list was removed — the inline menu below is the command list.
        // Restore the individual commands by uncommenting the matching entries.
        // 'text' => "Bot commands:\n"
        //     ."\n"
        //     ."/start — start\n"
        //     ."/help — help\n"
        //     ."/about — about the bot\n"
        //     ."/link <EMAIL> — link your Jira account\n"
        //     ."\n"
        //     ."/projects — list projects\n"
        //     // Tasks / Task details disabled — uncomment to restore.
        //     // ."/tasks — tasks of the default project\n"
        //     // ."/tasks <PROJECT KEY> — tasks of a project\n"
        //     // ."/task <TASK KEY> — task details\n"
        //     ."\n"
        //     // Assign / Unassign / Create task disabled — uncomment to restore.
        //     // ."/assign <TASK> <EMAIL> — assign a task\n"
        //     // ."/unassign <TASK> — remove assignment\n"
        //     // ."/create <PROJECT> <SUMMARY> — create a task\n"
        //     ."\n"
        //     ."/boards — list boards\n"
        //     // Sprints disabled — uncomment to restore.
        //     // ."/sprints <ID> — list sprints\n"
        //     // Push to sprint / Change status disabled — uncomment to restore.
        //     // ."/push <TASK> <SPRINT ID> — add to sprint\n"
        //     // .'/status <TASK> <STATUS> — change status'
        //     ."\n\nAdmin:\n"
        //     ."/report — register a problem / bug / force majeure requirement\n"
        //     .'/managetasks — browse and edit open tasks',
    ],

    'fallback' => [
        'user_name' => 'there',
    ],

    'topic' => [
        'allowed' => '✅ Topic «:name» enabled.',
        'disallowed' => '❌ Topic «:name» disabled.',
        'disallowed_last' => 'Topic restriction removed. Bot is active in all topics.',
        'list_header' => 'Allowed topics:',
        'list_empty' => 'No restrictions set — bot is active in all topics.',
        'list_item' => ':id — :name',
        'name_unknown' => '(name unknown)',
        'name_set' => '✅ Topic name changed to «:name».',
        'name_args_error' => 'Usage: /set_topic_name <new name>',
        'name_not_allowed' => 'This topic is not in the allowed list. Enable it first with /allow_topic.',
        'not_forum' => 'This command can only be used in forum groups.',
        'not_admin' => 'Only group admins can manage topics.',
    ],
];
