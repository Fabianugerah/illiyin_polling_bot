<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Revolution\Google\Sheets\Facades\Sheets;

class TelegramWebhookController extends Controller
{
    /**
     * Handle Telegram Webhook.
     * Menerima pilihan user dari polling Telegram dan menyimpannya ke Google Sheets.
     *
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
            $this->handlePollAnswer($update['poll_answer']);
        }

        // Handle poll update (untuk menyimpan metadata poll)
        if (isset($update['poll'])) {
            $pollId = $update['poll']['id'];
            $summary = $update['poll']['options'];

            // Simpan ringkasan poll ke file JSON (backup)
            Storage::put("polls/{$pollId}_summary.json", json_encode($summary, JSON_PRETTY_PRINT));
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Test Poll Submission.
     * Endpoint untuk testing manual polling tanpa melalui Telegram webhook.
     *
     * @OA\Post(
     * path="/api/telegram/test-poll",
     * summary="Test Poll Submission",
     * description="Endpoint untuk testing manual polling tanpa melalui Telegram webhook. Bisa specify user, option, tanggal, dan waktu sholat.",
     * operationId="testPollSubmission",
     * tags={"Telegram"},
     * @OA\RequestBody(
     * required=true,
     * description="Data polling untuk testing",
     * @OA\JsonContent(
     * type="object",
     * required={"user", "option", "date", "waktu"},
     * @OA\Property(property="user", type="string", example="Fabian", description="Nama user yang voting"),
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
     * example="2025-01-03",
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
     * @OA\Property(property="user", type="string", example="Fabian"),
     * @OA\Property(property="option", type="string", example="Masjid"),
     * @OA\Property(property="date", type="string", example="2025-01-03"),
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
            // Validasi input
            $validated = $request->validate([
                'user' => 'required|string|max:100',
                'option' => 'required|in:Masjid,Basecamp',
                'date' => 'required|date_format:Y-m-d',
                'waktu' => 'required|in:dzuhur,asar'
            ]);

            // Buat mock poll metadata untuk testing
            $pollMetadata = [
                'sent_at' => Carbon::parse($validated['date'])->setTimezone('Asia/Jakarta')->toISOString(),
                'waktu' => $validated['waktu'],
                'poll_id' => 'test_' . uniqid(),
                'question' => "Sholat " . ucfirst($validated['waktu']) . " di Masjid (Testing)",
                'options' => ['Masjid', 'Basecamp']
            ];

            // Simpan ke JSON (optional untuk testing)
            $this->saveToJson($pollMetadata['poll_id'], $validated['user'], $validated['option']);

            // Panggil method utama untuk update Google Sheets
            $updateResult = $this->updateGoogleSheets($validated['user'], $validated['option'], $pollMetadata);

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil disimpan ke Google Sheets',
                'data' => array_merge($validated, $updateResult)
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 400);
        } catch (\Exception $e) {
            Log::error("Error in test poll: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Handles a poll answer from Telegram.
     *
     * @param array $pollAnswer
     */
    private function handlePollAnswer($pollAnswer)
    {
        $pollId = $pollAnswer['poll_id'];
        $userName = $pollAnswer['user']['first_name'] ?? 'Unknown';
        $optionIndex = $pollAnswer['option_ids'][0] ?? null;

        // Ambil metadata poll untuk mengetahui tanggal dan waktu sholat
        $pollMetadata = $this->getPollMetadata($pollId);
        if (!$pollMetadata) {
            Log::warning("Poll metadata tidak ditemukan untuk poll_id: {$pollId}");
            return;
        }

        $options = $pollMetadata['options'] ?? ['Masjid', 'Basecamp'];
        $choice = $options[$optionIndex] ?? 'Unknown';

        // Simpan ke JSON (backup)
        $this->saveToJson($pollId, $userName, $choice);

        // Update Google Sheets
        try {
            $this->updateGoogleSheets($userName, $choice, $pollMetadata);
        } catch (\Exception $e) {
            Log::error("Error updating Google Sheets from webhook: " . $e->getMessage());
        }
    }

