<?php

namespace App\Services;

use Revolution\Google\Sheets\Facades\Sheets;

class TelegramService
{
    protected $sheets;
    protected $spreadsheetId = '1TmsT0ociaSUgNxdVziHl-nkEwQ8LNxhiE5O2G6CXd68'; // ganti dengan ID-mu
    protected $sheetName = 'AGUSTUS'; // tab di spreadsheet

    public function __construct(Sheets $sheets)
    {
        $this->sheets = $sheets;
    }

    /**
     * Update hasil polling ke Google Sheets
     * - Kalau user sudah ada → update pilihannya
     * - Kalau user belum ada → tambahkan baris baru
     */
    public function savePollResult(string $user, string $option): void
    {
        $rows = $this->sheets->spreadsheet($this->spreadsheetId)
            ->sheet($this->sheetName)
            ->all();

        // header
        if (empty($rows)) {
            $rows = [['User', 'Pilihan']];
        }

        $updated = false;
        foreach ($rows as &$row) {
            if (isset($row[0]) && $row[0] === $user) {
                $row[1] = $option;
                $updated = true;
                break;
            }
        }

        if (! $updated) {
            $rows[] = [$user, $option];
        }

        $this->sheets->spreadsheet($this->spreadsheetId)
            ->sheet($this->sheetName)
            ->update($rows);
    }
}
