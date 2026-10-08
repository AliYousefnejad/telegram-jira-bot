<?php

namespace App\Http\Controllers;

use App\Services\Telegram\UpdateProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function __construct(private UpdateProcessor $processor) {}

    public function webhook(Request $request): JsonResponse
    {
        $update = $request->attributes->get('telegram_update');

        if ($update !== null) {
            $this->processor->process($update);
        }

        return response()->json(['ok' => true]);
    }
}
