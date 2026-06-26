<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Наполнение территориальных справочников из CSV (catalog/):
 *   ATE-list.csv  -> ates
 *   MSU-list.csv  -> msus
 *   schools.csv   -> schools
 *
 * Особенности данных:
 *  - АТЕ 54/55/56 «объединённые» (содержат >1 МСУ), тип вычисляется по числу МСУ.
 *  - В schools.csv колонка «АТЕ» у казанских школ содержит устаревшие коды 57/58/59,
 *    поэтому АТЕ школы определяется через её МСУ (msu.ate_*), а не через колонку АТЕ.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $ateRows = $this->readCsv(base_path('catalog/ATE-list.csv'));   // [№, ate_code, name]
        $msuRows = $this->readCsv(base_path('catalog/MSU-list.csv'));   // [№, ate_code, msu_code, name]
        $schoolRows = $this->readCsv(base_path('catalog/schools.csv')); // [№, oo_code, full, short, level, ate, msu, urban]

        // Сколько МСУ ссылается на каждый код АТЕ -> определяет тип (isolated/unified)
        $msuPerAte = [];
        foreach ($msuRows as $r) {
            $msuPerAte[trim($r[1])] = ($msuPerAte[trim($r[1])] ?? 0) + 1;
        }

        // TRUNCATE в MySQL вызывает неявный commit, поэтому очистку делаем вне транзакции.
        Schema::disableForeignKeyConstraints();
        DB::table('schools')->truncate();
        DB::table('msus')->truncate();
        DB::table('ates')->truncate();
        Schema::enableForeignKeyConstraints();

        DB::transaction(function () use ($ateRows, $msuRows, $schoolRows, $msuPerAte) {
            $now = now();

            // --- ATE ---
            $ateInsert = [];
            foreach ($ateRows as $r) {
                $code = trim($r[1]);
                $ateInsert[] = [
                    'ate_code'   => $code,
                    'name'       => trim($r[2]),
                    'type'       => ($msuPerAte[$code] ?? 0) > 1 ? 'unified' : 'isolated',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('ates')->insert($ateInsert);
            $ateIdByCode = DB::table('ates')->pluck('id', 'ate_code')->all();

            // --- MSU ---
            $msuInsert = [];
            foreach ($msuRows as $r) {
                $ateCode = trim($r[1]);
                if (! isset($ateIdByCode[$ateCode])) {
                    throw new RuntimeException("МСУ {$r[2]}: неизвестный код АТЕ {$ateCode}");
                }
                $msuInsert[] = [
                    'msu_code'   => trim($r[2]),
                    'name'       => trim($r[3]),
                    'ate_id'     => $ateIdByCode[$ateCode],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('msus')->insert($msuInsert);

            // Карта МСУ -> {id, ate_id, ate_code} (источник истины для привязки школы к АТЕ)
            $msuMeta = [];
            $ateCodeById = array_flip($ateIdByCode);
            foreach (DB::table('msus')->get(['id', 'msu_code', 'ate_id']) as $m) {
                $msuMeta[$m->msu_code] = [
                    'id'       => $m->id,
                    'ate_id'   => $m->ate_id,
                    'ate_code' => $ateCodeById[$m->ate_id],
                ];
            }

            // --- Schools ---
            $batch = [];
            $count = 0;
            foreach ($schoolRows as $r) {
                if (count($r) < 8) {
                    continue;
                }
                $msuCode = trim($r[6]);
                if (! isset($msuMeta[$msuCode])) {
                    throw new RuntimeException("Школа {$r[1]}: неизвестный код МСУ {$msuCode}");
                }
                $meta = $msuMeta[$msuCode];

                $batch[] = [
                    'oo_code'          => trim($r[1]),
                    'short_name'       => trim($r[3]),
                    'full_name'        => trim($r[2]),
                    'education_level'  => (int) trim($r[4]),
                    'territorial_sign' => trim($r[7]) === '1' ? 'city' : 'rural',
                    'msu_id'           => $meta['id'],
                    'msu_code'         => $msuCode,
                    'ate_id'           => $meta['ate_id'],
                    'ate_code'         => $meta['ate_code'],
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
                $count++;

                if (count($batch) >= 500) {
                    DB::table('schools')->insert($batch);
                    $batch = [];
                }
            }
            if ($batch) {
                DB::table('schools')->insert($batch);
            }

            $this->command?->info("Справочники загружены: АТЕ=".count($ateInsert).", МСУ=".count($msuInsert).", школы={$count}");
        });
    }

    /**
     * Читает CSV целиком, корректно обрабатывая кавычки и многострочные поля.
     * Возвращает строки данных без заголовка.
     *
     * @return list<array<int, string>>
     */
    private function readCsv(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Файл справочника не найден: {$path}");
        }

        $rows = [];
        $handle = fopen($path, 'r');
        fgetcsv($handle); // пропустить заголовок
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === false) {
                continue; // пустая строка
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
