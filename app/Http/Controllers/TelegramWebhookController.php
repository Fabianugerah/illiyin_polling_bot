<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\TelegramService;


class TelegramWebhookController extends Controller
{
    /**
     * @var TelegramService
     */
    protected $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    /**
     * @OA\Post(
     * path="/api/telegram/webhook",
     * summary="Handle Telegram Webhook",
     * description="Menerima pilihan user dari polling Telegram dan menyimpannya ke Google Sheets.",
     * operationId="handleTelegramWebhook",
     * tags={"Telegram"},
     * @OA\Response(
     * response=200,
     * description="Webhook diterima",
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="ok", type="boolean", example=true)
     * )
     * )
     * )
     */
    public function handleWebhook(Request $request)
    {
        $update = $request->all();

        // Handle poll answer dari user
        if (isset($update['poll_answer'])) {
            $this->telegramService->handlePollAnswer($update['poll_answer']);
        }

        if (isset($update['poll'])) {
            $pollId = $update['poll']['id'];
            $summary = $update['poll']['options'];
            Storage::put("polls/{$pollId}_summary.json", json_encode($summary, JSON_PRETTY_PRINT));
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @OA\Post(
     * path="/api/telegram/test-poll",
     * summary="Test Poll Submission",
     * description="Endpoint untuk testing manual polling. Harus menggunakan Telegram ID sebagai user identifier.",
     * operationId="testPollSubmission",
     * tags={"Telegram"},
     * @OA\RequestBody(
     * required=true,
     * description="Data polling untuk testing",
     * @OA\JsonContent(
     * type="object",
     * required={"user_id", "option", "date", "waktu"},
     * @OA\Property(property="user_id", type="string", example="5459494803", description="Telegram User ID"),
     * @OA\Property(
     * property="option",
     * type="string",
     * description="Pilihan tempat sholat",
     * enum={"Masjid", "Basecamp"},
     * example="Masjid"
     * ),
     * @OA\Property(
     * property="date",
     * type="string",
     * format="date",
     * example="2025-08-06",
     * description="Tanggal polling (YYYY-MM-DD)"
     * ),
     * @OA\Property(
     * property="waktu",
     * type="string",
     * description="Waktu sholat",
     * enum={"dzuhur", "asar"},
     * example="dzuhur"
     * )
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="Test poll berhasil diproses",
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="success", type="boolean", example=true),
     * @OA\Property(property="message", type="string", example="Data berhasil disimpan ke Google Sheets"),
     * @OA\Property(property="data", type="object",
     * @OA\Property(property="user_id", type="string", example="5459494803"),
     * @OA\Property(property="option", type="string", example="Masjid"),
     * @OA\Property(property="date", type="string", example="2025-08-06"),
     * @OA\Property(property="waktu", type="string", example="dzuhur"),
     * @OA\Property(property="row_updated", type="integer", example=5),
     * @OA\Property(property="column_updated", type="string", example="B")
     * )
     * )
     * ),
     * @OA\Response(
     * response=400,
     * description="Validation error",
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="success", type="boolean", example=false),
     * @OA\Property(property="message", type="string", example="Validation failed"),
     * @OA\Property(property="errors", type="object")
     * )
     * ),
     * @OA\Response(
     * response=404,
     * description="User atau kolom tidak ditemukan",
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="success", type="boolean", example=false),
     * @OA\Property(property="message", type="string", example="User tidak ditemukan di Google Sheets")
     * )
     * )
     * )
     */
    public function testPoll(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|string|max:100',
                'option'  => 'required|in:Masjid,Basecamp',
                'date'    => 'required|date_format:Y-m-d',
                'waktu'   => 'required|in:dzuhur,asar'
            ]);

            $pollMetadata = [
                'sent_at'  => Carbon::parse($validated['date'])->setTimezone('Asia/Jakarta')->toISOString(),
                'waktu'    => $validated['waktu'],
                'poll_id'  => 'test_' . uniqid(),
                'question' => "Sholat " . ucfirst($validated['waktu']) . " di Masjid (Testing)",
                'options'  => ['Masjid', 'Basecamp']
            ];

            // Ganti pemanggilan updateGoogleSheets dan saveToJson

            $updateResult = $this->telegramService->updateGoogleSheets($validated['user_id'], $validated['option'], $pollMetadata);
            $userName = $updateResult['user_name'] ?? 'Unknown';

            $this->telegramService->saveToJson($pollMetadata['poll_id'], $validated['user_id'], $userName, $validated['option']);

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil disimpan ke Google Sheets',
                'data'    => array_merge($validated, $updateResult)
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors()
            ], 400);
        } catch (\Exception $e) {
            Log::error("Error in test poll: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage(),
            ], 500);
        }
    }
}
