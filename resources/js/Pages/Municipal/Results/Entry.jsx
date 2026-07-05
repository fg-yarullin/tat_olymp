import ColumnsMenu from '@/Components/ColumnsMenu';
import ImportProgress from '@/Components/ImportProgress';
import Modal from '@/Components/Modal';
import ScoreCell from '@/Components/ScoreCell';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useChunkedImport } from '@/Hooks/useChunkedImport';
import { useStoredColumns } from '@/Hooks/useStoredColumns';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const LEVEL_LABELS = { regional: 'Региональный', republican: 'Республиканский' };
const fmt = (n) => (n == null || n === '' ? '—' : String(n).replace('.', ','));
const fmtDateTime = (iso) =>
    new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const sumOf = (obj) =>
    Object.values(obj || {}).reduce((s, v) => {
        const n = parseFloat(String(v ?? '').replace(',', '.'));
        return s + (Number.isNaN(n) ? 0 : n);
    }, 0);

export default function MunicipalResultsEntry({ olympiad, participants, filters = {}, grade_options = [], pgrade_options = [], school_options = [] }) {
    const entryOpen = olympiad.entry_open;
    const appealOpen = olympiad.appeal_open;
    const questionCount = olympiad.question_count || 0;
    const questions = Array.from({ length: questionCount }, (_, i) => i + 1);

    // Опциональные колонки по заданиям (видимость хранится в браузере, по умолчанию скрыты).
    const [cols, toggleCol] = useStoredColumns('cols:municipal-results', { questions: false, appeals: false });
    const colOptions = questionCount > 0
        ? [
            { key: 'questions', label: 'Баллы по заданиям (В1–ВN)' },
            { key: 'appeals', label: 'Апелляции по заданиям' },
        ]
        : [];
    const showQ = questionCount > 0 && cols.questions;
    const showA = questionCount > 0 && cols.appeals;

    const [search, setSearch] = useState(filters.q ?? '');
    const go = (params) =>
        router.get(
            route('municipal.results.entry', olympiad.id),
            {
                q: search || undefined,
                grade: filters.grade ?? undefined,
                pgrade: filters.pgrade ?? undefined,
                school: filters.school ?? undefined,
                ...params,
            },
            { preserveState: true, preserveScroll: true },
        );
    const submitSearch = (e) => {
        e.preventDefault();
        go({ page: undefined });
    };

    // Первичный балл (единым числом или по заданиям).
    const [primaryRow, setPrimaryRow] = useState(null);
    const primaryForm = useForm({ primary_score: '', scores: {} });
    const openPrimary = (p) => {
        setPrimaryRow(p);
        primaryForm.clearErrors();
        primaryForm.setData({ primary_score: p.primary_score ?? '', scores: { ...(p.question_scores ?? {}) } });
    };
    const submitPrimary = (e) => {
        e.preventDefault();
        primaryForm.post(route('municipal.results.primary', primaryRow.id), { preserveScroll: true, onSuccess: () => setPrimaryRow(null) });
    };
    const primaryMax = primaryRow ? olympiad.max_scores?.[primaryRow.participation_grade] : undefined;

    // Добавочный балл по апелляции (единым числом или по заданиям).
    const [appealRow, setAppealRow] = useState(null);
    const appealForm = useForm({ appeal_addition: '', appeals: {} });
    const openAppeal = (p) => {
        setAppealRow(p);
        appealForm.clearErrors();
        appealForm.setData({ appeal_addition: p.appeal_addition ?? '', appeals: { ...(p.question_appeals ?? {}) } });
    };
    const submitAppeal = (e) => {
        e.preventDefault();
        appealForm.post(route('municipal.results.appeal', appealRow.id), { preserveScroll: true, onSuccess: () => setAppealRow(null) });
    };
    const appealMax = appealRow ? olympiad.max_scores?.[appealRow.participation_grade] : undefined;

    // Массовый ввод первичных баллов из XLSX (по частям, с прогресс-баром).
    const [importOpen, setImportOpen] = useState(false);
    const [importFile, setImportFile] = useState(null);
    const scoresImport = useChunkedImport({
        startUrl: route('municipal.results.import-scores', olympiad.id),
        chunkUrl: (id) => route('municipal.results.import-scores.chunk', id),
        errorsUrl: (id) => route('municipal.results.import-scores.errors', id),
    });
    const submitScoresImport = (e) => {
        e.preventDefault();
        scoresImport.run(importFile);
    };
    const closeScoresImport = () => {
        setImportOpen(false);
        scoresImport.reset();
        setImportFile(null);
    };
    // После завершения импорта обновляем список участников — прогресс-бар остаётся виден.
    useEffect(() => {
        if (scoresImport.progress?.done) {
            router.reload({ only: ['participants'] });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [scoresImport.progress?.done]);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Результаты МЭ · {olympiad.subject}</h2>}
        >
            <Head title={`Результаты МЭ · ${olympiad.subject}`} />

            <div className="py-8">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between">
                        <Link href={route('municipal.results.index')} className="text-sm text-gray-500 hover:underline">
                            ← К списку олимпиад
                        </Link>
                        <div className="flex items-center gap-2">
                            {questionCount === 0 && entryOpen && participants.total > 0 && (
                                <>
                                    <a href={route('municipal.results.score-template', olympiad.id)}
                                        className="rounded border border-emerald-300 px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50">
                                        ↓ Шаблон баллов (XLSX)
                                    </a>
                                    <button onClick={() => { scoresImport.reset(); setImportFile(null); setImportOpen(true); }}
                                        className="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                                        Массовый ввод баллов
                                    </button>
                                </>
                            )}
                            {olympiad.has_protocol_template && participants.total > 0 && (
                                <a href={route('municipal.results.protocol', olympiad.id)}
                                    className="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                                    ↓ Протокол МЭ (XLSX)
                                </a>
                            )}
                        </div>
                    </div>

                    {/* Вкладки по олимпиаде */}
                    <div className="flex gap-1 border-b border-gray-200">
                        <Link href={route('municipal.results.show', olympiad.id)}
                            className="-mb-px border-b-2 border-transparent px-4 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">
                            Состав участников
                        </Link>
                        <Link href={route('municipal.results.entry', olympiad.id)}
                            className="-mb-px border-b-2 border-indigo-600 px-4 py-2 text-sm font-medium text-indigo-700">
                            Ввод результатов
                        </Link>
                    </div>

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
                            {appealOpen && (
                                <>
                                    <span className="mx-1">·</span>
                                    <span className="text-green-700">
                                        приём апелляций открыт{olympiad.appeal_deadline ? ` до ${fmtDateTime(olympiad.appeal_deadline)}` : ''}
                                    </span>
                                </>
                            )}
                            {!entryOpen && !appealOpen && <span className="ml-1 text-gray-400">— ввод закрыт</span>}
                        </div>
                        {!entryOpen && (
                            <p className="mt-1 text-xs text-gray-400">
                                Показаны только участники с введённым первичным баллом; не явившиеся скрыты.
                            </p>
                        )}
                    </div>

                    <div className="overflow-x-auto rounded-lg bg-white shadow">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-3">
                            <h3 className="font-semibold text-gray-800">Участники ({participants.total})</h3>
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
                                <select value={filters.school ?? ''} onChange={(e) => go({ school: e.target.value || undefined, page: undefined })}
                                    className="max-w-[180px] rounded border-gray-300 text-sm">
                                    <option value="">Школа: все</option>
                                    {school_options.map((s) => <option key={s.id} value={s.id}>{s.short_name}</option>)}
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
                                <ColumnsMenu options={colOptions} cols={cols} onToggle={toggleCol} />
                            </div>
                        </div>
                        {participants.data.length === 0 ? (
                            <p className="px-6 py-8 text-center text-sm text-gray-400">
                                {filters.q || filters.grade || filters.pgrade || filters.school ? 'Ничего не найдено по фильтрам.' : 'Состав ещё не сформирован — заполните его на странице «Состав участников».'}
                            </p>
                        ) : (
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <tr>
                                        <th className="px-3 py-3">Ученик</th>
                                        <th className="px-3 py-3">Школа</th>
                                        <th className="px-3 py-3">Кл.</th>
                                        <th className="px-3 py-3">Кл. уч.</th>
                                        <th className="px-3 py-3">Первичный</th>
                                        <th className="px-3 py-3">Макс.</th>
                                        {showQ && questions.map((n) => <th key={`qh${n}`} className="px-2 py-3 text-center">В{n}</th>)}
                                        <th className="px-3 py-3">Апелляция</th>
                                        {showA && questions.map((n) => <th key={`ah${n}`} className="px-2 py-3 text-center text-amber-700">+В{n}</th>)}
                                        <th className="px-3 py-3">Итог</th>
                                        {questionCount > 0 && (entryOpen || appealOpen) && <th className="px-3 py-3"></th>}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {participants.data.map((p) => (
                                        <tr key={p.id} className="hover:bg-gray-50">
                                            <td className="px-3 py-2 font-medium text-gray-800">{p.fio}</td>
                                            <td className="px-3 py-2 text-gray-500">{p.school ?? '—'}</td>
                                            <td className="px-3 py-2 text-gray-600">{p.real_grade}</td>
                                            <td className="px-3 py-2 text-gray-600">{p.participation_grade}</td>
                                            <td className="px-3 py-2 font-medium">
                                                {questionCount > 0
                                                    ? fmt(p.primary_score)
                                                    : <ScoreCell value={p.primary_score} editable={entryOpen}
                                                        url={route('municipal.results.primary', p.id)} payloadKey="primary_score" />}
                                            </td>
                                            <td className="px-3 py-2 text-gray-400">{olympiad.max_scores?.[p.participation_grade] != null ? fmt(olympiad.max_scores[p.participation_grade]) : '—'}</td>
                                            {showQ && questions.map((n) => (
                                                <td key={`q${p.id}-${n}`} className="px-2 py-2 text-center text-gray-600">{fmt(p.question_scores?.[n])}</td>
                                            ))}
                                            <td className="px-3 py-2 text-gray-600">
                                                {questionCount > 0
                                                    ? (p.appeal_addition != null ? `+${fmt(p.appeal_addition)}` : '—')
                                                    : <ScoreCell value={p.appeal_addition} editable={appealOpen} prefix="+"
                                                        url={route('municipal.results.appeal', p.id)} payloadKey="appeal_addition" />}
                                            </td>
                                            {showA && questions.map((n) => {
                                                const v = p.question_appeals?.[n];
                                                return <td key={`a${p.id}-${n}`} className="px-2 py-2 text-center text-amber-700">{v != null && v !== '' ? `+${fmt(v)}` : '—'}</td>;
                                            })}
                                            <td className="px-3 py-2 font-medium text-gray-700">{fmt(p.final_score)}</td>
                                            {questionCount > 0 && (entryOpen || appealOpen) && (
                                                <td className="px-3 py-2 whitespace-nowrap text-right">
                                                    {entryOpen && (
                                                        <button onClick={() => openPrimary(p)} className="mr-3 text-indigo-600 hover:underline">Балл</button>
                                                    )}
                                                    {appealOpen && (
                                                        <button onClick={() => openAppeal(p)} className="text-amber-600 hover:underline">Апелляция</button>
                                                    )}
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        {participants.links?.length > 3 && (
                            <div className="flex flex-wrap gap-1 border-t px-6 py-3">
                                {participants.links.map((link, i) => (
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

            <Modal show={!!primaryRow} onClose={() => setPrimaryRow(null)} maxWidth="lg">
                {primaryRow && (
                    <form onSubmit={submitPrimary} className="space-y-4 p-6">
                        <h3 className="font-semibold text-gray-800">Первичный балл — {primaryRow.fio}</h3>
                        <p className="text-xs text-gray-500">
                            Класс участия {primaryRow.participation_grade}. Итоговый балл пересчитается автоматически.
                        </p>
                        {questionCount > 0 ? (
                            <div>
                                <label className="mb-1 block text-xs text-gray-500">Баллы по заданиям{primaryMax != null ? ` (сумма ≤ макс. ${fmt(primaryMax)})` : ''}</label>
                                <div className="flex flex-wrap gap-2">
                                    {questions.map((n) => (
                                        <div key={n} className="flex items-center gap-1">
                                            <span className="text-xs text-gray-500">№{n}</span>
                                            <input type="text" inputMode="decimal" value={primaryForm.data.scores?.[n] ?? ''}
                                                onChange={(e) => primaryForm.setData('scores', { ...primaryForm.data.scores, [n]: e.target.value.replace(/[^\d.,]/g, '') })}
                                                className="w-16 rounded border-gray-300 text-sm" />
                                        </div>
                                    ))}
                                </div>
                                <p className="mt-1 text-xs text-gray-500">Первичный балл (сумма): <b>{fmt(sumOf(primaryForm.data.scores))}</b></p>
                                {primaryForm.errors.scores && <p className="text-xs text-red-600">{primaryForm.errors.scores}</p>}
                            </div>
                        ) : (
                            <div>
                                <label className="block text-xs text-gray-500">Первичный балл{primaryMax != null ? ` (макс. ${fmt(primaryMax)})` : ''}</label>
                                <input type="text" inputMode="decimal" value={primaryForm.data.primary_score}
                                    onChange={(e) => primaryForm.setData('primary_score', e.target.value.replace(/[^\d.,]/g, ''))}
                                    placeholder="напр. 27,5" className="w-full rounded border-gray-300 text-sm" />
                                {primaryForm.errors.primary_score && <p className="text-xs text-red-600">{primaryForm.errors.primary_score}</p>}
                            </div>
                        )}
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setPrimaryRow(null)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                            <button type="submit" disabled={primaryForm.processing}
                                className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">Сохранить</button>
                        </div>
                    </form>
                )}
            </Modal>

            <Modal show={!!appealRow} onClose={() => setAppealRow(null)} maxWidth="lg">
                {appealRow && (
                    <form onSubmit={submitAppeal} className="space-y-4 p-6">
                        <h3 className="font-semibold text-gray-800">Добавочный балл по апелляции — {appealRow.fio}</h3>
                        <p className="text-xs text-gray-500">
                            Первичный балл: {fmt(appealRow.primary_score)}. Итог = первичный + добавка
                            {appealMax != null ? `, но не выше максимума (${fmt(appealMax)})` : ''}.
                        </p>
                        {questionCount > 0 ? (
                            <div>
                                <label className="mb-1 block text-xs text-gray-500">Добавки по заданиям</label>
                                <div className="flex flex-wrap gap-2">
                                    {questions.map((n) => (
                                        <div key={n} className="flex items-center gap-1">
                                            <span className="text-xs text-gray-500">№{n}</span>
                                            <input type="text" inputMode="decimal" value={appealForm.data.appeals?.[n] ?? ''}
                                                onChange={(e) => appealForm.setData('appeals', { ...appealForm.data.appeals, [n]: e.target.value.replace(/[^\d.,]/g, '') })}
                                                className="w-16 rounded border-gray-300 text-sm" />
                                        </div>
                                    ))}
                                </div>
                                <p className="mt-1 text-xs text-gray-500">Добавка (сумма): <b>{fmt(sumOf(appealForm.data.appeals))}</b></p>
                                {appealForm.errors.appeals && <p className="text-xs text-red-600">{appealForm.errors.appeals}</p>}
                            </div>
                        ) : (
                            <div>
                                <label className="block text-xs text-gray-500">Добавлено по апелляции</label>
                                <input type="text" inputMode="decimal" value={appealForm.data.appeal_addition}
                                    onChange={(e) => appealForm.setData('appeal_addition', e.target.value.replace(/[^\d.,]/g, ''))}
                                    placeholder="напр. 2,5" className="w-full rounded border-gray-300 text-sm" />
                                {appealForm.errors.appeal_addition && <p className="text-xs text-red-600">{appealForm.errors.appeal_addition}</p>}
                            </div>
                        )}
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setAppealRow(null)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                            <button type="submit" disabled={appealForm.processing}
                                className="rounded bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50">Сохранить</button>
                        </div>
                    </form>
                )}
            </Modal>

            <Modal show={importOpen} onClose={closeScoresImport} maxWidth="lg">
                <form onSubmit={submitScoresImport} className="space-y-4 p-6">
                    <h3 className="font-semibold text-gray-800">Массовый ввод первичных баллов</h3>
                    <p className="text-xs text-gray-500">
                        Скачайте шаблон (XLSX), заполните колонку <b>Балл</b> и загрузите этот же файл. Балл
                        сопоставляется по ID участия из шаблона; код олимпиады в шапке сверяется.
                    </p>
                    {!scoresImport.progress && (
                        <>
                            <div>
                                <input type="file" accept=".xlsx,.ods,.csv,.txt"
                                    onChange={(e) => setImportFile(e.target.files[0] ?? null)}
                                    className="block w-full text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm" />
                            </div>
                            <div className="flex justify-end gap-2">
                                <button type="button" onClick={closeScoresImport} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                                <button type="submit" disabled={scoresImport.running || !importFile}
                                    className="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50">Загрузить</button>
                            </div>
                        </>
                    )}
                    <ImportProgress progress={scoresImport.progress} error={scoresImport.error} errorsHref={scoresImport.errorsHref}
                        onReset={() => { scoresImport.reset(); setImportFile(null); }} />
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
