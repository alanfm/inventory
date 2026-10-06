<?php

namespace Acme\Inventory\Infrastructure;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use ZipArchive;

final class TiStockWorkbookReader
{
    public const VERSION = '1.2.0';

    public const MAX_ROWS = 20_000;

    public const MAX_COLUMNS = 64;

    /** @return list<array{sheet:string,row:int,values:array<string, mixed>}> */
    public function read(string $path): array
    {
        $this->assertSafeArchive($path);
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= TiStockWorkbookReader::MAX_ROWS && Coordinate::columnIndexFromString($columnAddress) <= TiStockWorkbookReader::MAX_COLUMNS;
            }
        });
        $book = $reader->load($path);
        if ($book->getSheetCount() > 20) {
            $book->disconnectWorksheets();
            throw new RuntimeException('A pasta de trabalho excede o limite de abas.');
        }
        $result = [];
        if (in_array('LANÇAMENTOS', $book->getSheetNames(), true) && in_array('DADOS', $book->getSheetNames(), true)) {
            $result = $this->readTiStockV12($book);
        } else {
            foreach ($book->getWorksheetIterator() as $sheet) {
                if ($sheet->getHighestDataRow() > self::MAX_ROWS || Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > self::MAX_COLUMNS) {
                    $book->disconnectWorksheets();
                    throw new RuntimeException('A planilha excede os limites de linhas ou colunas.');
                }
                $this->appendSheet($sheet, $result);
            }
        }
        $book->disconnectWorksheets();

        return $result;
    }

    /** @return list<array{sheet:string,row:int,values:array<string, mixed>}> */
    private function readTiStockV12(Spreadsheet $book): array
    {
        $result = [];
        $sourceColumns = [
            'LANÇAMENTOS' => ['type' => 'ISSUE', 'code' => 'A', 'quantity' => 'B', 'description' => 'E', 'service_order_number' => 'F', 'date' => 'G'],
            'DADOS' => ['type' => 'ENTRY', 'code' => 'A', 'quantity' => 'B', 'category' => 'C', 'name' => 'D', 'description' => 'E', 'date' => 'F', 'cost' => 'G', 'document_number' => 'I'],
        ];

        foreach ($sourceColumns as $sheetName => $columns) {
            $sheet = $book->getSheetByName($sheetName);
            if ($sheet === null) {
                throw new RuntimeException('A aba de origem esperada não existe.');
            }
            if ($sheet->getHighestDataRow() > self::MAX_ROWS || Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > self::MAX_COLUMNS) {
                throw new RuntimeException('A planilha excede os limites de linhas ou colunas.');
            }

            $headerMap = [];
            for ($column = 1; $column <= Coordinate::columnIndexFromString($sheet->getHighestDataColumn()); $column++) {
                $letter = Coordinate::stringFromColumnIndex($column);
                $headerMap[$letter] = $this->normalize((string) $sheet->getCell($letter.'4')->getValue());
            }
            $this->validateSourceHeaders($sheetName, $headerMap);

            for ($row = 5; $row <= $sheet->getHighestDataRow(); $row++) {
                $code = $sheet->getCell($columns['code'].$row)->getValue();
                $quantityCell = $sheet->getCell($columns['quantity'].$row);
                $quantity = $quantityCell->getDataType() === DataType::TYPE_FORMULA ? $quantityCell->getOldCalculatedValue() : $quantityCell->getValue();
                if (($code === null || trim((string) $code) === '') && ($quantity === null || $quantity === '')) {
                    continue;
                }

                $values = ['type' => $columns['type']];
                foreach ($columns as $field => $letter) {
                    if ($field === 'type') {
                        continue;
                    }
                    $cell = $sheet->getCell($letter.$row);
                    $value = $field === 'quantity' ? $quantity : ($cell->getDataType() === DataType::TYPE_FORMULA ? null : $cell->getValue());
                    if ($field === 'date' && is_numeric($value)) {
                        $value = Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
                    }
                    if ($field === 'service_order_number' && $value !== null) {
                        $value = (string) $value;
                    }
                    $values[$field] = is_scalar($value) || $value === null ? $value : null;
                    if ($field === 'quantity' && $quantityCell->getDataType() === DataType::TYPE_FORMULA) {
                        $values['_source_formulas'] = ['quantity' => $quantityCell->getValue()];
                        $values['_formula_cached_quantity'] = $quantity;
                    }
                }

                $raw = [];
                foreach ($headerMap as $letter => $header) {
                    if ($header !== '') {
                        $raw[$header] = $sheet->getCell($letter.$row)->getValue();
                    }
                }
                $values['_source_row'] = $raw;
                $result[] = ['sheet' => $sheetName, 'row' => $row, 'values' => $values];
            }
        }

        return $result;
    }

    /** @param array<string, string> $headers */
    private function validateSourceHeaders(string $sheet, array $headers): void
    {
        $expected = $sheet === 'DADOS'
            ? ['cod', 'qtde', 'categoria', 'item', 'descricao', 'data_entrada', 'v_unit', 'no_processo']
            : ['cod', 'qtde', 'descricao_do_atentimento', 'no_os', 'data'];

        if (array_diff($expected, array_values($headers)) !== []) {
            throw new RuntimeException('Os cabeçalhos da aba '.$sheet.' não correspondem à versão 1.2 esperada.');
        }
    }

    private function assertSafeArchive(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true || $zip->numFiles > 500) {
            throw new RuntimeException('O XLSX está inválido ou contém entradas demais.');
        }
        $expandedBytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                $zip->close();
                throw new RuntimeException('Não foi possível verificar o conteúdo compactado.');
            }
            $entryName = strtolower($stat['name']);
            if (str_contains($entryName, 'vbaproject') || str_contains($entryName, 'externallinks/')) {
                $zip->close();
                throw new RuntimeException('Macros e vínculos externos não são aceitos.');
            }
            $expandedBytes += $stat['size'];
            if ($expandedBytes > 64 * 1024 * 1024 || ($stat['comp_size'] > 0 && $stat['size'] / $stat['comp_size'] > 200)) {
                $zip->close();
                throw new RuntimeException('O XLSX excede os limites seguros de expansão.');
            }
        }
        $zip->close();
    }

    /** @param list<array{sheet:string,row:int,values:array<string, mixed>}> $result */
    private function appendSheet(Worksheet $sheet, array &$result): void
    {
        $highestRow = min($sheet->getHighestDataRow(), self::MAX_ROWS);
        $highestColumn = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), self::MAX_COLUMNS);
        if ($highestRow < 2) {
            return;
        }
        $headers = [];
        for ($column = 1; $column <= $highestColumn; $column++) {
            $headers[] = $this->normalize((string) $sheet->getCell(Coordinate::stringFromColumnIndex($column).'1')->getValue());
        }
        for ($row = 2; $row <= $highestRow; $row++) {
            $values = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $coordinate = Coordinate::stringFromColumnIndex($index + 1).$row;
                $cell = $sheet->getCell($coordinate);
                $value = $cell->getDataType() === DataType::TYPE_FORMULA ? null : $cell->getValue();
                if (is_scalar($value) || $value === null) {
                    $values[$header] = $value;
                }
            }
            if (count(array_filter($values, static fn ($value): bool => $value !== null && $value !== '')) === 0) {
                continue;
            }
            $result[] = ['sheet' => mb_substr($sheet->getTitle(), 0, 120), 'row' => $row, 'values' => $values];
        }
    }

    private function normalize(string $header): string
    {
        $header = mb_strtolower(trim($header));
        $header = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header) ?: $header;
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? '';

        return trim($header, '_');
    }
}
