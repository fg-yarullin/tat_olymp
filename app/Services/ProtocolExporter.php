<?php

namespace App\Services;

use App\Models\ProtocolTemplate;
use App\Support\ProtocolSources;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Строит XLSX-протокол по шаблону конструктора. Если у колонок задан групповой
 * заголовок — шапка двухуровневая (группы В1…В6 сливаются в верхней строке).
 */
class ProtocolExporter
{
    /** @param Collection<int, \App\Models\HumanOlympiad> $participations */
    public function build(ProtocolTemplate $template, Collection $participations): Spreadsheet
    {
        $columns = $template->columns->values();
        $hasGroups = $columns->contains(fn ($c) => $c->group_header !== null);
        $headerRows = $hasGroups ? 2 : 1;
        $lastCol = Coordinate::stringFromColumnIndex(max(1, $columns->count()));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Протокол');

        $this->writeHeader($sheet, $columns, $hasGroups);

        $row = $headerRows + 1;
        $n = 1;
        foreach ($participations as $ho) {
            foreach ($columns as $i => $col) {
                $letter = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValueExplicit(
                    "{$letter}{$row}",
                    ProtocolSources::resolve($col->source_key, $ho, $n),
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                );
            }
            $row++;
            $n++;
        }

        // Оформление шапки
        $sheet->getStyle("A1:{$lastCol}{$headerRows}")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastCol}{$headerRows}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        $sheet->getStyle("A1:{$lastCol}{$headerRows}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        foreach (range(1, max(1, $columns->count())) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    private function writeHeader($sheet, Collection $columns, bool $hasGroups): void
    {
        foreach ($columns as $i => $col) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            if (! $hasGroups) {
                $sheet->setCellValue("{$letter}1", $col->header);

                continue;
            }
            if ($col->group_header !== null) {
                $sheet->setCellValue("{$letter}1", $col->group_header);
                $sheet->setCellValue("{$letter}2", $col->header);
            } else {
                // Колонка без группы занимает обе строки шапки.
                $sheet->setCellValue("{$letter}1", $col->header);
                $sheet->mergeCells("{$letter}1:{$letter}2");
            }
        }

        if ($hasGroups) {
            $this->mergeGroups($sheet, $columns);
        }
    }

    /** Сливает в верхней строке подряд идущие колонки с одинаковой группой. */
    private function mergeGroups($sheet, Collection $columns): void
    {
        $count = $columns->count();
        $i = 0;
        while ($i < $count) {
            $group = $columns[$i]->group_header;
            if ($group === null) {
                $i++;

                continue;
            }
            $j = $i;
            while ($j + 1 < $count && $columns[$j + 1]->group_header === $group) {
                $j++;
            }
            if ($j > $i) {
                $from = Coordinate::stringFromColumnIndex($i + 1).'1';
                $to = Coordinate::stringFromColumnIndex($j + 1).'1';
                $sheet->mergeCells("{$from}:{$to}");
            }
            $i = $j + 1;
        }
    }
}
