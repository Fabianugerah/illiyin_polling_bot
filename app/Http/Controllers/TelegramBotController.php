<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class TelegramBotController extends Controller
{
    protected $token;
    protected $chatId;

    public function __construct()
    {
        $this->token = env('TELEGRAM_BOT_TOKEN'); // token dari BotFather
        $this->chatId = env('TELEGRAM_CHAT_ID');  // id group/channel
    }

    /**
     * @OA\Post(
     *     path="/api/telegram/send-poll",
     *     summary="Kirim polling ke Telegram",
     *     tags={"Telegram Bot"},
     *     @OA\Response(
     *         response=200,
     *         description="Polling berhasil dikirim"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Gagal mengirim polling"
     *     )
     * )
     */
    public function sendPoll(string $waktu)
    {
        $today = Carbon::now()->locale('id')->isoFormat('dddd, D MMMM Y');
        $question = "Sholat " . ucfirst($waktu) . " di Masjid ( $today )";
        $options = ["Masjid", "Basecamp"];

        $response = Http::post("https://api.telegram.org/bot{$this->token}/sendPoll", [
            'chat_id' => $this->chatId,
            'question' => $question,
            'options' => json_encode($options),
            'is_anonymous' => false,
        ]);

        return $response->json();
    }
}
