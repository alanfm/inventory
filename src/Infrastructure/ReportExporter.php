<?php

namespace Acme\Inventory\Infrastructure;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ReportExporter
{
    /** @param list<array<string, mixed>> $rows */
    public function download(array $rows, string $format, string $filename)
    {
        $headers = array_keys($rows[0] ?? []);
        if ($format === 'csv') {
            $stream = fopen('php://temp', 'w+');
            if ($stream === false) {
                throw new \RuntimeException('Não foi possível preparar a exportação.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $headers, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($stream, array_map($this->csvValue(...), array_values($row)), ',', '"', '');
            }
            rewind($stream);
            $contents = stream_get_contents($stream);
            fclose($stream);

            return response($contents === false ? '' : $contents, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'.csv"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $sheet = new Spreadsheet;
        $worksheet = $sheet->getActiveSheet();
        foreach ($headers as $index => $header) {
            $worksheet->setCellValue([$index + 1, 1], $header);
        }
        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $cell = [$columnIndex + 1, $rowIndex + 2];
                if (is_int($value) || is_float($value)) {
                    $worksheet->setCellValue($cell, $value);
                } else {
                    $worksheet->setCellValueExplicit($cell, $value === null ? '' : (string) $value, DataType::TYPE_STRING);
                }
            }
        }
        foreach (range(1, max(1, count($headers))) as $column) {
            $worksheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }
        $path = tempnam(sys_get_temp_dir(), 'inventory-report-');
        if ($path === false) {
            throw new \RuntimeException('Não foi possível preparar a exportação XLSX.');
        }
        try {
            (new Xlsx($sheet))->save($path);
            $contents = file_get_contents($path);
        } finally {
            unlink($path);
            $sheet->disconnectWorksheets();
        }

        return response($contents === false ? '' : $contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.xlsx"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function csvValue(mixed $value): string|int|float
    {
        if (! is_string($value)) {
            return $value ?? '';
        }
        if (preg_match('/^[\s]*[=+\-@\t\r]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
