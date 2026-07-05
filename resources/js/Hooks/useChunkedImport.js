import { usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Общая логика фонового импорта файла по частям с прогресс-баром (без очереди/воркера):
 * загрузка → сервер разбирает файл и возвращает {id,total} → цикл запросов на чанк-эндпоинт,
 * пока не done. Используется везде, где раньше был синхронный импорт файла (учащиеся,
 * результаты ШЭ/МЭ, ввод по шифру и т.п.) — см. серверный `App\Support\ChunkedImportService`.
 *
 * @param {string} startUrl — маршрут запуска импорта (POST, multipart, поле file[+extra])
 * @param {(id:number)=>string} chunkUrl — маршрут обработки чанка по id импорта
 * @param {(id:number)=>string} [errorsUrl] — маршрут выгрузки строк с ошибками по id импорта
 */
export function useChunkedImport({ startUrl, chunkUrl, errorsUrl }) {
    const csrf = usePage().props.csrf_token;
    const [running, setRunning] = useState(false);
    const [error, setError] = useState('');
    const [progress, setProgress] = useState(null);
    const headers = { 'X-CSRF-TOKEN': csrf };

    const run = async (file, extra = {}) => {
        if (!file) return;
        setError('');
        setRunning(true);
        setProgress(null);
        try {
            const fd = new FormData();
            fd.append('file', file);
            Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
            const { data } = await window.axios.post(startUrl, fd, { headers });
            let prog = { id: data.id, total: data.total, processed: 0, created: 0, updated: 0, failed: 0, done: data.total === 0 };
            setProgress(prog);
            while (!prog.done) {
                const res = await window.axios.post(chunkUrl(prog.id), {}, { headers });
                prog = res.data;
                setProgress(prog);
            }
        } catch (err) {
            setError(err?.response?.data?.errors?.file?.[0] || err?.response?.data?.message || 'Ошибка импорта');
        } finally {
            setRunning(false);
        }
    };

    const reset = () => { setProgress(null); setError(''); };

    return { run, running, error, progress, reset, errorsHref: progress && errorsUrl ? errorsUrl(progress.id) : null };
}
