<?php

namespace App\Http\Controllers\Coordinator;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\RatingService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Министерская отчётность (ТЗ 4.5): экспорт ранжированного итогового протокола ОО
 * в Excel (.xlsx) с обязательным раздельным срезом город/село.
 */
class ReportController extends Controller
{
    public function ratingsXlsx(Request $request, RatingService $ratings): StreamedResponse
    {
        $request->validate([
            'year' => ['nullable', 'string', 'regex:/^\d{4}\/\d{4}$/'],
            'territory' => ['nullable', 'in:all,city,rural'],
        ]);

        $year = $request->query('year') ?: ($ratings->availableYears()[0] ?? null);
        $territory = $request->query('territory', 'all');
        $user = $request->user();

        // Координатор АТЕ — только своя территория; супер-координатор/админ — весь срез.
        $rows = $user->role === UserRole::MunicipalCoordinator && $user->ate?->ate_code
            ? $this->filterTerritory($ratings->schoolRatings($user->ate->ate_code, $year), $territory)
            : $ratings->republicSchoolRatings($year, $territory === 'all' ? null : $territory);

        $spreadsheet = $this->build($rows, $year, $territory);

        $filename = 'protokol_'.str_replace('/', '-', (string) $year).'_'.$territory.'.xlsx';

        return $this->streamXlsx($spreadsheet, $filename);
    }

    private function filterTerritory(array $rows, string $territory): array
    {
        if ($territory === 'all') {
            return $rows;
        }

        return array_values(array_filter($rows, fn ($r) => $r['territorial_sign'] === $territory));
    }

    private function build(array $rows, ?string $year, string $territory): Spreadsheet
    {
        $territoryLabel = ['all' => 'Все ОО', 'city' => 'Городские ОО', 'rural' => 'Сельские ОО'][$territory] ?? 'Все ОО';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Протокол');

        $sheet->setCellValue('A1', "Итоговый протокол · $year · $territoryLabel");
        $sheet->mergeCells('A1:G1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $headers = ['Место', 'ОО', 'МСУ', 'Признак', 'Участников', 'Призёров', 'Победителей'];
        $sheet->fromArray($headers, null, 'A3');
        $sheet->getStyle('A3:G3')->getFont()->setBold(true);
        $sheet->getStyle('A3:G3')->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E2E8F0');
        $sheet->getStyle('A3:G3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $territoryNames = ['city' => 'Город', 'rural' => 'Село'];
        $r = 4;
        foreach ($rows as $row) {
            $sheet->fromArray([
                $row['rank'],
                $row['school'],
                $row['msu_code'],
                $territoryNames[$row['territorial_sign']] ?? $row['territorial_sign'],
                $row['participants'],
                $row['prizes'],
                $row['winners'],
            ], null, 'A'.$r);
            $r++;
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    private function streamXlsx(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
