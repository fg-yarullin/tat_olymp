import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

const STAGE_ORDER = ['school', 'municipal', 'regional'];
const STAGE_LABELS = { school: 'Школьный', municipal: 'Муниципальный', regional: 'Региональный' };
const gradesLabel = (g) => (!g || g.length === 11 ? 'все' : g.join(', '));
const fmt = (iso) =>
    iso
        ? new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
        : '—';
const fmtDate = (iso) =>
    iso ? new Date(iso).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—';

// Ячейка срока (зафиксированный срок; продления на расписание не влияют).
function Deadline({ d }) {
    if (!d || !d.date) return <span className="text-gray-400">—</span>;
    return <span className="whitespace-nowrap">{fmt(d.date)}</span>;
}

function OlympiadCell({ o }) {
    return (
        <td className="px-4 py-3">
            <div className="font-medium text-gray-800">{o.subject}</div>
            <div className="text-xs text-gray-400">классы {gradesLabel(o.grades)}</div>
        </td>
    );
}

function PublicationCell({ o }) {
    return (
        <td className="px-4 py-3 text-gray-600">
            {o.published_at ? (
                <span className="whitespace-nowrap text-green-700">{fmt(o.published_at)}</span>
            ) : (
                <Deadline d={o.publication} />
            )}
        </td>
    );
}

// Таблица школьного этапа: один срок (закрытие ввода = публикация).
function SchoolTable({ rows }) {
    return (
        <table className="min-w-full text-sm">
            <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
                    <th className="px-4 py-3">Олимпиада</th>
                    <th className="px-4 py-3">Проведение</th>
                    <th className="px-4 py-3">Закрытие ввода результатов</th>
                </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
                {rows.map((o) => (
                    <tr key={o.id} className="hover:bg-gray-50">
                        <OlympiadCell o={o} />
                        <td className="px-4 py-3 whitespace-nowrap text-gray-600">{fmtDate(o.start)}</td>
                        <PublicationCell o={o} />
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

// Таблица муниципального/регионального этапа: первичный ввод + апелляции/публикация.
function TwoPhaseTable({ rows }) {
    return (
        <table className="min-w-full text-sm">
            <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
                    <th className="px-4 py-3">Олимпиада</th>
                    <th className="px-4 py-3">Проведение</th>
                    <th className="px-4 py-3">Первичный ввод до</th>
                    <th className="px-4 py-3">Итоговый ввод до</th>
                </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
                {rows.map((o) => (
                    <tr key={o.id} className="hover:bg-gray-50">
                        <OlympiadCell o={o} />
                        <td className="px-4 py-3 whitespace-nowrap text-gray-600">{fmtDate(o.start)}</td>
                        <td className="px-4 py-3 text-gray-600"><Deadline d={o.primary_close} /></td>
                        <PublicationCell o={o} />
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

export default function ScheduleIndex({ olympiads = [] }) {
    const grouped = olympiads.reduce((acc, o) => {
        (acc[o.stage] ??= []).push(o);
        return acc;
    }, {});
    const stages = STAGE_ORDER.filter((s) => grouped[s]?.length);

    const [tab, setTab] = useState(stages[0] ?? 'school');
    const rows = grouped[tab] ?? [];

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Расписание олимпиад</h2>}
        >
            <Head title="Расписание" />

            <div className="py-8">
                <div className="mx-auto max-w-6xl space-y-4 px-4 sm:px-6 lg:px-8">
                    <p className="text-sm text-gray-500">
                        Зафиксированные сроки текущего учебного года. Продления отдельным АТЕ/школам
                        на расписание не влияют.
                    </p>

                    {stages.length === 0 ? (
                        <div className="rounded-lg bg-white p-10 text-center text-gray-400 shadow">
                            Олимпиады текущего года не заданы.
                        </div>
                    ) : (
                        <>
                            {/* Вкладки этапов */}
                            <div className="flex gap-1 border-b border-gray-200">
                                {stages.map((s) => (
                                    <button
                                        key={s}
                                        onClick={() => setTab(s)}
                                        className={`-mb-px border-b-2 px-4 py-2 text-sm font-medium transition ${
                                            tab === s
                                                ? 'border-indigo-600 text-indigo-700'
                                                : 'border-transparent text-gray-500 hover:text-gray-700'
                                        }`}
                                    >
                                        {STAGE_LABELS[s] ?? s} этап
                                        <span className="ml-1 text-xs text-gray-400">({grouped[s].length})</span>
                                    </button>
                                ))}
                            </div>

                            <div className="overflow-x-auto rounded-lg bg-white shadow">
                                {tab === 'school' ? <SchoolTable rows={rows} /> : <TwoPhaseTable rows={rows} />}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
