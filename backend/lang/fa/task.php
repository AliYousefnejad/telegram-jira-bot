<?php

return [
    'list' => [
        'none' => 'تسکی یافت نشد (:label).',
        'header' => 'تسک‌های پروژه :project',
        'page_label' => 'صفحه',
    ],

    'success' => [
        'created' => '✅ تسک :key ساخته شد.'."\n".'خلاصه: :summary',
        'assigned' => '✅ تسک :key به :name واگذار شد.',
        'unassigned' => '✅ واگذاری تسک :key حذف شد.',
        'sprint_added' => '✅ تسک :key به اسپرینت :sprint اضافه شد.',
        'status_changed' => '✅ وضعیت :key تغییر کرد.',
    ],

    'error' => [
        'assign' => '❌ خطا در واگذاری تسک :key.',
        'status' => '❌ خطا در تغییر وضعیت :key.',
        'sprint' => '❌ خطا در افزودن :key به اسپرینت.',
        'users_list' => '❌ خطا در دریافت لیست کاربران.',
        'statuses_list' => '❌ خطا در دریافت وضعیت‌ها.',
    ],

    'validation' => [
        'not_found' => 'تسک :key پیدا نشد.',
        'no_transitions' => 'هیچ تغییر وضعیتی برای :key موجود نیست.',
        'default_project_missing' => 'پروژه پیش‌فرض تنظیم نشده است.'."\n".'از کلید کامل تسک استفاده کنید: :example_command',
        'invalid_project_key' => 'کلید پروژه نامعتبر است.',
    ],

    'picker' => [
        'user_header' => 'کاربر مورد نظر برای تسک :key را انتخاب کنید:',
        'status_header' => 'وضعیت جدید برای :key را انتخاب کنید:',
        'board_header' => 'بورد مورد نظر برای اسپرینت :key را انتخاب کنید:',
        'sprint_header' => 'اسپرینت مورد نظر برای تسک :key را انتخاب کنید:',
    ],

    'detail' => [
        'type' => '📋 نوع: :value',
        'status' => '🟢 وضعیت: :value',
        'priority' => '🏷️ اولویت: :value',
        'severity' => '⚡ شدت: :value',
        'impact' => '💥 اثر: :value',
        'owner' => '👑 مالک: :value',
        'category' => '📁 دسته‌بندی: :value',
        'assignee' => '👤 گیرنده: :value',
        'reporter' => '👤 گزارشگر: :value',
        'created_jalaali' => '📅 تاریخ ثبت: :value',
        'updated' => '🔄 بروزرسانی: :value',
        'labels' => '🏷️ لیبل‌ها: :value',
        'link' => '🔗 پیوند: :value',
        'report_user' => '👤 کاربر: :value',
        'description' => '📝 شرح گزارش:'."\n".':description',
    ],
];
