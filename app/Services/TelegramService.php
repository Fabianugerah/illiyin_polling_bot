<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Revolution\Google\Sheets\Facades\Sheets;

class TelegramService
{
    /**
     * Handles a poll answer and updates Google Sheets.
     *
     * @param array $pollAnswer
     */
    public function handlePollAnswer(array $pollAnswer): void
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
        $choice = $options[$optionIndex] ?? 'Tarik Suara';

        $this->saveToJson($pollId, $userName, $choice);

        try {
            $this->updateGoogleSheets($userName, $choice, $pollMetadata);
        } catch (\Exception $e) {
            Log::error("Error updating Google Sheets from webhook: " . $e->getMessage());
        }
    }

    /**
     * A helper method to perform the Google Sheets update logic.
     *
     * @param string $userName
     * @param string $choice
     * @param array $pollMetadata
     * @return array
     * @throws \Exception
     */
    public function updateGoogleSheets(string $userName, string $choice, array $pollMetadata): array
    {
        $spreadsheetId = env('GOOGLE_SHEET_ID');
        if (!$spreadsheetId) {
            throw new \Exception('GOOGLE_SHEET_ID not configured.');
        }

        // Parse tanggal polling dari metadata
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
        $monthName = $monthMap[$pollDate->format('m')] ?? strtoupper($pollDate->locale('id')->isoFormat('MMMM'));
        $yearName = $pollDate->format('Y');

        $sheetsApi = Sheets::spreadsheet($spreadsheetId);
        $availableSheets = $sheetsApi->sheetList() ?: [];
        $candidates = [$monthName, $yearName];
        $sheetName = $this->findCorrectSheetName($candidates, $availableSheets, $sheetsApi);

        if (!$sheetName) {
            $msg = "Tidak bisa menemukan tab untuk bulan '{$monthName}' atau tahun '{$yearName}'.";
            Log::error($msg, ['available_sheets' => $availableSheets]);
            throw new \Exception($msg);
        }

        $allData = $sheetsApi->sheet($sheetName)->all();
        if (empty($allData)) {
            throw new \Exception("Tab '{$sheetName}' kosong atau tidak bisa dibaca.");
        }

        $userRowIndex = $this->findUserRow($allData, $userName);
        if ($userRowIndex === null) {
            $msg = "User '{$userName}' tidak ditemukan di Google Sheets tab '{$sheetName}'.";
            Log::warning($msg, ['user' => $userName, 'sheet' => $sheetName]);
            throw new \Exception($msg);
        }

        $columnResult = $this->findCorrectColumn($pollMetadata, $allData);
        if ($columnResult['index'] === null) {
            throw new \Exception("Kolom tidak ditemukan: " . json_encode($columnResult['debug']));
        }

        $columnIndex = $columnResult['index'];
        $columnLetter = $this->getColumnLetter($columnIndex);
        $value = ($choice === 'Masjid') ? true : false;

        $this->updateCell($spreadsheetId, $sheetName, $userRowIndex, $columnIndex, $value);
        Log::info("Updated: {$userName} -> {$choice} di sheet {$sheetName} row {$userRowIndex}, col {$columnLetter}");

        return [
            'row_updated' => $userRowIndex,
            'column_updated' => $columnLetter,
            'value' => $value
        ];
    }

    /**
     * Saves poll answers to a JSON file.
     */
    public function saveToJson(string $pollId, string $userName, string $choice): void
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
     * Retrieves poll metadata from a daily JSON file.
     */
    private function getPollMetadata(string $pollId): ?array
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
     * Finds the correct sheet name.
     */
    private function findCorrectSheetName(array $candidates, array $availableSheets, $sheetsApi): ?string
    {
        foreach ($candidates as $candidate) {
            foreach ($availableSheets as $avail) {
                if (strtoupper(trim($avail)) === strtoupper(trim($candidate))) {
                    return $avail;
                }
            }
        }

        foreach ($candidates as $candidate) {
            try {
                if (!empty($sheetsApi->sheet($candidate)->all())) {
                    return $candidate;
                }
            } catch (\Exception $e) {
                // ignore
            }
        }
        return null;
    }

    /**
     * Finds the user's row index in the Google Sheet.
     */
    private function findUserRow(array $allData, string $userName): ?int
    {
        foreach ($allData as $index => $row) {
            if (isset($row[0]) && trim(strtolower($row[0])) === trim(strtolower($userName))) {
                return $index + 1;
            }
        }
        return null;
    }

    /**
     * Finds the correct column index based on poll metadata.
     */
    private function findCorrectColumn(array $pollMetadata, array $allData): array
    {
        $pollDate = Carbon::parse($pollMetadata['sent_at'])->timezone('Asia/Jakarta');
        $waktuSholat = strtolower($pollMetadata['waktu']);
        $targetDay = (string) $pollDate->day;

        $dateRow = $allData[1] ?? [];
        $subHeaderRow = $allData[2] ?? [];

        $dateColumnIndex = null;
        foreach ($dateRow as $colIndex => $headerValue) {
            if (preg_match('/\b' . $targetDay . '\b/', trim($headerValue))) {
                $dateColumnIndex = $colIndex;
                break;
            }
        }

        if ($dateColumnIndex === null) {
            return ['index' => null, 'debug' => ['error' => "Tanggal tidak ditemukan."]];
        }

        $targetColumn = null;
        foreach ($subHeaderRow as $colIndex => $subHeader) {
            if ($colIndex >= $dateColumnIndex && strtolower(trim($subHeader)) === $waktuSholat) {
                $targetColumn = $colIndex;
                break;
            }
        }

        return ['index' => $targetColumn, 'debug' => ['target_column' => $targetColumn]];
    }

    /**
     * Updates a single cell in Google Sheets.
     */
    private function updateCell(string $spreadsheetId, string $sheetName, int $rowIndex, int $columnIndex, $value): void
    {
        $columnLetter = $this->getColumnLetter($columnIndex);
        $range = "{$columnLetter}{$rowIndex}";

        Sheets::spreadsheet($spreadsheetId)
            ->sheet($sheetName)
            ->range($range)
            ->update([[$value]]);
    }

    /**
     * Converts a column index to a letter.
     */
    private function getColumnLetter(int $columnIndex): string
    {
        $letters = '';
        while ($columnIndex >= 0) {
            $letters = chr($columnIndex % 26 + 65) . $letters;
            $columnIndex = intval($columnIndex / 26) - 1;
        }
        return $letters;
    }
}
