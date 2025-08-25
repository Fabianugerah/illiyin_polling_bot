<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class SendTelegramPoll extends Command
{
    protected $signature = 'send:poll {waktu : dzuhur|ashar}';
    protected $description = 'Kirim polling sholat ke Telegram';

    public function handle()
    {
        $waktu = strtolower($this->argument('waktu'));
        if (! in_array($waktu, ['dzuhur','ashar'])) {
            $this->error('Argumen waktu harus dzuhur atau ashar');
            return self::INVALID;
        }

        $tz   = config('app.timezone', 'Asia/Jakarta');
        $now  = Carbon::now($tz);
        $today = Carbon::now()->locale('id')->isoFormat('dddd, D MMMM Y');
        $tanggal = $now->isoFormat('D MMMM Y');

        // Rules: Senin–Jumat saja, skip Dzuhur saat Jumat, (opsional) skip libur —
        if ($now->isWeekend()) {
            $this->info('Weekend — skip.');
            return self::SUCCESS;
        }
        if ($now->isFriday() && $waktu === 'dzuhur') {
            $this->info('Jumat (Jum’atan) — skip Dzuhur.');
            return self::SUCCESS;
        }
        // Optional: cek libur dari file JSON (storage/app/holidays.json)
        if (Storage::exists('holidays.json')) {
            $holidays = json_decode(Storage::get('holidays.json'), true) ?: [];
            if (in_array($now->toDateString(), $holidays, true)) {
                $this->info('Tanggal merah — skip.');
                return self::SUCCESS;
            }
        }

        $token  = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID');

        $question = "Sholat " . ucfirst($waktu) . " di Masjid ( $today )";
        $options  = ["Masjid", "Basecamp"];

        $resp = Http::post("https://api.telegram.org/bot{$token}/sendPoll", [
            'chat_id'      => $chatId,
            'question'     => $question,
            'options'      => json_encode($options),
            'is_anonymous' => false,
        ]);

        if (! $resp->successful()) {
            $this->error('Gagal kirim poll: '.$resp->body());
            return self::FAILURE;
        }

        $data = $resp->json();
        // Simpan meta poll ke file (tanpa DB)
        $payload = [
            'sent_at'    => $now->toIso8601String(),
            'waktu'      => $waktu,
            'chat_id'    => $chatId,
            'message_id' => data_get($data, 'result.message_id'),
            'poll_id'    => data_get($data, 'result.poll.id'),
            'question'   => $question,
            'options'    => $options,
        ];

        // Append ke file JSON per hari
        $file = 'polls/'. $now->toDateString() .'.json';
        $existing = Storage::exists($file) ? json_decode(Storage::get($file), true) : [];
        $existing[] = $payload;
        Storage::put($file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("Poll terkirim ($waktu). poll_id=".($payload['poll_id'] ?? '-'));
        return self::SUCCESS;
    }
}
