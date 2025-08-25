<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TelegramWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $update = $request->all();

        // Tambahkan mapping option index ke label
        $optionLabels = [
            0 => 'Masjid',
            1 => 'Basecamp',
        ];

        // Kalau ada vote baru
        if (isset($update['poll_answer'])) {
            $pollId = $update['poll_answer']['poll_id'];
            $user = $update['poll_answer']['user']['first_name'];

            // Bisa jadi user batalin vote → array kosong
            $optionIndex = $update['poll_answer']['option_ids'][0] ?? null;
            $optionLabel = $optionIndex !== null ? $optionLabels[$optionIndex] : null;

            // Simpan ke file JSON
            $filename = "polls/{$pollId}.json";
            $data = Storage::exists($filename) ? json_decode(Storage::get($filename), true) : [];
            $data['answers'][] = [
                'user' => $user,
                'option' => $optionLabel, // <-- sudah berupa "Masjid" / "Basecamp"
                'time' => now()->toDateTimeString(),
            ];
            Storage::put($filename, json_encode($data, JSON_PRETTY_PRINT));
        }

        // Kalau poll selesai
        if (isset($update['poll'])) {
            $pollId = $update['poll']['id'];
            $summary = $update['poll']['options'];
            Storage::put("polls/{$pollId}_summary.json", json_encode($summary, JSON_PRETTY_PRINT));
        }

        return response()->json(['ok' => true]);
    }
}
