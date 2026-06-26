import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const STAGE_LABELS = { school: 'Школьный', municipal: 'Муниципальный', regional: 'Региональный' };
const LEVEL_LABELS = { regional: 'Региональный', republican: 'Республиканский' };
const PHASE_LABELS = { primary: 'Первичный ввод', appeal: 'Приём апелляций' };

const gradesLabel = (g) => (!g || g.length === 11 ? 'все классы' : `классы ${g.join(', ')}`);

const fmtDate = (iso) =>
    new Date(iso).toLocaleDateString('ru-RU', { day: '2-digit', month: 'long', year: 'numeric' });

const pad = (n) => String(n).padStart(2, '0');

// Остаток времени до срока: { text, tone } по порогам 48ч / 12ч. Тикает посекундно.
function remaining(deadlineIso, now) {
    if (!deadlineIso) return { text: 'без срока', tone: 'none' };
    const ms = new Date(deadlineIso).getTime() - now;
    if (ms <= 0) return { text: 'срок истёк', tone: 'closed' };

    const totalSec = Math.floor(ms / 1000);
    const days = Math.floor(totalSec / 86400);
    const hours = Math.floor((totalSec % 86400) / 3600);
    const mins = Math.floor((totalSec % 3600) / 60);
    const secs = totalSec % 60;

    const hms = `${pad(hours)}:${pad(mins)}:${pad(secs)}`;
    const text = days > 0 ? `${days} д ${hms}` : hms;

    const hoursLeft = ms / 3_600_000;
    const tone = hoursLeft > 48 ? 'ok' : hoursLeft > 12 ? 'warn' : 'urgent';
    return { text, tone };
}

const TONE = {
    ok: { dot: 'bg-green-500', badge: 'bg-green-50 text-green-700' },
    warn: { dot: 'bg-amber-500', badge: 'bg-amber-50 text-amber-700' },
    urgent: { dot: 'bg-red-500', badge: 'bg-red-50 text-red-700' },
    closed: { dot: 'bg-gray-300', badge: 'bg-gray-100 text-gray-500' },
    none: { dot: 'bg-indigo-300', badge: 'bg-indigo-50 text-indigo-600' },
};

export default function Dashboard({ active = [], upcoming = [], counts = {}, has_current_year = true }) {
    const user = usePage().props.auth.user;

    // Живой отсчёт: пересчёт каждую секунду.
    const [now, setNow] = useState(Date.now());
    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, []);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Главная</h2>}
        >
            <Head title="Главная" />

            <div className="py-8">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="rounded-lg bg-white p-5 shadow">
                        <p className="text-lg font-semibold text-gray-800">Здравствуйте, {user.name}!</p>
                        <p className="mt-1 text-sm text-gray-500">
                            Обзор олимпиад текущего учебного года: сроки ввода данных и ближайшие события.
                        </p>
                    </div>

                    {!has_current_year ? (
                        <div className="rounded-lg bg-white p-10 text-center text-gray-400 shadow">
                            Текущий учебный год не задан.
                        </div>
                    ) : (
                        <>
                            {/* Счётчики */}
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <Tile value={counts.active ?? 0} label="идёт ввод" accent="text-indigo-600" />
                                <Tile value={counts.closing_soon ?? 0} label="закрывается за 24 ч" accent="text-red-600" />
                                <Tile value={counts.upcoming ?? 0} label="предстоящих олимпиад" accent="text-emerald-600" />
                            </div>

                            {/* Идёт ввод */}
                            <div className="overflow-hidden rounded-lg bg-white shadow">
                                <div className="border-b px-6 py-3">
                                    <h3 className="font-semibold text-gray-800">Сроки ввода данных</h3>
                                </div>
                                {active.length === 0 ? (
                                    <p className="px-6 py-8 text-center text-sm text-gray-400">
                                        Сейчас нет олимпиад с открытым вводом результатов.
                                    </p>
                                ) : (
                                    <ul className="divide-y divide-gray-100">
                                        {active.map((o) => {
                                            const r = remaining(o.deadline, now);
                                            const tone = TONE[r.tone];
                                            return (
                                                <li key={`${o.id}-${o.phase}`} className="flex items-center gap-3 px-6 py-3">
                                                    <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${tone.dot}`} />
                                                    <div className="min-w-0 flex-1">
                                                        <p className="font-medium text-gray-800">
                                                            {o.subject}
                                                            <span className="ml-2 text-xs font-normal text-gray-400">
                                                                {STAGE_LABELS[o.stage] ?? o.stage} этап · {gradesLabel(o.grades)}
                                                            </span>
                                                        </p>
                                                        <p className="text-xs text-gray-500">{PHASE_LABELS[o.phase] ?? o.phase}</p>
                                                    </div>
                                                    <span className={`shrink-0 rounded-full px-3 py-1 text-xs font-medium ${tone.badge}`}>
                                                        {r.tone === 'closed' || r.tone === 'none' ? r.text : `осталось ${r.text}`}
                                                    </span>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </div>

                            {/* Предстоящие олимпиады */}
                            <div className="overflow-hidden rounded-lg bg-white shadow">
                                <div className="border-b px-6 py-3">
                                    <h3 className="font-semibold text-gray-800">Предстоящие олимпиады</h3>
                                </div>
                                {upcoming.length === 0 ? (
                                    <p className="px-6 py-8 text-center text-sm text-gray-400">
                                        Нет запланированных олимпиад с будущей датой проведения.
                                    </p>
                                ) : (
                                    <ul className="divide-y divide-gray-100">
                                        {upcoming.map((o) => (
                                            <li key={o.id} className="flex items-center justify-between gap-3 px-6 py-3">
                                                <div className="min-w-0">
                                                    <p className="font-medium text-gray-800">
                                                        {o.subject}
                                                        <span className="ml-2 text-xs font-normal text-gray-400">
                                                            {STAGE_LABELS[o.stage] ?? o.stage} этап · {gradesLabel(o.grades)}
                                                        </span>
                                                    </p>
                                                </div>
                                                <span className="shrink-0 text-sm text-gray-500">{fmtDate(o.date_held)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Tile({ value, label, accent }) {
    return (
        <div className="rounded-lg bg-white p-5 shadow">
            <p className={`text-3xl font-bold ${accent}`}>{value}</p>
            <p className="mt-1 text-sm text-gray-500">{label}</p>
        </div>
    );
}
