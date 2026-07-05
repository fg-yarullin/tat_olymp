import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

const STAGE_ORDER = ['school', 'municipal', 'regional'];
const STAGE_LABELS = { school: 'Школьный', municipal: 'Муниципальный', regional: 'Региональный' };

const gradesLabel = (g) => (!g || g.length === 11 ? 'все' : g.join(', '));
const fmtDate = (iso) =>
    iso ? new Date(iso).toLocaleDateString('ru-RU', { day: '2-digit', month: 'long', year: 'numeric' }) : '—';

export default function SchedulePublic({ olympiads = [] }) {
    const grouped = olympiads.reduce((acc, o) => {
        (acc[o.stage] ??= []).push(o);
        return acc;
    }, {});
    const stages = STAGE_ORDER.filter((s) => grouped[s]?.length);
    const [tab, setTab] = useState(stages[0] ?? 'school');
    const rows = grouped[tab] ?? [];

    return (
        <>
            <Head title="Расписание олимпиад" />
            <div className="min-h-screen bg-gradient-to-b from-slate-50 to-indigo-50">
                <div className="mx-auto max-w-4xl px-6 py-12">
                    <div className="mb-6 flex items-center justify-between">
                        <Link href="/" className="text-sm text-indigo-600 hover:underline">← На главную</Link>
                        <Link href={route('login')} className="text-sm text-indigo-600 hover:underline">Вход для сотрудников</Link>
                    </div>

                    <h1 className="text-2xl font-bold tracking-tight text-gray-900">Расписание олимпиад</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Даты проведения и публикации результатов в текущем учебном году.
                    </p>

                    {stages.length === 0 ? (
                        <div className="mt-6 rounded-lg bg-white p-10 text-center text-sm text-gray-400 shadow">
                            Расписание пока не опубликовано.
                        </div>
                    ) : (
                        <>
                            <div className="mt-6 flex gap-1 border-b border-gray-200">
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
                                    </button>
                                ))}
                            </div>

                            <div className="mt-4 overflow-hidden rounded-lg bg-white shadow">
                                <table className="min-w-full text-sm">
                                    <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                        <tr>
                                            <th className="px-5 py-3">Олимпиада</th>
                                            <th className="px-5 py-3">Проведение</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {rows.map((o) => (
                                            <tr key={o.id}>
                                                <td className="px-5 py-3">
                                                    <div className="font-medium text-gray-800">{o.subject}</div>
                                                    <div className="text-xs text-gray-400">классы {gradesLabel(o.grades)}</div>
                                                </td>
                                                <td className="px-5 py-3 whitespace-nowrap text-gray-600">{fmtDate(o.start)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}