    /**
     * Retrieves poll metadata from a daily JSON file.
     *
     * @param string $pollId
     * @return array|null
     */
    private function getPollMetadata($pollId)
    {
        $dailyFile = "polls/" . Carbon::now('Asia/Jakarta')->toDateString() . ".json";

        if (!Storage::exists($dailyFile)) {
            Log::warning("Daily poll file not found: {$dailyFile}");
            return null;
        }

        $dailyData = json_decode(Storage::get($dailyFile), true);

        foreach ($dailyData as $pollData) {
            if ($pollData['poll_id'] === $pollId) {
                return $pollData;
            }
        }

        return null;
    }

    /**
     * Saves poll answers to a JSON file.
     *
     * @param string $pollId
     * @param string $userName
     * @param string $choice
     */
    private function saveToJson($pollId, $userName, $choice)
    {
        $filename = "polls/{$pollId}.json";
        $data = Storage::exists($filename) ? json_decode(Storage::get($filename), true) : ['answers' => []];

        $data['answers'][] = [
            'user' => $userName,
            'option' => $choice,
            'time' => now()->toDateTimeString(),
        ];

        Storage::put($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Updates Google Sheets with the poll result.
     *
     * @param string $userName
     * @param string $choice
     * @param array $pollMetadata
     * @return array
     * @throws \Exception
     */
    private function updateGoogleSheets($userName, $choice, $pollMetadata)
    {
        $spreadsheetId = env('GOOGLE_SHEET_ID');
        if (!$spreadsheetId) {
            throw new \Exception('GOOGLE_SHEET_ID not configured.');
        }

        // Parse tanggal poll (fallback ke now jika parse gagal)
        try {
            $pollDate = Carbon::parse($pollMetadata['sent_at'])->timezone('Asia/Jakarta');
        } catch (\Exception $e) {
            Log::warning('Cannot parse poll sent_at, fallback to now: ' . ($pollMetadata['sent_at'] ?? 'null'));
            $pollDate = Carbon::now('Asia/Jakarta');
        }

        // Mapping bulan ke nama tab (uppercase, bahasa Indonesia)
        $monthMap = [
            '01' => 'JANUARI',
            '02' => 'FEBRUARI',
            '03' => 'MARET',
            '04' => 'APRIL',
            '05' => 'MEI',
            '06' => 'JUNI',
            '07' => 'JULI',
            '08' => 'AGUSTUS',
            '09' => 'SEPTEMBER',
            '10' => 'OKTOBER',
            '11' => 'NOVEMBER',
            '12' => 'DESEMBER'
        ];

        $monthNumber = $pollDate->format('m'); // "09"
        $monthName   = $monthMap[$monthNumber] ?? strtoupper($pollDate->locale('id')->isoFormat('MMMM'));
        $yearName    = $pollDate->format('Y'); // "2025"

        Log::info("Selecting sheet tab for poll date", ['date' => $pollDate->toDateString(), 'month' => $monthName, 'year' => $yearName]);

        $sheetsApi = Sheets::spreadsheet($spreadsheetId);

        // Ambil daftar sheet yang tersedia (bila memungkinkan)
        $availableSheets = [];
        try {
            $availableSheets = $sheetsApi->sheetList() ?: [];
        } catch (\Exception $e) {
            Log::warning("Unable to list sheets: " . $e->getMessage());
        }

        // Kandidat preferensi: bulan dulu, lalu tahun
        $candidates = [$monthName, $yearName];

        $sheetName = null;
        $allData = null;

        // 1) Cari nama tab yang match di availableSheets (case-insensitive)
        foreach ($candidates as $candidate) {
            foreach ($availableSheets as $avail) {
                if (strtoupper(trim($avail)) === strtoupper(trim($candidate))) {
                    $sheetName = $avail; // gunakan nama persis dari sheetList()
                    break 2;
                }
            }
        }

        // 2) Jika belum ketemu lewat sheetList(), coba akses langsung candidate (ini untuk kasus sheetList gagal)
        if ($sheetName === null) {
            foreach ($candidates as $candidate) {
                try {
                    $tmp = $sheetsApi->sheet($candidate)->all();
                    if (!empty($tmp)) {
                        $sheetName = $candidate;
                        $allData = $tmp;
                        break;
                    }
                } catch (\Exception $e) {
                    // ignore, coba candidate berikutnya
                }
            }
        }

        // 3) Jika kita sudah menemukan sheetName dari sheetList tadi, ambil datanya
        if ($sheetName !== null && $allData === null) {
            try {
                $allData = $sheetsApi->sheet($sheetName)->all();
            } catch (\Exception $e) {
                Log::warning("Failed to read sheet '{$sheetName}': " . $e->getMessage());
                $allData = null;
            }
        }

        // 4) Jika belum menemukan sheet yang cocok, berhenti dan beri tahu (lebih aman daripada mengambil sheet lain)
        if ($sheetName === null || empty($allData)) {
            $msg = "Tidak bisa menemukan tab untuk bulan '{$monthName}' atau tahun '{$yearName}'. "
                . "Pastikan tab '{$monthName}' atau '{$yearName}' ada di spreadsheet dan service account memiliki akses.";
            Log::error($msg, ['available_sheets' => $availableSheets]);
            throw new \Exception($msg);
        }

        Log::info("Using sheet tab: {$sheetName}");

        // Cari index user di kolom A
        $userRowIndex = $this->findUserRow($allData, $userName);

        if ($userRowIndex === null) {
            // User belum ada, tambahkan di baris kosong terakhir
            $userRowIndex = $this->addNewUser($spreadsheetId, $sheetName, $userName);
            if ($userRowIndex === null) {
                throw new \Exception("Gagal menambahkan user baru: {$userName}");
            }
        }

        // Tentukan kolom berdasarkan tanggal dan waktu sholat
        $columnResult = $this->findCorrectColumn($pollMetadata, $allData);

        if ($columnResult['index'] === null) {
            throw new \Exception("Kolom tidak ditemukan. Debug info: " . json_encode($columnResult['debug']));
        }

        $columnIndex = $columnResult['index'];
        $columnLetter = $this->getColumnLetter($columnIndex);

        // Jika sheet memakai checkbox, boolean true/false biasanya cocok.
        // Jika tidak, kamu bisa mengganti 'TRUE' atau 'Hadir' sesuai kebutuhan.
        $value = ($choice === 'Masjid') ? true : false;

        // Lakukan update
        $this->updateCell($spreadsheetId, $sheetName, $userRowIndex, $columnIndex, $value);

        Log::info("Updated: {$userName} -> {$choice} di sheet {$sheetName} row {$userRowIndex}, col {$columnLetter}");

        return [
            'row_updated' => $userRowIndex,
            'column_updated' => $columnLetter,
            'value' => $value
        ];
    }


    /**
     * Finds the user's row index in the Google Sheet.
     *
     * @param array $allData
     * @param string $userName
     * @return int|null
     */
    private function findUserRow($allData, $userName)
    {
        foreach ($allData as $index => $row) {
            if (isset($row[0]) && trim(strtolower($row[0])) === trim(strtolower($userName))) {
                return $index + 1; // Google Sheets uses 1-based indexing
            }
        }
        return null;
    }

    /**
     * Adds a new user to the end of the Google Sheet.
     *
     * @param string $spreadsheetId
     * @param string $sheetName
     * @param string $userName
     * @return int|null
     */
    private function addNewUser($spreadsheetId, $sheetName, $userName)
    {
        $allData = Sheets::spreadsheet($spreadsheetId)->sheet($sheetName)->all();

        $lastUserRowIndex = null;

        foreach ($allData as $rowIndex => $row) {
            if (!empty($row[0])) {
                $cellValue = strtolower(trim($row[0]));
                // Skip baris summary
                if (
                    str_contains($cellValue, 'total member') ||
                    str_contains($cellValue, 'jumlah sholat') ||
                    str_contains($cellValue, 'total sholat') ||
                    str_contains($cellValue, 'toleransi')
                ) {
                    break; // stop sebelum summary
                }
                // Baris ini dianggap user terakhir
                $lastUserRowIndex = $rowIndex;
            }
        }

        if ($lastUserRowIndex === null) {
            throw new \Exception("Tidak menemukan baris user untuk menaruh user baru.");
        }

        // Baris baru setelah user terakhir
        $newRowIndex = $lastUserRowIndex + 2; // +1 untuk row selanjutnya, +1 karena index API mulai dari 0

        $range = "{$sheetName}!A{$newRowIndex}";
        Sheets::spreadsheet($spreadsheetId)
            ->sheet($sheetName)
            ->range($range)
            ->update([[$userName]]);

        return $newRowIndex;
    }


    /**
     * Finds the correct column index based on poll metadata.
     *
     * @param array $pollMetadata
     * @param array $allData
     * @return array
     */
    private function findCorrectColumn($pollMetadata, $allData)
    {
        $pollDate = Carbon::parse($pollMetadata['sent_at'])->timezone('Asia/Jakarta');
        $waktuSholat = strtolower($pollMetadata['waktu']);

        $targetDate = $pollDate->locale('id')->isoFormat('D MMMM'); // contoh "1 Agustus"
        $targetDay  = (string) $pollDate->day;

        // Baris tanggal (row index 1 = baris ke-2 di sheet)
        $dateRow = $allData[1] ?? [];
        // Baris subheader (row index 2 = baris ke-3 di sheet)
        $subHeaderRow = $allData[2] ?? [];

        $debugInfo = [
            'target_date'  => $targetDate,
            'target_day'   => $targetDay,
            'waktu_sholat' => $waktuSholat,
            'date_row'     => $dateRow,
            'subheaders'   => $subHeaderRow,
        ];

        // Cari kolom tanggal
        $dateColumnIndex = null;
        foreach ($dateRow as $colIndex => $headerValue) {
            $headerValue = trim($headerValue);
            if (
                stripos($headerValue, $targetDate) !== false ||
                preg_match('/\b' . $targetDay . '\b/', $headerValue)
            ) {
                $dateColumnIndex = $colIndex;
                break;
            }
        }

        if ($dateColumnIndex === null) {
            $debugInfo['error'] = "Tanggal tidak ditemukan di baris 2";
            return ['index' => null, 'debug' => $debugInfo];
        }

        // Cari kolom subheader Dzuhur/Asar tepat di bawah tanggal
        $targetColumn = null;
        foreach ($subHeaderRow as $colIndex => $subHeader) {
            if ($colIndex >= $dateColumnIndex && strtolower(trim($subHeader)) === $waktuSholat) {
                $targetColumn = $colIndex;
                break;
            }
        }

        if ($targetColumn === null) {
            $debugInfo['error'] = "Kolom {$waktuSholat} tidak ditemukan di bawah tanggal";
            return ['index' => null, 'debug' => $debugInfo];
        }

        $debugInfo['target_column'] = $targetColumn;
        Log::info("Column mapping success", $debugInfo);

        return ['index' => $targetColumn, 'debug' => $debugInfo];
    }

    /**
     * Updates a single cell in Google Sheets.
     *
     * @param string $spreadsheetId
     * @param string $sheetName
     * @param int $rowIndex
     * @param int $columnIndex
     * @param mixed $value
     */
    private function updateCell($spreadsheetId, $sheetName, $rowIndex, $columnIndex, $value)
    {
        $columnLetter = $this->getColumnLetter($columnIndex);
        $range = "{$columnLetter}{$rowIndex}";

        Sheets::spreadsheet($spreadsheetId)
            ->sheet($sheetName)
            ->range($range)
            ->update([[$value]]);
    }

    /**
     * Converts a column index (0-based) to a letter (A, B, C...).
     *
     * @param int $columnIndex
     * @return string
     */
    private function getColumnLetter($columnIndex)
    {
        $letters = '';
        while ($columnIndex >= 0) {
            $letters = chr($columnIndex % 26 + 65) . $letters;
            $columnIndex = intval($columnIndex / 26) - 1;
        }
        return $letters;
    }
}
