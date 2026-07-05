<?php

namespace App\Support;

use App\Models\BulkImport;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Общий механизм фонового (по частям) импорта с прогресс-баром: файл разбирается целиком при
 * загрузке (start), затем фронтенд в цикле обрабатывает строки чанками (processChunk) — так
 * избегаем таймаута на массовых загрузках (учащиеся, результаты ШЭ/МЭ и т.п.) без очереди/воркера.
 * Каждый домен передаёт свой $rowHandler с бизнес-логикой одной строки; сам механизм —
 * общий (счётчики, статус, ошибки, выгрузка CSV).
 */
class ChunkedImportService
{
    private const DEFAULT_CHUNK_SIZE = 150;

    /** Сохраняет разобранные строки файла и создаёт запись импорта. */
    public function start(?int $userId, string $type, string $label, array $context, ?array $header, array $rows): BulkImport
    {
        return BulkImport::create([
            'user_id' => $userId,
            'type' => $type,
            'label' => $label,
            'context' => $context,
            'header' => $header,
            'rows' => array_values($rows),
            'total' => count($rows),
        ]);
    }

    /**
     * Обрабатывает очередной чанк строк. $rowHandler(array $row, int $line, ImportResult $result): void
     * Номер строки в исходном файле = context['line_offset'] (по умолчанию 1) + позиция + 1.
     */
    public function processChunk(BulkImport $import, callable $rowHandler, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        if ($import->status !== 'done') {
            $offset = (int) ($import->context['line_offset'] ?? 1);
            $slice = array_slice($import->rows, $import->processed, $chunkSize);
            $result = new ImportResult();
            $startIndex = $import->processed;

            DB::transaction(function () use ($slice, $rowHandler, $result, $offset, $startIndex) {
                foreach ($slice as $i => $row) {
                    $line = $offset + $startIndex + $i + 1;
                    $rowHandler((array) $row, $line, $result);
                }
            });

            $import->created_count += $result->created;
            $import->updated_count += $result->updated;
            $import->skipped_count += $result->skipped;
            $import->failed_count += count($result->failures);
            $import->errors = array_merge($import->errors ?? [], $result->failures);
            $import->processed = min($import->processed + count($slice), $import->total);
            if ($import->processed >= $import->total) {
                $import->status = 'done';
            }
            $import->save();
        }

        return $this->progress($import);
    }

    public function progress(BulkImport $import): array
    {
        return [
            'id' => $import->id,
            'label' => $import->label,
            'total' => $import->total,
            'processed' => $import->processed,
            'created' => $import->created_count,
            'updated' => $import->updated_count,
            'skipped' => $import->skipped_count,
            'failed' => $import->failed_count,
            'done' => $import->status === 'done',
        ];
    }

    /** Выгрузка проблемных строк (исходные ячейки + столбец «Ошибка»). */
    public function errorsCsv(BulkImport $import): StreamedResponse
    {
        $failures = $import->errors ?? [];
        $header = $import->header ?? [];
        $filename = 'import_errors_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($failures, $header) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge($header, ['Ошибка']), ';');
            foreach ($failures as $f) {
                fputcsv($out, array_merge((array) $f['row'], [$f['reason']]), ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
