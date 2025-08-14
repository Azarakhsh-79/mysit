<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Bots\Amir\AmirBotHandler;
use Telegram\Bot\Laravel\Facades\Telegram;

class AmirWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $bot = Telegram::bot('amir');
        $update = $bot->getWebhookUpdate();

        // کل منطق به سرویس اختصاصی بات واگذار می‌شود
        (new AmirBotHandler($bot))->handle($update);

        return response()->json(['ok' => true]);
    }
}
