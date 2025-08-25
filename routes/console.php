<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dzuhur — Senin–Kamis (skip Jumat)
Schedule::command('send:poll dzuhur')
    ->weekdays()
    ->at('11:42')
    ->timezone('Asia/Jakarta')
    ->when(fn () => ! now('Asia/Jakarta')->isFriday());

// Ashar — Senin–Jumat
Schedule::command('send:poll ashar')
    ->weekdays()
    ->at('13:17')
    ->timezone('Asia/Jakarta');
