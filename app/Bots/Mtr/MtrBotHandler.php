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
            if ($inlineQuery = $update->getInlineQuery()) {
                $botHandler = new BotHandler(
                    $this->bot,
                    $inlineQuery->getFrom()->getId(),
                    $inlineQuery->getQuery(),
                    null,
                    [],
                    $this->botLink 
                );
                $botHandler->handleInlineQuery($inlineQuery->toArray());
            } elseif ($callbackQuery = $update->getCallbackQuery()) {
                $message = $callbackQuery->getMessage();
                $botHandler = new BotHandler(
                    $this->bot,
                    $message?->getChat()->getId(),
                    '',
                    $message?->getMessageId(),
                    $message?->toArray() ?? [],
                    $this->botLink
                );
                $botHandler->handleCallbackQuery($callbackQuery->toArray());
            } elseif ($preCheckout = $update->getPreCheckoutQuery()) {
                $botHandler = new BotHandler(
                    $this->bot,
                    $preCheckout->getFrom()->getId(),
                    null,
                    null,
                    [],
                    $this->botLink
                );
                $botHandler->handlePreCheckoutQuery($update->toArray());
            } elseif ($message = $update->getMessage() ?? $update->getEditedMessage()) {
                $chatId = $message->getChat()->getId();
                $this->bot->sendChatAction(['chat_id' => $chatId, 'action' => 'typing']);

                $botHandler = new BotHandler(
                    $this->bot, 
                    $chatId,
                    (string)($message->getText() ?? $message->getCaption() ?? ''),
                    $message->getMessageId(),
                    $message->toArray(),
                    $this->botLink
                );

                if ($message->getSuccessfulPayment()) {
                    $botHandler->handleSuccessfulPayment($update->toArray());
                } else {
                    $botHandler->handleRequest();
                }
            } else {
                Log::info('MTR: Unhandled update type');
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