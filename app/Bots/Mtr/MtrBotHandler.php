<?php

namespace App\Bots\Mtr;

use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;

class MtrBotHandler
{
    public function __construct(private Api $bot) {}

    public function handle(Update $update): void
    {
        $message       = $update->getMessage() ?? $update->getEditedMessage();
        $callbackQuery = $update->getCallbackQuery();

        if ($callbackQuery) {
            $this->bot->answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text'              => 'MTR: callback received.',
            ]);
            return;
        }

        if (!$message) {
            return;
        }

        $chatId = $message->getChat()->getId();
        $text   = (string)($message->getText() ?? '');

        // دستورات اختصاصی mtr
        if ($text === '/start') {
            $this->bot->sendMessage([
                'chat_id' => $chatId,
                'text'    => "MTR Bot: سلام! اینجا مخصوص MTR هست ✅",
            ]);
            return;
        }

        // منطق پیش‌فرض
        $this->bot->sendMessage([
            'chat_id' => $chatId,
            'text'    => "MTR Bot: شما نوشتید → {$text}",
        ]);
    }
}
