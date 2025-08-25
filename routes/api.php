<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramBotController;
use App\Http\Controllers\TelegramWebhookController;

Route::post('/telegram/send-poll', [TelegramBotController::class, 'sendPoll']);

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handleWebhook']);
