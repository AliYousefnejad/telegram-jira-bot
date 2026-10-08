<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sign-in method of a linked account: by Jira email (acts with the bot's token) or by the user's
     * own Personal Access Token (acts as the user). The token is stored encrypted with APP_KEY.
     */
    public function up(): void
    {
        Schema::table('jira_users', function (Blueprint $table) {
            $table->string('auth_method', 10)->default('email')->after('telegram_username');
            $table->text('jira_pat')->nullable()->after('auth_method');
            $table->timestamp('pat_verified_at')->nullable()->after('jira_pat');
        });
    }

    public function down(): void
    {
        Schema::table('jira_users', function (Blueprint $table) {
            $table->dropColumn(['auth_method', 'jira_pat', 'pat_verified_at']);
        });
    }
};
