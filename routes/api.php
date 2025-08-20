<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Telegram\AmirWebhookController;
use App\Http\Controllers\Telegram\MtrWebhookController;
use App\Http\Controllers\TelegramWebhookSetupController;

// وبهوک‌های اصلی (دریافت آپدیت‌ها)
Route::post('/telegram/amir/webhook', [AmirWebhookController::class, 'handle'])
    ->name('webhook.amir');

Route::post('/telegram/mtr/webhook', [MtrWebhookController::class, 'handle'])
    ->name('webhook.mtr');

// روت‌های مدیریتی (ست/حذف/وضعیت) — بدون سکرت
Route::get('/telegram/webhooks/set',    [TelegramWebhookSetupController::class, 'setAll']);
Route::get('/telegram/webhooks/delete', [TelegramWebhookSetupController::class, 'deleteAll']);
Route::get('/telegram/webhooks/info',   [TelegramWebhookSetupController::class, 'info']);
