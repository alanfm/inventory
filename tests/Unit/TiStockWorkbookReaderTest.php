<?php

namespace Acme\Inventory\Tests\Unit;

use Acme\Inventory\Infrastructure\TiStockWorkbookReader;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class TiStockWorkbookReaderTest extends TestCase
{
    public function test_reads_source_tabs_maps_cached_formula_value_and_ignores_derived_report_tab(): void
    {
        $book = new Spreadsheet;
        $issues = $book->getActiveSheet();
        $issues->setTitle('LANÇAMENTOS');
        $issues->fromArray(['Cod.', 'Qtde', 'Categoria', 'Item', 'Descrição do Atentimento', 'Nº OS', 'Data', 'Saldo', 'Status'], null, 'A4');
        $issues->fromArray(['IMP01', null, null, null, 'Troca', '1001', Date::PHPToExcel(new \DateTimeImmutable('2023-05-30')), null, null], null, 'A5');
        $issues->getCell('B5')->setValue('=24+3')->setCalculatedValue(27);

        $entries = $book->createSheet();
        $entries->setTitle('DADOS');
        $entries->fromArray(['Cod.', 'Qtde', 'Categoria', 'Item', 'Descrição', 'Data Entrada', 'V.Unit.', 'V.Total', 'Nº. Processo'], null, 'A4');
        $entries->fromArray(['IMP02', 4, 'Cabos', 'Cabo USB', 'USB 3.0', Date::PHPToExcel(new \DateTimeImmutable('2023-06-01')), 9.5, null, 'NF 123'], null, 'A5');

        $report = $book->createSheet();
        $report->setTitle('Relatório');
        $report->setCellValue('A1', 'Resumo derivado');

        $path = tempnam(sys_get_temp_dir(), 'inventory-v12-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        try {
            $rows = (new TiStockWorkbookReader)->read($path);
            self::assertCount(2, $rows);
            self::assertSame('LANÇAMENTOS', $rows[0]['sheet']);
            self::assertSame('ISSUE', $rows[0]['values']['type']);
            self::assertSame(27, $rows[0]['values']['quantity']);
            self::assertSame('=24+3', $rows[0]['values']['_source_formulas']['quantity']);
            self::assertSame('2023-05-30', $rows[0]['values']['date']);
            self::assertSame('1001', $rows[0]['values']['service_order_number']);
            self::assertSame('ENTRY', $rows[1]['values']['type']);
            self::assertSame('NF 123', $rows[1]['values']['document_number']);
            self::assertSame('2023-06-01', $rows[1]['values']['date']);
        } finally {
            @unlink($path);
        }
    }
}
