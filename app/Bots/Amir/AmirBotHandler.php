<?php

namespace App\Bots\Amir;

use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;

class AmirBotHandler
{
    public function __construct(private Api $bot) {}

    public function handle(Update $update): void
    {
        $message       = $update->getMessage() ?? $update->getEditedMessage();
        $callbackQuery = $update->getCallbackQuery();

        if ($callbackQuery) {
            $this->bot->answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text'              => 'Amir: callback received.',
            ]);
            return;
        }

        if (!$message) {
            return;
        }

        $chatId = $message->getChat()->getId();
        $text   = (string)($message->getText() ?? '');

        // دستورات اختصاصی amir
        if ($text === '/start') {
            $this->bot->sendMessage([
                'chat_id' => $chatId,
                'text'    => "Amir Bot: خوش اومدی 🌟",
            ]);
            return;
        }

        // منطق پیش‌فرض
        $this->bot->sendMessage([
            'chat_id' => $chatId,
            'text'    => "Amir Bot: شما نوشتید → {$text}",
        ]);
    }
}
