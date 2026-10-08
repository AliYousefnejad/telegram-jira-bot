<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllowedTopic extends Model
{
    protected $fillable = [
        'group_id',
        'message_thread_id',
        'topic_name',
    ];

    /** @return BelongsTo<TelegramGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class, 'group_id');
    }
}
