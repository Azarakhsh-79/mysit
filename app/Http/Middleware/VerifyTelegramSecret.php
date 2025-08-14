<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyTelegramSecret
{
    public function handle(Request $request, Closure $next, string $botName)
    {
        $header = $request->header('X-Telegram-Bot-Api-Secret-Token');
        $expected = match ($botName) {
            'amir' => env('BOT_AMIR_SECRET'),
            'mtr'  => env('BOT_MTR_SECRET'),
            default => null,
        };

        if ($expected && $header !== $expected) {
            abort(403, 'Invalid Telegram secret token.');
        }

        return $next($request);
    }
}
