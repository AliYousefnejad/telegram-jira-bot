<?php

return [
    'empty' => [
        'projects' => 'پروژه‌ای یافت نشد.',
        'users' => 'کاربری یافت نشد.',
        'boards' => 'بوردی یافت نشد.',
        'sprints' => 'اسپرینتی یافت نشد.',
    ],

    'error' => [
        'jira' => '❌ خطا در ارتباط با جیرا.',
        'internal' => 'خطای داخلی رخ داد، لطفاً دوباره تلاش کنید.',
        'timeout' => '⏱ پاسخ بیش از حد طول کشید، لطفاً دوباره تلاش کنید.',
        'command_failed' => 'خطا در اجرای دستور: :error',
    ],

    'auth' => [
        'not_authorized_user' => '⛔ تنها ادمین هایی که ثبت شده اند میتوانند از این ربات استفاده کنند',
        'not_authorized_group' => '⛔ گروه ثبت نشده.'."\n".'ابتدا ایدی گروه را ثبت کنید.',
        'not_linked' => '⛔ شما ابتدا باید حساب جیرای خود را متصل کنید.'."\n".'از دستور :link_command استفاده کنید.',
    ],

    'header' => [
        'projects' => 'پروژه‌های جیرا:',
        'users' => 'کاربران جیرا:',
        'boards' => 'بوردها:',
        'sprints' => 'اسپرینت‌ها:',
    ],

    'welcome' => [
        // 'greeting' => '💡 لطفا یکی از گزینه های زیر را انتخاب کنید:',
        // Old greeting kept for restore.
        'greeting' => 'سلام👋'."\n".'به بات پشتیبانی فنی خوش آمدید.'."\n".'از طریق این بات میتوانید مشکلات و نیازمندی های فنی را ثبت و پیگیری کنید.',
        'choose' => 'لطفا یکی از گزینه های زیر را انتخاب کنید:'."\n",
        'about' => 'این ربات برای ساده سازی ارتباط ادمین ها با جیرا پیاده سازی شده است.'."\n \n \n".'Developed by : Ali-Un',
    ],

    'help' => [
        'text' => '⚠️ توجه ابتدا باید به حساب جیرای خود لینک شوید'."\n".'بعد از لینک شدن با حساب جیرا، میتوانید از آپشن های زیر استفاده کنید:',
        // The text command list was removed — the inline menu below is the command list.
        // Restore the individual commands by uncommenting the matching entries.
        // 'text' => "دستورهای ربات:\n"
        //     ."\n"
        //     ."/start — شروع\n"
        //     ."/help — راهنما\n"
        //     // ."/about — درباره ربات\n"
        //     ."/link <EMAIL> — اتصال حساب جیرا\n"
        //     ."\n"
        //     ."/projects — لیست پروژه‌ها\n"
        //     // Tasks / Task details disabled — uncomment to restore.
        //     // ."/tasks — تسک‌های پروژه پیش‌فرض\n"
        //     // ."/tasks <PROJECT KEY> — تسک‌های یک پروژه\n"
        //     // ."/task <TASK KEY> — جزئیات یک تسک\n"
        //     // ."\n"
        //     // Assign / Unassign / Create task disabled — uncomment to restore.
        //     // ."/assign <TASK> <EMAIL> — واگذاری تسک\n"
        //     // ."/unassign <TASK> — حذف واگذاری\n"
        //     // ."/create <PROJECT> <SUMMARY> — ساخت تسک\n"
        //     // ."\n"
        //     ."/boards — لیست بوردها\n"
        //     // Sprints disabled — uncomment to restore.
        //     // ."/sprints <ID> — لیست اسپرینت‌ها\n"
        //     // Push to sprint / Change status disabled — uncomment to restore.
        //     // ."/push <TASK> <SPRINT ID> — افزودن به اسپرینت\n"
        //     // .'/status <TASK> <STATUS> — تغییر وضعیت'
        //     ."\n"
        //     ."/report —ثبت مشکل / باگ / نیازمندی فورس ماژور\n"
        //     .'/managetasks — مشاهده و ویرایش تسک‌های باز',
    ],

    'fallback' => [
        'user_name' => 'کاربر',
    ],

    'topic' => [
        'allowed' => '✅ تاپیک «:name» فعال شد.',
        'disallowed' => '❌ تاپیک «:name» غیرفعال شد.',
        'disallowed_last' => 'محدودیت تاپیک برداشته شد. ربات در همه تاپیک ها فعال است.',
        'list_header' => 'تاپیک ها فعال:',
        'list_empty' => 'محدودیتی تنظیم نشده — ربات در همه تاپیک ها فعال است.',
        'list_item' => ':id — :name',
        'name_unknown' => '(نام نامشخص)',
        'name_set' => '✅ نام تاپیک به «:name» تغییر یافت.',
        'name_args_error' => 'Usage: /set_topic_name <new name>',
        'name_not_allowed' => 'این تاپیک در لیست مجاز نیست. ابتدا با /allow_topic آن را فعال کنید.',
        'not_forum' => 'این دستور فقط در گروه‌های فروم قابل اجراست.',
        'not_admin' => 'فقط ادمین گروه می‌تواند تاپیک ها را مدیریت کند.',
    ],
];
