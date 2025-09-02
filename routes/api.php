<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramWebhookController;

// Webhook asli dari Telegram
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handleWebhook']);

// Endpoint untuk testing manual via Swagger
Route::post('/telegram/test-poll', [TelegramWebhookController::class, 'testPoll']);
