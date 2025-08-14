<?php



namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramController extends Controller
{
    public function amir(Request $request)
    {
        Telegram::bot('amir')->commandsHandler(true);
        return response()->json(['ok' => true]);
    }

    public function mtr(Request $request)
    {
        Telegram::bot('mtr')->commandsHandler(true);
        return response()->json(['ok' => true]);
    }




    public function handle()
    {
        $update = Telegram::getWebhookUpdate();
        $message = $update->getMessage();
        $chat_id = $message->getChat()->getId();
        $text = $message->getText();

        if ($text === '/start') {
            Telegram::sendMessage([
                'chat_id' => $chat_id,
                'text' => 'سلام! به ربات لاراولی من خوش آمدید.'
            ]);
        } else {
            Telegram::sendMessage([
                'chat_id' => $chat_id,
                'text' => 'شما نوشتید: ' . $text
            ]);
        }

        return response('ok'); 
    }
}