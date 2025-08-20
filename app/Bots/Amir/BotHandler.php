<?php

declare(strict_types=1);

namespace App\Bots\Amir;

use Telegram\Bot\Api;

use Illuminate\Support\Facades\Log;

class BotHandler
{

    private Api $telegram;
    private string $botLink;

    public function __construct(
        Api $telegram,
        private ?int $chatId,
        private ?string $text,
        private ?int $messageId,
        private ?array $message,
        string $botLink = ''
    ) {
        $this->telegram = $telegram;
        $this->botLink = $botLink;
    }

    public function handleRequest(): void
    {
        $txt = trim($this->text ?? '');

        if ($txt === '/start') {
            $this->sendChatActionTyping();
            return;
        }

        if ($txt === '/help') {
            $this->sendChatActionTyping();
            $this->sendMessage(
                "راهنما:\n" .
                    "• /start — شروع\n" .
                    "• نوشتن هر متن — Echo\n" .
                    "• دکمه راهنما — نمایش این پیام\n" .
                    "• اینلاین: @{$this->botLink} <query>" // << استفاده از لینک تزریق شده
            );
            return;
        }

        $this->sendChatActionTyping();
        //    $res =  $this->telegram->sendMessage(['chat_id' => $this->chatId, 'text' =>"Amir Bot: شما نوشتید → {$txt}"]);
        // Log::warning('result', [$res]);

    }


    public function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackQueryId = $callbackQuery['id'] ?? null;
        $data = (string)($callbackQuery['data'] ?? '');
        $msg  = $callbackQuery['message'] ?? null;

        $this->answerCallback($callbackQueryId, "دریافت شد: {$data}");

        switch ($data) {
            case 'HELP':
                $chatId    = $msg['chat']['id'] ?? $this->chatId;
                $messageId = $msg['message_id'] ?? $this->messageId;

                if ($chatId && $messageId) {
                    $this->editMessageText(
                        (int)$chatId,
                        (int)$messageId,
                        "راهنما:\n• /start — شروع\n• نوشتن هر متن — Echo\n• اینلاین: @{$this->botLink} <query>"
                    );
                }
                break;
                // ... بقیه case ها
        }
    }

    public function handleInlineQuery(array $inlineQuery): void
    {
        $inlineQueryId = (string)($inlineQuery['id'] ?? '');
        $query         = trim((string)($inlineQuery['query'] ?? ''));

        $results = [
            [
                'type' => 'article',
                'id'   => 'echo-1',
                'title' => $query === '' ? 'نوشتن شروع کن…' : "ارسال: {$query}",
                'input_message_content' => [
                    'message_text' => $query === '' ? 'سلام! یه متن بنویس.' : "Echo: {$query}",
                ],
                'description' => 'ارسال پیام echo در چت',
            ],
        ];

        // << بهینه‌سازی جزئی: پکیج خودش json_encode می‌کند
        $this->telegram->answerInlineQuery([
            'inline_query_id' => $inlineQueryId,
            'results'         => $results,
            'cache_time'      => 0,
            'is_personal'     => true,
        ]);
    }

    public function handlePreCheckoutQuery(array $update): void
    {
        $pcq = $update['pre_checkout_query'] ?? null;
        if (!$pcq) return;

        $this->telegram->answerPreCheckoutQuery([
            'pre_checkout_query_id' => (string)$pcq['id'],
            'ok'                    => true,
        ]);
    }

    public function handleSuccessfulPayment(array $update): void
    {
        $message = $update['message'] ?? null;
        if (!$message || !isset($message['chat']['id'])) return;

        $sp = $message['successful_payment'] ?? [];
        $total = isset($sp['total_amount']) ? ((int)$sp['total_amount']) / 100 : 0;
        $currency = $sp['currency'] ?? '—';

        // برای ارسال پیام از متد sendMessage استفاده می‌کنیم تا کد تکراری نشود
        $originalChatId = $this->chatId;
        $this->chatId = $message['chat']['id']; // chatId را موقتا برای این پیام تغییر می‌دهیم
        $this->sendMessage("پرداخت موفق بود ✅\nمبلغ: {$total} {$currency}");
        $this->chatId = $originalChatId; // بازگرداندن به حالت اولیه
    }

    public function sendMessageWithKeyboard(string $text, array $inlineKeyboard): void
    {
        if (!$this->chatId) return;

        $this->telegram->sendMessage([
            'chat_id'      => $this->chatId,
            'text'         => $text,
            // << بهینه‌سازی جزئی: پکیج خودش json_encode می‌کند
            'reply_markup' => ['inline_keyboard' => $inlineKeyboard],
        ]);
    }

    public function sendChatActionTyping(): void
    {
        if (!$this->chatId) return;
        $this->telegram->sendChatAction(['chat_id' => $this->chatId, 'action'  => 'typing']);
    }
    public function sendMessage(string $text): void
    {
        if (!$this->chatId) return;
        $this->telegram->sendMessage(['chat_id' => $this->chatId, 'text'    => $text]);
    }
    public function editMessageText(int $chatId, int $messageId, string $text): void
    {
        $this->telegram->editMessageText(['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text]);
    }
    public function answerCallback(?string $callbackQueryId, string $text, bool $alert = false): void
    {
        if (!$callbackQueryId) return;
        $this->telegram->answerCallbackQuery(['callback_query_id' => $callbackQueryId, 'text' => $text, 'show_alert' => $alert]);
    }
}
