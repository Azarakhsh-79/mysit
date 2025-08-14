<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramWebhookController extends Controller
{
    public function setAll(Request $request)
    {
        if ($request->query('key') !== env('WEBHOOK_SETUP_KEY')) {
            abort(403, 'Forbidden');
        }

        // چون از طریق مرورگر (همون ngrok) صداش می‌زنی،
        // route() خودش URL کامل با همون دامنه ngrok می‌سازه.
        $amirUrl = route('webhook.amir'); // https://<ngrok>/api/telegram/amir/webhook
        $mtrUrl  = route('webhook.mtr');  // https://<ngrok>/api/telegram/mtr/webhook

        $r1 = Telegram::bot('amir')->setWebhook(['url' => $amirUrl]);
        $r2 = Telegram::bot('mtr')->setWebhook(['url' => $mtrUrl]);

        return response()->json(['status' => 'set', 'amir' => $r1, 'mtr' => $r2]);
    }

    public function deleteAll(Request $request)
    {
        if ($request->query('key') !== env('WEBHOOK_SETUP_KEY')) {
            abort(403, 'Forbidden');
        }

        $r1 = Telegram::bot('amir')->deleteWebhook();
        $r2 = Telegram::bot('mtr')->deleteWebhook();

        return response()->json(['status' => 'deleted', 'amir' => $r1, 'mtr' => $r2]);
    }
}
