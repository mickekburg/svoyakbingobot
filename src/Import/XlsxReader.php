<?php

declare(strict_types=1);

namespace App\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;

/** Thin wrapper over PhpSpreadsheet: returns the header row and the data rows keyed by Excel row number. */
class XlsxReader
{
    /**
     * Reads the sheet named "items" if it exists, otherwise the first sheet.
     * Values are raw (numbers stay numbers, formulas are calculated, dates are NOT converted).
     *
     * @return array{header: list<mixed>, rows: array<int, list<mixed>>}
     */
    public function read(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $sheet = $spreadsheet->getSheetByName('items') ?? $spreadsheet->getSheet(0);
        $table = $sheet->toArray(null, true, false, false);

        $header = array_shift($table) ?? [];
        $rows = [];
        foreach ($table as $i => $cells) {
            $rows[$i + 2] = $cells; // header is row 1, first data row is row 2 — same as in Excel
        }

        $spreadsheet->disconnectWorksheets();

        return ['header' => $header, 'rows' => $rows];
    }
}
