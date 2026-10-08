<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowed_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('telegram_groups')->cascadeOnDelete();
            $table->bigInteger('message_thread_id');
            $table->string('topic_name')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'message_thread_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowed_topics');
    }
};
