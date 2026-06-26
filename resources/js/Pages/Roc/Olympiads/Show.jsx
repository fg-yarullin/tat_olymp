import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const STAGE_LABELS = { school: 'Школьный', municipal: 'Муниципальный' };
const STATUS_LABELS = { winner: 'Победитель', prize_winner: 'Призёр', participant: 'Участник', appealed: 'Апелляция' };
const fmt = (n) => (n == null || n === '' ? '—' : String(n).replace('.', ','));

export default function RocOlympiadsShow({ olympiad, rows, filters = {}, ate_options = [], has_template }) {
    const apply = (patch) => {
        const next = { ate: filters.ate ?? '', grade: filters.grade ?? '', pgrade: filters.pgrade ?? '', q: filters.q ?? '', ...patch };
        router.get(route('roc.olympiads.show', olympiad.id), Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '' && v != null)), {
            preserveScroll: true, preserveState: true, replace: true,
        });
    };

    const exportParams = { olympiad: olympiad.id };
    if (filters.ate) exportParams.ate = filters.ate;
    if (filters.grade) exportParams.grade = filters.grade;
    if (filters.pgrade) exportParams.pgrade = filters.pgrade;

    return (
        <AuthenticatedLayout header={
            <h2 className="text-xl font-semibold leading-tight text-gray-800">
                {olympiad.subject} — {STAGE_LABELS[olympiad.stage] ?? olympiad.stage} этап
            </h2>
        }>
            <Head title={`Протокол — ${olympiad.subject}`} />

            <div className="py-8">
                <div className="mx-auto max-w-6xl space-y-4 px-4 sm:px-6 lg:px-8">
                    <Link href={route('roc.olympiads.index')} className="text-sm text-indigo-600 hover:underline">← К списку олимпиад</Link>

                    <div className="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow">
                        <div>
                            <label className="block text-xs text-gray-500">АТЕ</label>
                            <select value={filters.ate ?? ''} onChange={(e) => apply({ ate: e.target.value })}
                                className="rounded border-gray-300 text-sm">
                                <option value="">Все АТЕ</option>
                                {ate_options.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">Класс обучения</label>
                            <select value={filters.grade ?? ''} onChange={(e) => apply({ grade: e.target.value })}
                                className="rounded border-gray-300 text-sm">
                                <option value="">Все</option>
                                {olympiad.grades.map((g) => <option key={g} value={g}>{g}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">Класс участия</label>
                            <select value={filters.pgrade ?? ''} onChange={(e) => apply({ pgrade: e.target.value })}
                                className="rounded border-gray-300 text-sm">
                                <option value="">Все</option>
                                {olympiad.grades.map((g) => <option key={g} value={g}>{g}</option>)}
                            </select>
                        </div>
                        <div className="flex-1">
                            <label className="block text-xs text-gray-500">Поиск по ФИО</label>
                            <input defaultValue={filters.q ?? ''} onBlur={(e) => apply({ q: e.target.value })}
                                onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }}
                                className="w-full rounded border-gray-300 text-sm" placeholder="Фамилия…" />
                        </div>
                        {has_template ? (
                            <a href={route('roc.olympiads.protocol', exportParams)}
                                className="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                                Выгрузить протокол (XLSX)
                            </a>
                        ) : (
                            <span className="text-xs text-amber-600" title="Шаблон протокола не настроен администратором">
                                Шаблон протокола не настроен
                            </span>
                        )}
                    </div>

                    <div className="overflow-hidden rounded-lg bg-white shadow">
                        <table className="min-w-full text-sm">
                            <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <tr>
                                    <th className="px-4 py-3">ФИО</th>
                                    <th className="px-4 py-3">Школа</th>
                                    <th className="px-4 py-3">Класс</th>
                                    <th className="px-4 py-3">Класс участия</th>
                                    <th className="px-4 py-3">Балл</th>
                                    <th className="px-4 py-3">Результат</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {rows.data.length === 0 ? (
                                    <tr><td colSpan={6} className="px-4 py-10 text-center text-gray-400">Нет участников по фильтрам.</td></tr>
                                ) : rows.data.map((r, i) => (
                                    <tr key={i} className="hover:bg-gray-50">
                                        <td className="px-4 py-2 font-medium text-gray-800">{r.fio}</td>
                                        <td className="px-4 py-2 text-gray-500">{r.school ?? '—'}</td>
                                        <td className="px-4 py-2 text-gray-600">{r.real_grade}</td>
                                        <td className="px-4 py-2 text-gray-600">{r.participation_grade}</td>
                                        <td className="px-4 py-2 font-medium">{fmt(r.score)}</td>
                                        <td className="px-4 py-2 text-gray-500">{STATUS_LABELS[r.result_status] ?? r.result_status ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {rows.links && rows.links.length > 3 && (
                        <div className="flex flex-wrap gap-1">
                            {rows.links.map((l, i) => (
                                <Link key={i} href={l.url || '#'} preserveScroll preserveState
                                    className={`rounded px-3 py-1 text-sm ${l.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'} ${!l.url ? 'pointer-events-none opacity-40' : ''}`}
                                    dangerouslySetInnerHTML={{ __html: l.label }} />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
