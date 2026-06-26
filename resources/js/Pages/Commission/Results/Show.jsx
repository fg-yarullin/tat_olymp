import Modal from '@/Components/Modal';
import ScoreCell from '@/Components/ScoreCell';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const LEVEL_LABELS = { regional: 'Региональный', republican: 'Республиканский' };
const fmt = (n) => (n == null || n === '' ? '—' : String(n).replace('.', ','));
const fmtDateTime = (iso) =>
    new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const sumOf = (obj) =>
    Object.values(obj || {}).reduce((s, v) => {
        const n = parseFloat(String(v ?? '').replace(',', '.'));
        return s + (Number.isNaN(n) ? 0 : n);
    }, 0);

export default function CommissionShow({ olympiad, works, filters = {}, grade_options = [], pgrade_options = [] }) {
    const entryOpen = olympiad.entry_open;
    const questionCount = olympiad.question_count || 0;
    const questions = Array.from({ length: questionCount }, (_, i) => i + 1);

    const [search, setSearch] = useState(filters.q ?? '');
    const go = (params) =>
        router.get(
            route('commission.results.show', olympiad.id),
            { q: search || undefined, grade: filters.grade ?? undefined, pgrade: filters.pgrade ?? undefined, ...params },
            { preserveState: true, preserveScroll: true },
        );
    const submitSearch = (e) => {
        e.preventDefault();
        go({ page: undefined });
    };

    const { flash = {} } = usePage().props;

    // Массовый ввод первичных баллов из CSV «шифр;балл».
    const [importOpen, setImportOpen] = useState(false);
    const importForm = useForm({ file: null });
    const submitImport = (e) => {
        e.preventDefault();
        importForm.post(route('commission.results.import', olympiad.id), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => { setImportOpen(false); importForm.reset('file'); },
        });
    };

    const [row, setRow] = useState(null);
    const form = useForm({ primary_score: '', scores: {} });
    const openEntry = (w) => {
        setRow(w);
        form.clearErrors();
        form.setData({ primary_score: w.primary_score ?? '', scores: { ...(w.question_scores ?? {}) } });
    };
    const submit = (e) => {
        e.preventDefault();
        form.post(route('commission.results.primary', row.id), { preserveScroll: true, onSuccess: () => setRow(null) });
    };
    const rowMax = row ? olympiad.max_scores?.[row.participation_grade] : undefined;

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Проверка работ · {olympiad.subject}</h2>}
        >
            <Head title={`Проверка работ · ${olympiad.subject}`} />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <Link href={route('commission.results.index')} className="text-sm text-gray-500 hover:underline">
                        ← К списку олимпиад
                    </Link>

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

                    <div className="rounded-lg bg-white p-4 text-sm text-gray-600 shadow">
                        <b>{olympiad.subject}</b> · {LEVEL_LABELS[olympiad.level] ?? olympiad.level} уровень · классы {olympiad.grades.join(', ')}
                        <div className="mt-1">
                            {entryOpen ? (
                                <span className="text-green-700">
                                    ввод первичных результатов открыт{olympiad.entry_deadline ? ` до ${fmtDateTime(olympiad.entry_deadline)}` : ''}
                                </span>
                            ) : (
                                <span className="text-red-600">
                                    ввод первичных результатов закрыт{olympiad.entry_deadline ? ` (срок: ${fmtDateTime(olympiad.entry_deadline)})` : ''}
                                </span>
                            )}
                        </div>
                    </div>

                    <div className="overflow-x-auto rounded-lg bg-white shadow">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-3">
                            <div className="flex items-center gap-3">
                                <h3 className="font-semibold text-gray-800">Работы ({works.total})</h3>
                                {entryOpen && (
                                    <>
                                        <a href={route('commission.results.score-template', olympiad.id)}
                                            className="rounded border border-emerald-300 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-50">
                                            ↓ Шаблон баллов (XLSX)
                                        </a>
                                        <button onClick={() => { importForm.clearErrors(); setImportOpen(true); }}
                                            className="rounded bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">
                                            Массовый ввод
                                        </button>
                                    </>
                                )}
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <select value={filters.grade ?? ''} onChange={(e) => go({ grade: e.target.value || undefined, page: undefined })}
                                    className="rounded border-gray-300 text-sm">
                                    <option value="">Класс: все</option>
                                    {grade_options.map((g) => <option key={g} value={g}>{g} класс</option>)}
                                </select>
                                <select value={filters.pgrade ?? ''} onChange={(e) => go({ pgrade: e.target.value || undefined, page: undefined })}
                                    className="rounded border-gray-300 text-sm">
                                    <option value="">Класс уч.: все</option>
                                    {pgrade_options.map((g) => <option key={g} value={g}>{g} класс</option>)}
                                </select>
                                <form onSubmit={submitSearch} className="flex gap-2">
                                    <input type="text" value={search} onChange={(e) => setSearch(e.target.value)}
                                        placeholder="Поиск по шифру" className="w-44 rounded border-gray-300 text-sm" />
                                    <button type="submit" className="rounded bg-gray-200 px-3 py-2 text-sm hover:bg-gray-300">Найти</button>
                                    {filters.q && (
                                        <button type="button" onClick={() => { setSearch(''); go({ q: undefined, page: undefined }); }}
                                            className="rounded px-2 py-2 text-sm text-gray-500 hover:underline">Сброс</button>
                                    )}
                                </form>
                            </div>
                        </div>
                        {works.data.length === 0 ? (
                            <p className="px-6 py-8 text-center text-sm text-gray-400">
                                {filters.q || filters.grade || filters.pgrade ? 'Ничего не найдено по фильтрам.' : 'Зашифрованных работ пока нет.'}
                            </p>
                        ) : (
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <tr>
                                        <th className="px-3 py-3">Шифр</th>
                                        <th className="px-3 py-3">Класс участия</th>
                                        <th className="px-3 py-3">Первичный балл</th>
                                        <th className="px-3 py-3">Макс.</th>
                                        {questionCount > 0 && entryOpen && <th className="px-3 py-3"></th>}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {works.data.map((w) => (
                                        <tr key={w.id} className="hover:bg-gray-50">
                                            <td className="px-3 py-2 font-mono font-medium text-gray-800">{w.cipher}</td>
                                            <td className="px-3 py-2 text-gray-600">{w.participation_grade}</td>
                                            <td className="px-3 py-2 font-medium">
                                                {questionCount > 0
                                                    ? fmt(w.primary_score)
                                                    : <ScoreCell value={w.primary_score} editable={entryOpen}
                                                        url={route('commission.results.primary', w.id)} payloadKey="primary_score" />}
                                            </td>
                                            <td className="px-3 py-2 text-gray-400">{olympiad.max_scores?.[w.participation_grade] != null ? fmt(olympiad.max_scores[w.participation_grade]) : '—'}</td>
                                            {questionCount > 0 && entryOpen && (
                                                <td className="px-3 py-2 text-right">
                                                    <button onClick={() => openEntry(w)} className="text-indigo-600 hover:underline">Балл</button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        {works.links?.length > 3 && (
                            <div className="flex flex-wrap gap-1 border-t px-6 py-3">
                                {works.links.map((link, i) => (
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

            <Modal show={importOpen} onClose={() => setImportOpen(false)} maxWidth="lg">
                <form onSubmit={submitImport} className="space-y-4 p-6">
                    <h3 className="font-semibold text-gray-800">Массовый ввод первичных баллов</h3>
                    <p className="text-xs text-gray-500">
                        Скачайте шаблон баллов (XLSX), заполните колонку <b>Балл</b> и загрузите этот же файл. Балл
                        сопоставляется по шифру с работами вашего АТЕ; код олимпиады в шапке сверяется. Неизвестные
                        шифры, дубли и баллы выше максимума будут пропущены — список покажем после загрузки.
                        {questionCount > 0 && ' Покомандная разбивка при массовой загрузке очищается (балл из файла — итоговый).'}
                    </p>
                    <div>
                        <input type="file" accept=".xlsx,.ods,.csv,.txt"
                            onChange={(e) => importForm.setData('file', e.target.files[0] ?? null)}
                            className="block w-full text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm" />
                        {importForm.errors.file && <p className="mt-1 text-xs text-red-600">{importForm.errors.file}</p>}
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setImportOpen(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button type="submit" disabled={importForm.processing || !importForm.data.file}
                            className="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50">Загрузить</button>
                    </div>
                </form>
            </Modal>

            <Modal show={!!row} onClose={() => setRow(null)} maxWidth="lg">
                {row && (
                    <form onSubmit={submit} className="space-y-4 p-6">
                        <h3 className="font-semibold text-gray-800">Первичный балл — шифр {row.cipher}</h3>
                        <p className="text-xs text-gray-500">Класс участия {row.participation_grade}.</p>
                        {questionCount > 0 ? (
                            <div>
                                <label className="mb-1 block text-xs text-gray-500">Баллы по заданиям{rowMax != null ? ` (сумма ≤ макс. ${fmt(rowMax)})` : ''}</label>
                                <div className="flex flex-wrap gap-2">
                                    {questions.map((n) => (
                                        <div key={n} className="flex items-center gap-1">
                                            <span className="text-xs text-gray-500">№{n}</span>
                                            <input type="text" inputMode="decimal" value={form.data.scores?.[n] ?? ''}
                                                onChange={(e) => form.setData('scores', { ...form.data.scores, [n]: e.target.value.replace(/[^\d.,]/g, '') })}
                                                className="w-16 rounded border-gray-300 text-sm" />
                                        </div>
                                    ))}
                                </div>
                                <p className="mt-1 text-xs text-gray-500">Первичный балл (сумма): <b>{fmt(sumOf(form.data.scores))}</b></p>
                                {form.errors.scores && <p className="text-xs text-red-600">{form.errors.scores}</p>}
                            </div>
                        ) : (
                            <div>
                                <label className="block text-xs text-gray-500">Первичный балл{rowMax != null ? ` (макс. ${fmt(rowMax)})` : ''}</label>
                                <input type="text" inputMode="decimal" value={form.data.primary_score}
                                    onChange={(e) => form.setData('primary_score', e.target.value.replace(/[^\d.,]/g, ''))}
                                    placeholder="напр. 27,5" className="w-full rounded border-gray-300 text-sm" />
                                {form.errors.primary_score && <p className="text-xs text-red-600">{form.errors.primary_score}</p>}
                            </div>
                        )}
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setRow(null)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                            <button type="submit" disabled={form.processing}
                                className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">Сохранить</button>
                        </div>
                    </form>
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
