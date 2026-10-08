<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramGroup extends Model
{
    protected $fillable = [
        'chat_id',
        'is_forum',
    ];

    protected function casts(): array
    {
        return [
            'is_forum' => 'boolean',
        ];
    }

    /** @return HasMany<AllowedTopic, $this> */
    public function allowedTopics(): HasMany
    {
        return $this->hasMany(AllowedTopic::class, 'group_id');
    }
}
