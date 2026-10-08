<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class CallbackDebounce
{
    public static function run(string $scope, int $userId, string $data, int $ttlSeconds, callable $action): bool
    {
        $lock = Cache::lock($scope.'_'.$userId.'_'.sha1($data), $ttlSeconds);

        if (! $lock->get()) {
            return false;
        }

        try {
            $action();
        } finally {
            $lock->release();
        }

        return true;
    }
}
