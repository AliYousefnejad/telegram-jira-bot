<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Task messages TechDesk sent to a chat, so they can be edited when the task changes. */
    public function up(): void
    {
        Schema::create('shared_task_messages', function (Blueprint $table) {
            $table->id();
            $table->string('task_key', 32)->index();
            $table->bigInteger('chat_id');
            $table->bigInteger('message_id');
            $table->json('shared_by');
            $table->timestamps();
            $table->unique(['chat_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_task_messages');
    }
};
