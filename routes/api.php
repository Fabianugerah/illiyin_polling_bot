<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramWebhookController;

// Webhook asli dari Telegram
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handleWebhook']);

// Endpoint untuk testing manual via Swagger
Route::post('/telegram/test-poll', [TelegramWebhookController::class, 'testPoll']);

// Endpoint untuk debug Google Sheets connection
Route::get('/telegram/debug-sheets', [TelegramWebhookController::class, 'debugSheets']);

// Simple test connection
Route::get('/telegram/simple-test', [TelegramWebhookController::class, 'simpleTest']);

// Debug column mapping
Route::get('/telegram/debug-columns', [TelegramWebhookController::class, 'debugColumnMapping']);
