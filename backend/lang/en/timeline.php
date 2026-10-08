<?php

return [
    // The timeline report is always rendered in English (see TimelineResource).
    'report' => [
        'title' => '📊 :key · Timeline',
        'now' => '📍 Now: :status · ⏳ :duration',
        'now_no_duration' => '📍 Now: :status',
        'assignee' => '👤 Assignee: :assignee',
        'owner' => '👑 Owner: :owner',
        'created' => '🗓️ Created: :date by :author (:ago)',
        'last_change' => '🕒 Last change: :change · :ago',
        'last_status' => '🔄 moved to :status by :author',
        'last_assignee' => '👤 assigned to :assignee by :author',
        'last_owner' => '👑 owner set to :owner by :author',
        'last_comment' => '💬 comment by :author',
        'changes' => '🔢 Changes: 🔄 :status status · 👥 :assignee assignee · 👑 :owner owner · 💬 :comment comments',
        'first_response' => '⏱️ First response: :duration after creation',
        'journey' => '━━━━ 🧭 Journey ━━━━',
        'block' => '✅ :n. :status · ⏱️ :duration',
        'block_current' => '🟢 :n. :status · ⏱️ :duration (current)',
        'created_by' => '   🆕 :date · created by :author',
        'moved_by' => '   🔄 :date · moved by :author',
        'assignee_change' => '   👤 :from ➜ :to',
        'owner_change' => '   👑 Owner: :from ➜ :to',
        'activity_by' => '      🕒 :date · by :author',
        'comment' => '   💬 :date · :author (+:gap)',
        'comment_body' => '      “:body”',
        'unassigned' => 'Unassigned',
        'unknown' => 'unknown',
        'no_changes' => 'ℹ️ No status, assignee, owner or comment activity yet.',
        'ago' => ':duration ago',
    ],

    'duration' => [
        'just_now' => 'just now',
        'minutes' => ':countm',
        'hours' => ':counth',
        'hours_minutes' => ':hoursh :minutesm',
        'days' => ':countd',
        'days_hours' => ':daysd :hoursh',
    ],

    'menu' => [
        'title' => '📊 Choose how to view the timeline:',
        'by_status' => '📋 By Status',
        'by_key' => '🔑 By Task Key',
        'enter_key_prompt' => "Please enter the task key:\n(e.g. TS-32 or 32)",
    ],
];
