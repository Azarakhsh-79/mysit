<?php

namespace App\Bots\Mtr;

use App\Bots\Mtr\BotHandler;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;
use Illuminate\Support\Facades\Log;

class MtrBotHandler
{
    private string $botLink;

    public function __construct(private Api $bot)
    {
        $this->botLink = config("telegram.bots.mtr.link");
    }

    public function handle(Update $update): void
    {
        try {
            // --- بازگشت به ساختار اصلی و ساده ---
            $chatId = null;
            $message = null;
            $callbackQuery = null;

            if ($callbackQuery = $update->getCallbackQuery()) {
                $message = $callbackQuery->getMessage();
                $chatId = $message?->getChat()->getId();
            } elseif ($msg = $update->getMessage() ?? $update->getEditedMessage()) {
                $message = $msg;
                $chatId = $message->getChat()->getId();
            }
            // می‌توان انواع دیگر آپدیت را در اینجا اضافه کرد (مانند inline_query)

            if (!$chatId) {
                Log::info('MTR: Could not determine chatId from update.');
                return;
            }

            // ارسال اکشن "typing" فقط در صورتی که پیامی وجود داشته باشد
            if ($message) {
                $this->bot->sendChatAction(['chat_id' => $chatId, 'action' => 'typing']);
            }

            // ساخت BotHandler با همان ورودی‌های ساده قبلی
            $botHandler = new BotHandler(
                $this->bot,
                $chatId, // فقط شناسه چت را ارسال می‌کنیم
                (string)($message?->getText() ?? $message?->getCaption() ?? ''),
                $message?->getMessageId(),
                $message?->toArray() ?? [],
                $this->botLink
            );

            // --- مسیریابی ساده بر اساس نوع آپدیت ---
            if ($callbackQuery) {
                $botHandler->handleCallbackQuery($callbackQuery->toArray());
            } elseif ($message?->getSuccessfulPayment()) {
                $botHandler->handleSuccessfulPayment($update->toArray());
            } elseif ($message) {
                $botHandler->handleRequest();
            }
        } catch (\Throwable $e) {
            Log::error('MTR dispatch error: ' . $e->getMessage(), [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
                'payload' => $update->toArray(),
            ]);
        }
    }
}
