<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Bots\Mtr\MtrBotHandler;
use Telegram\Bot\Laravel\Facades\Telegram;

class MtrWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $bot = Telegram::bot('mtr');
        $update = $bot->getWebhookUpdate();

        (new MtrBotHandler($bot))->handle($update);

        return response()->json(['ok' => true]);
    }
}
