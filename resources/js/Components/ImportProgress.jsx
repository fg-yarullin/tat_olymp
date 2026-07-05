/** Прогресс-бар фонового импорта (см. useChunkedImport) + сводка и выгрузка ошибок по завершении. */
export default function ImportProgress({ progress, error, errorsHref, onReset }) {
    if (!progress && !error) return null;

    const pct = progress && progress.total > 0 ? Math.round((progress.processed / progress.total) * 100) : (progress?.done ? 100 : 0);

    return (
        <div className="space-y-2">
            {progress && (
                <>
                    <div className="h-3 w-full overflow-hidden rounded bg-gray-200">
                        <div className={`h-full ${progress.done ? 'bg-green-600' : 'bg-indigo-600'} transition-all`} style={{ width: `${pct}%` }} />
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-600">
                        <span>{progress.done ? 'Готово' : 'Обработка…'} {progress.processed} из {progress.total} ({pct}%)</span>
                        <span>
                            добавлено {progress.created}, обновлено {progress.updated}
                            {progress.skipped > 0 ? `, без данных ${progress.skipped}` : ''}
                            {progress.failed > 0 ? `, с ошибками ${progress.failed}` : ''}
                        </span>
                    </div>
                    {progress.done && (
                        <div className="flex flex-wrap items-center gap-3 pt-1">
                            {progress.failed > 0 && errorsHref && (
                                <a href={errorsHref} className="rounded bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-700">
                                    ↓ Скачать строки с ошибками ({progress.failed})
                                </a>
                            )}
                            {onReset && <button type="button" onClick={onReset} className="text-xs text-indigo-600 hover:underline">Загрузить ещё файл</button>}
                        </div>
                    )}
                </>
            )}
            {error && <p className="text-sm text-red-600">{error}</p>}
        </div>
    );
}
