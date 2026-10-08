<?php

return [
    'admin_only' => '⛔ این بخش فقط برای ادمین‌های ربات قابل دسترسی است.',

    'pick_type' => '📋 نوع گزارش را انتخاب کنید:',

    'pick_category' => '📁 دسته‌بندی مورد نظر را انتخاب کنید:',

    'pick_user_ref_type' => '🎯 نوع اطلاعات کاربر را انتخاب کنید:',

    'user_ref_type' => [
        'phone' => '📱 شماره تلفن',
        'username' => '👤 نام کاربری',
        'identifier' => '🪪 شناسه کاربر',
    ],

    'type' => [
        'user_bug' => '🪲 گزارش مشکل / باگ کاربر',
        'admin_panel_problem' => '🖥️ گزارش مشکل پنل مدیریت',
        'force_majeure' => '⚡ نیازمندی فورس ماژور',
    ],

    'enter' => [
        // Optional user-info fields; the admin may fill one, two, or all —
        // upheld by the staging step that requires at least one field.
        'user_name' => '👤 نام کاربری کاربر را وارد کنید (در صورت وجود):',
        'user_identifier' => '🪪 شناسه کاربر (نام کاربری / کد ملی / کد مشتری) را وارد کنید (در صورت وجود):',
        'user_mobile' => '📱 شماره موبایل کاربر را وارد کنید (در صورت وجود):',
        'description' => '📝 شرح کامل مشکل/نیازمندی را وارد کنید:',
    ],

    'user_info_review' => [
        'title' => '👤 اطلاعات کاربر واردشده:',
        'line' => ':label: :value',
        'empty' => '—',
    ],

    'user_info_staging' => [
        'field_filled' => ':label — :value ✓',
        'field_empty' => ':label',
        'confirm' => '✅ تایید و ادامه',
        'prompt' => '⬇️ :label را وارد کنید:',
    ],

    'user_info_required' => '⚠️ حداقل یکی از اطلاعات کاربر (نام کاربری / شناسه / شماره موبایل) را وارد کنید.',

    // 🕰️ ایجاد در جیرا: :jira_created — not shown (commented per request).
    'created' => "✅ گزارش با موفقیت در جیرا ثبت شد!\n\n🔑 :key\n🏷️ :summary\n━━━━━━━━━━━━━━━━\n📋 نوع: :type\n🟢 وضعیت: :status:category\n👑 مالک: :owner\n👤 گزارش‌دهنده: :reporter\n📅 زمان ثبت: :jalaali:extra",
    'created_link' => '🔗 :url',
    'created_user_field' => ':label: :value',
    'created_assignee' => '👤 مسئول: :assignee',
    'created_description' => "━━━━━━━━━━━━━━━━\n📝 شرح گزارش:\n:description",

    'invalid_type' => 'نوع گزارش نامعتبر است، لطفاً دوباره تلاش کنید.',
    'description_too_short' => 'شرح خیلی کوتاه است، لطفاً کامل‌تر توضیح دهید.',
    'force_majeure_warning' => '⚠️ شامل توسعه‌ها یا درخواست‌های فوری از سمت مدیران، شرکا یا نهادهای ناظر و ...',

    'jira' => [
        'type' => [
            'user_bug' => 'گزارش مشکل / باگ کاربر',
            'admin_panel_problem' => 'گزارش مشکل پنل مدیریت',
            'force_majeure' => 'نیازمندی فورس ماژور',
        ],
        'description_heading' => 'توضیحات   ',
        'user_info_heading' => 'اطلاعات کاربر',
        'user_name_label' => 'نام کاربری',
        'user_identifier_label' => 'شناسه کاربر',
        'user_mobile_label' => 'شماره موبایل',
        'comment_footer' => '— ثبت‌شده توسط :name از طریق ربات تلگرام',
    ],

    'manage' => [
        'open_header' => '🔓 تسک‌های باز پروژه :project (صفحه :page):',
        'none_open' => 'تسک بازی در این پروژه وجود ندارد.',
        'filter_label' => 'فیلتر بر اساس وضعیت:',
        'action' => [
            'status' => '🔄 تغییر وضعیت',
            'assignee' => '👤 تغییر مسئول',
            'priority' => '🔝 تغییر اولویت',
            'impact' => '💥 تغییر تاثیرگذاری',
            'severity' => '⚡ تغییر شدت مشکل',
            'owner' => '👑 تغییر مالک',
            'category' => '📁 تغییر دسته‌بندی',
            'description' => '📝 تغییر توضیحات',
            'comment' => '💬 افزودن کامنت',
        ],
        'skip' => 'رد کردن',
        'all_open' => '🔓 همه تسک‌های باز',
        'no_statuses' => 'هیچ وضعیتی برای این پروژه یافت نشد.',
        'pick_status_transition' => 'وضعیت جدید را انتخاب کنید:',
        'pick_assignee' => 'مسئول جدید را انتخاب کنید:',
        'pick_category' => 'دسته‌بندی جدید را انتخاب کنید:',
        'no_category_options' => 'هیچ گزینه‌ای برای دسته‌بندی در جیرا یافت نشد.',
        'enter_description' => ':key — توضیحات جدید را وارد کنید:',
        'enter_report_description' => ':key — شرح کامل مشکل/نیازمندی را وارد کنید:',
        'enter_comment' => ':key — متن کامنت را وارد کنید:',
        'comment_too_short' => 'متن کامنت خیلی کوتاه است، لطفاً کامل‌تر بنویسید.',
        'field' => [
            'status' => 'وضعیت',
            'assignee' => 'مسئول',
            'priority' => 'اولویت',
            'impact' => 'تاثیرگذاری',
            'severity' => 'شدت مشکل',
            'owner' => 'مالک',
            'category' => 'دسته‌بندی',
            'description' => 'توضیحات',
            'comment' => 'کامنت',
        ],
        'unavailable' => 'این گزینه در جیرا موجود نیست (فیلد تعریف نشده است).',
        'no_transitions' => 'هیچ گذار وضعیتی برای این تسک مجاز نیست.',
        'confirm' => '✅ تایید',
        'discard' => '🗑️ لغو تغییرات',
        'staged_header' => ':key — تغییرات انتخاب‌شده (با «تایید» اعمال می‌شوند):',
        'nothing_staged' => 'تغییری برای تایید وجود ندارد.',
        'staged_discarded' => '♻️ تغییرات :key لغو شد.',
        'applied_summary' => "🔑 :key — نتیجه اعمال تغییرات:\n:lines",
    ],
];
