<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Telegram\AmirWebhookController;
use App\Http\Controllers\Telegram\MtrWebhookController;

// اگر می‌خوای Secret-Token را چک کنی، میدلور را اضافه کن (در قدم 7 می‌آید).
Route::post('/telegram/amir/webhook', [AmirWebhookController::class, 'handle'])
    ->name('webhook.amir'); // ->middleware('telegram.secret:amir');

Route::post('/telegram/mtr/webhook',  [MtrWebhookController::class, 'handle'])
    ->name('webhook.mtr');  // ->middleware('telegram.secret:mtr');
