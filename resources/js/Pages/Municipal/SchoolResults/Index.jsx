import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const STATUS_LABELS = { winner: 'Победитель', prize_winner: 'Призёр', participant: 'Участник' };
const STATUS_TONE = { winner: 'text-green-700', prize_winner: 'text-indigo-700', participant: 'text-gray-500' };
const fmt = (n) => (n == null || n === '' ? '—' : String(n).replace('.', ','));

export default function MunicipalSchoolResults({ olympiad, rows, filters = {}, grade_options = [], school_options = [] }) {
    const { flash = {} } = usePage().props;
    const { errors } = usePage().props;

    const [search, setSearch] = useState(filters.q ?? '');
    const go = (params) =>
        router.get(
            route('municipal.results.school-stage', olympiad.id),
            {
                q: search || undefined,
                grade: filters.grade ?? undefined,
                school: filters.school ?? undefined,
                status: filters.status ?? undefined,
                ...params,
            },
            { preserveState: true, preserveScroll: true },
        );
    const submitSearch = (e) => {
        e.preventDefault();
        go({ page: undefined });
    };

    // Импорт списка приглашённых.
    const [importOpen, setImportOpen] = useState(false);
    const importForm = useForm({ file: null });
    const submitImport = (e) => {
        e.preventDefault();
        importForm.post(route('municipal.results.import-invited', olympiad.id), {
            preserveScroll: true, forceFormData: true,
            onSuccess: () => { setImportOpen(false); importForm.reset('file'); },
        });
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Результаты ШЭ · {olympiad.subject}</h2>}
        >
            <Head title={`Результаты ШЭ · ${olympiad.subject}`} />

            <div className="py-8">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between">
                        <Link href={route('municipal.results.show', olympiad.id)} className="text-sm text-gray-500 hover:underline">
                            ← К составу МЭ
                        </Link>
                    </div>

                    {errors?.file && <div className="rounded bg-amber-50 p-3 text-sm text-amber-700">{errors.file}</div>}
                    {flash.success && (
                        <div className="rounded-lg bg-green-50 p-3 text-sm text-green-800 shadow-sm">
                            {flash.success}
                            {flash.import_skipped?.length > 0 && (
                                <details className="mt-2">
                                    <summary className="cursor-pointer text-green-700">Показать пропущенные строки ({flash.import_skipped.length})</summary>
                                    <ul className="mt-1 list-disc space-y-0.5 pl-5 text-xs text-red-700">
                                        {flash.import_skipped.map((s, i) => <li key={i}>{s}</li>)}
                                    </ul>
                                </details>
                            )}
                        </div>
                    )}

                    {/* Выгрузка / импорт */}
                    <div className="flex flex-wrap items-center gap-3 rounded-lg bg-white p-4 shadow">
                        <a href={route('municipal.results.school-stage-export', olympiad.id)}
                            className="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                            ↓ Выгрузить результаты ШЭ (XLSX)
                        </a>
                        {olympiad.compose_open && (
                            <button onClick={() => { importForm.clearErrors(); setImportOpen(true); }}
                                className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                Импорт приглашённых
                            </button>
                        )}
                        <p className="basis-full text-xs text-gray-400">
                            Выгрузите результаты ШЭ своего АТЕ, в файле <b>оставьте только приглашённых</b> (удалите лишние
                            строки), при необходимости поправьте «Класс участия» — и загрузите файл. Для каждого оставшегося
                            ученика будет создано участие в составе МЭ. Шапку (название и код олимпиады) не меняйте.
                        </p>
                    </div>

                    <div className="overflow-x-auto rounded-lg bg-white shadow">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-3">
                            <h3 className="font-semibold text-gray-800">Результаты школьного этапа ({rows.total})</h3>
                            <div className="flex flex-wrap items-center gap-2">
                                <select value={filters.grade ?? ''} onChange={(e) => go({ grade: e.target.value || undefined, page: undefined })}
                                    className="rounded border-gray-300 text-sm">
                                    <option value="">Класс уч.: все</option>
                                    {grade_options.map((g) => <option key={g} value={g}>{g} класс</option>)}
                                </select>
                                <select value={filters.school ?? ''} onChange={(e) => go({ school: e.target.value || undefined, page: undefined })}
                                    className="max-w-[180px] rounded border-gray-300 text-sm">
                                    <option value="">Школа: все</option>
                                    {school_options.map((s) => <option key={s.id} value={s.id}>{s.short_name}</option>)}
                                </select>
                                <select value={filters.status ?? ''} onChange={(e) => go({ status: e.target.value || undefined, page: undefined })}
                                    className="rounded border-gray-300 text-sm">
                                    <option value="">Статус: все</option>
                                    <option value="winner">Победитель</option>
                                    <option value="prize_winner">Призёр</option>
                                    <option value="participant">Участник</option>
                                </select>
                                <form onSubmit={submitSearch} className="flex gap-2">
                                    <input type="text" value={search} onChange={(e) => setSearch(e.target.value)}
                                        placeholder="Поиск по ФИО" className="w-44 rounded border-gray-300 text-sm" />
                                    <button type="submit" className="rounded bg-gray-200 px-3 py-2 text-sm hover:bg-gray-300">Найти</button>
                                    {filters.q && (
                                        <button type="button" onClick={() => { setSearch(''); go({ q: undefined, page: undefined }); }}
                                            className="rounded px-2 py-2 text-sm text-gray-500 hover:underline">Сброс</button>
                                    )}
                                </form>
                            </div>
                        </div>
                        {rows.data.length === 0 ? (
                            <p className="px-6 py-8 text-center text-sm text-gray-400">
                                {filters.q || filters.grade || filters.school || filters.status
                                    ? 'Ничего не найдено по фильтрам.'
                                    : 'Результатов ШЭ по этому предмету для вашего АТЕ нет.'}
                            </p>
                        ) : (
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <tr>
                                        <th className="px-3 py-3">Ученик</th>
                                        <th className="px-3 py-3">Школа</th>
                                        <th className="px-3 py-3">Кл.</th>
                                        <th className="px-3 py-3">Кл. уч.</th>
                                        <th className="px-3 py-3">Балл ШЭ</th>
                                        <th className="px-3 py-3">Статус</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {rows.data.map((r) => (
                                        <tr key={`${r.student_id}-${r.participation_grade}`} className="hover:bg-gray-50">
                                            <td className="px-3 py-2 font-medium text-gray-800">
                                                {r.fio}
                                                {r.prev_municipal_winner && (
                                                    <span className="ml-2 rounded bg-indigo-100 px-1.5 py-0.5 text-xs text-indigo-700">призёр МЭ пр. года</span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-gray-500">{r.school ?? '—'}</td>
                                            <td className="px-3 py-2 text-gray-600">{r.real_grade}</td>
                                            <td className="px-3 py-2 text-gray-600">{r.participation_grade}</td>
                                            <td className="px-3 py-2 font-medium">{fmt(r.score)}</td>
                                            <td className={`px-3 py-2 ${STATUS_TONE[r.result_status] ?? 'text-gray-500'}`}>
                                                {STATUS_LABELS[r.result_status] ?? r.result_status}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        {rows.links?.length > 3 && (
                            <div className="flex flex-wrap gap-1 border-t px-6 py-3">
                                {rows.links.map((link, i) => (
                                    <button key={i} disabled={!link.url}
                                        onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })}
                                        className={`rounded px-3 py-1 text-sm ${
                                            link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'
                                        } ${!link.url ? 'opacity-40' : ''}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }} />
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {importOpen && (
                <div className="fixed inset-0 z-30 flex items-center justify-center bg-black/40 p-4" onClick={() => setImportOpen(false)}>
                    <div className="w-full max-w-lg rounded-lg bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                        <form onSubmit={submitImport} className="space-y-4 p-6">
                            <h3 className="font-semibold text-gray-800">Импорт списка приглашённых</h3>
                            <p className="text-xs text-gray-500">
                                Файл <b>XLSX/ODS</b> (или CSV) — обычно выгрузка ШЭ, в которой оставлены только приглашённые.
                                Каждая строка добавляется в состав МЭ по ID ученика и «Классу участия». Код олимпиады в шапке
                                сверяется; чужие ученики, неверный класс и уже добавленные пропускаются.
                            </p>
                            <input type="file" accept=".xlsx,.ods,.csv,.txt"
                                onChange={(e) => importForm.setData('file', e.target.files[0] ?? null)}
                                className="block w-full text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm" />
                            {importForm.errors.file && <p className="text-xs text-red-600">{importForm.errors.file}</p>}
                            <div className="flex justify-end gap-2">
                                <button type="button" onClick={() => setImportOpen(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                                <button type="submit" disabled={importForm.processing || !importForm.data.file}
                                    className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">Загрузить</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
