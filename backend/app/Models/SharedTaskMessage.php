<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A task message sent to a Telegram chat from TechDesk ("send to this chat").
 *
 * @property int $id
 * @property string $task_key
 * @property int $chat_id
 * @property int $message_id
 * @property array<string, mixed> $shared_by the Telegram user who sent it (Mini App launch data)
 */
class SharedTaskMessage extends Model
{
    protected $fillable = ['task_key', 'chat_id', 'message_id', 'shared_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['shared_by' => 'array', 'chat_id' => 'integer', 'message_id' => 'integer'];
    }
}
