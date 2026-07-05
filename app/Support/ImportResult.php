<?php

namespace App\Support;

/**
 * Результат пакетного импорта: счётчики и список проблемных строк с исходными
 * ячейками (для выгрузки и повторного импорта после исправления).
 */
class ImportResult
{
    public int $created = 0;

    public int $updated = 0;

    /** Строки, намеренно пропущенные без данных (напр. балл ещё не выставлен) — не ошибка. */
    public int $skipped = 0;

    /** @var list<array{line:int, reason:string, row:array<int, mixed>}> */
    public array $failures = [];

    public function fail(int $line, string $reason, array $row): void
    {
        $this->failures[] = ['line' => $line, 'reason' => $reason, 'row' => array_values($row)];
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }
}
