<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Revolution\Google\Sheets\Facades\Sheets;

class TelegramWebhookController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/telegram/webhook",
     *     summary="Handle Telegram Webhook",
     *     description="Menerima pilihan user dari polling Telegram dan menyimpannya ke storage.",
     *     operationId="handleTelegramWebhook",
     *     tags={"Telegram"},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Payload polling dari Telegram",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="user", type="string", example="Fabian"),
     *             @OA\Property(
     *                 property="option",
     *                 type="string",
     *                 description="Pilihan tempat",
     *                 enum={"Masjid", "Basecamp"},
     *                 example="Masjid"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Webhook diterima",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="ok", type="boolean", example=true)
     *         )
     *     )
     * )
     */
    public function handleWebhook(Request $request)
    {

        $values = Sheets::spreadsheet(env('GOOGLE_SHEET_ID'))
            ->sheet('JUNI')
            ->all();
            return $values;
        $update = $request->all();

        $update = $request->all();

        if (isset($update['poll_answer'])) {
            $pollId = $update['poll_answer']['poll_id'];
            $user = $update['poll_answer']['user']['first_name'];
            $optionIndex = $update['poll_answer']['option_ids'][0] ?? null;

            // Ambil daftar opsi poll yang sudah tersimpan (dari event 'poll')
            $pollFile = "polls/{$pollId}_summary.json";
            $pollData = Storage::exists($pollFile) ? json_decode(Storage::get($pollFile), true) : [];

            $optionLabel = $optionIndex !== null && isset($pollData[$optionIndex]['text'])
                ? $pollData[$optionIndex]['text']
                : null;

            // Simpan ke file JSON
            $filename = "polls/{$pollId}.json";
            $data = Storage::exists($filename) ? json_decode(Storage::get($filename), true) : [];
            $data['answers'][] = [
                'user' => $user,
                'option' => $optionLabel,
                'time' => now()->toDateTimeString(),
            ];
            Storage::put($filename, json_encode($data, JSON_PRETTY_PRINT));
        }

        // Kalau poll selesai / update poll
        if (isset($update['poll'])) {
            $pollId = $update['poll']['id'];
            $summary = $update['poll']['options'];

            // Simpan ringkasan poll ke file JSON
            Storage::put("polls/{$pollId}_summary.json", json_encode($summary, JSON_PRETTY_PRINT));
        }

        return response()->json(['ok' => true]);
    }
}
