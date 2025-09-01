<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class SendTelegramPoll extends Command
{
    protected $signature = 'send:poll {waktu : dzuhur|asar}';
    protected $description = 'Kirim polling sholat ke Telegram';

    public function handle()
    {
        $waktu = strtolower($this->argument('waktu'));
        if (! in_array($waktu, ['dzuhur','asar'])) {
            $this->error('Argumen waktu harus dzuhur atau asar');
            return self::INVALID;
        }

        $tz   = config('app.timezone', 'Asia/Jakarta');
        $now  = Carbon::now($tz);
        $today = Carbon::now()->locale('id')->isoFormat('dddd, D MMMM Y');

        // Rules: Senin–Jumat saja, skip Dzuhur saat Jumatan
        if ($now->isWeekend()) {
            $this->info('Weekend — skip.');
            return self::SUCCESS;
        }
        if ($now->isFriday() && $waktu === 'dzuhur') {
            $this->info('Jumat (Jum’atan) — skip Dzuhur.');
            return self::SUCCESS;
        }

        $token  = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID');

        if (! $token || ! $chatId) {
            $this->error('Env TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID belum di-set.');
            return self::INVALID;
        }

        // Pastikan folder polls ada
        if (! Storage::exists('polls')) {
            Storage::makeDirectory('polls');
        }

        $question = "Sholat " . ucfirst($waktu) . " di Masjid ( $today )";
        $options  = ["Masjid", "Basecamp"];

        $response = Http::asForm()->post("https://api.telegram.org/bot{$token}/sendPoll", [
            'chat_id'      => $chatId,
            'question'     => $question,
            'options'      => json_encode($options),
            'is_anonymous' => false,
        ]);

        if (! $response->successful()) {
            $this->error('Gagal kirim poll: '.$response->body());
            return self::FAILURE;
        }

        $data = $response->json();
        $pollId = data_get($data, 'result.poll.id');

        // Simpan meta poll ke file summary
        $payload = [
            'sent_at'    => $now->toIso8601String(),
            'waktu'      => $waktu,
            'chat_id'    => $chatId,
            'message_id' => data_get($data, 'result.message_id'),
            'poll_id'    => $pollId,
            'question'   => $question,
            'options'    => $options,
        ];

        // Append ke file JSON per hari
        $file = 'polls/'. $now->toDateString() .'.json';
        $existing = Storage::exists($file) ? json_decode(Storage::get($file), true) : [];
        $existing[] = $payload;
        Storage::put($file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

         // Simpan metadata per-poll (fix: agar webhook bisa menemukan meski hari sudah ganti)
        if ($pollId) {
            Storage::put("polls/meta_{$pollId}.json", json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $this->info("Poll terkirim ($waktu). poll_id=".($payload['poll_id'] ?? '-'));
        return self::SUCCESS;
    }
}
