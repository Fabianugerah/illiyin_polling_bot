<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dzuhur — Senin–Kamis (skip Jumat)
Schedule::command('send:poll dzuhur')
    ->weekdays()
    ->at('08:56')
    ->timezone('Asia/Jakarta')
    ->when(fn () => ! now('Asia/Jakarta')->isFriday());

// Asar — Senin–Jumat
Schedule::command('send:poll asar')
    ->weekdays()
    ->at('11:13')
    ->timezone('Asia/Jakarta');
