<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramWebhookSetupController extends Controller
{
    /**
     * ست‌کردن وبهوک همه ربات‌ها (بدون secret)
     * URL نهایی هر ربات از name روت‌ها ساخته می‌شود:
     *  - amir => route('webhook.amir')  => https://<NGROK>/api/telegram/amir/webhook
     *  - mtr  => route('webhook.mtr')   => https://<NGROK>/api/telegram/mtr/webhook
     */
    public function setAll(Request $request)
    {
        $amirUrl = url()->route('webhook.amir', [], true); // absolute
        $mtrUrl  = url()->route('webhook.mtr',  [], true);

        // احتیاط: اگر به هر دلیلی http شد، به https تبدیل کن
        $amirUrl = preg_replace('#^http://#', 'https://', $amirUrl);
        $mtrUrl  = preg_replace('#^http://#', 'https://', $mtrUrl);

        $results = [];
        $results['amir'] = Telegram::bot('amir')->setWebhook(['url' => $amirUrl,'drop_pending_updates' => true,]);
        $results['mtr']  = Telegram::bot('mtr')->setWebhook( ['url' => $mtrUrl,'drop_pending_updates' => true,]);

        return response()->json(['status' => 'set', 'targets' => compact('amirUrl', 'mtrUrl'), 'results' => $results]);
    }


    /** حذف وبهوک همه ربات‌ها */
    public function deleteAll(Request $request)
    {
        $bots = ['amir', 'mtr'];
        $results = [];
        foreach ($bots as $botName) {
            $results[$botName] = Telegram::bot($botName)->deleteWebhook();
        }

        return response()->json([
            'status'  => 'deleted',
            'results' => $results,
        ]);
    }

    /** نمایش وضعیت وبهوک هر ربات (برای دیباگ سریع) */
    public function info(Request $request)
    {
        $bots = ['amir', 'mtr'];
        $results = [];
        foreach ($bots as $botName) {
            $results[$botName] = Telegram::bot($botName)->getWebhookInfo();
        }

        return response()->json([
            'status'  => 'info',
            'results' => $results,
        ]);
    }
}
