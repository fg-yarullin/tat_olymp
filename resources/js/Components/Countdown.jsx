import { useNow } from '@/Hooks/useNow';

const pad = (n) => String(n).padStart(2, '0');

const fmtDeadline = (iso) => {
    const d = new Date(iso);
    return `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()} в ${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

const TONE = {
    ok: { badge: 'bg-green-50 text-green-700', big: 'bg-green-50 text-green-700' },
    warn: { badge: 'bg-amber-50 text-amber-700', big: 'bg-amber-50 text-amber-700' },
    urgent: { badge: 'bg-red-50 text-red-700', big: 'bg-red-50 text-red-700' },
    closed: { badge: 'bg-gray-100 text-gray-500', big: 'bg-gray-100 text-gray-500' },
    none: { badge: 'bg-indigo-50 text-indigo-600', big: 'bg-indigo-50 text-indigo-600' },
};

/**
 * Состояние обратного отсчёта. Закрытие определяется ЛИБО истёкшим сроком (живой тик по
 * `deadline`), ЛИБО серверным флагом `open=false` (ввод может закрыться и до срока —
 * например, при публикации результатов, см. Olympiad::phaseOpen).
 */
function countdownState(open, deadlineIso, now, closedLabel) {
    const ms = deadlineIso ? new Date(deadlineIso).getTime() - now : null;
    if ((ms !== null && ms <= 0) || !open) {
        return { tone: 'closed', text: closedLabel };
    }
    if (ms === null) {
        return { tone: 'none', text: 'без срока' };
    }

    const totalSec = Math.floor(ms / 1000);
    const days = Math.floor(totalSec / 86400);
    const hours = Math.floor((totalSec % 86400) / 3600);
    const mins = Math.floor((totalSec % 3600) / 60);
    const secs = totalSec % 60;
    const hms = `${pad(hours)}:${pad(mins)}:${pad(secs)}`;
    const text = days > 0 ? `${days} д ${hms}` : hms;

    const hoursLeft = ms / 3_600_000;
    const tone = hoursLeft > 48 ? 'ok' : hoursLeft > 12 ? 'warn' : 'urgent';

    return { tone, text };
}

/**
 * Живой обратный отсчёт до закрытия ввода результатов.
 * size="sm" — компактный бейдж (строки списков); size="lg" — крупный блок с секундами
 * (страница ввода — чтобы оператор явно видел остаток времени).
 * stack — в size="sm" переносит слово «осталось» на отдельную строку над счётчиком
 * (компактнее по ширине для таблиц с несколькими счётчиками в ячейке).
 */
export default function Countdown({ open, deadline, size = 'sm', closedLabel = 'ввод закрыт', stack = false }) {
    const now = useNow();
    const { tone, text } = countdownState(open, deadline, now, closedLabel);
    const cls = TONE[tone];
    const isCounting = tone === 'ok' || tone === 'warn' || tone === 'urgent';

    if (size === 'lg') {
        return (
            <div className={`rounded-lg p-4 ${cls.big}`}>
                <p className="text-xs font-medium uppercase tracking-wide opacity-75">
                    {tone === 'closed' ? 'Ввод результатов закрыт' : tone === 'none' ? 'Срок ввода не задан' : 'До закрытия ввода результатов'}
                </p>
                {tone !== 'none' && (
                    <p className="mt-1 font-mono text-3xl font-bold tabular-nums">{tone === 'closed' ? text : `осталось ${text}`}</p>
                )}
                {tone !== 'none' && tone !== 'closed' && deadline && (
                    <p className="mt-1 text-sm font-normal opacity-75">(закроется {fmtDeadline(deadline)})</p>
                )}
            </div>
        );
    }

    if (stack && isCounting) {
        return (
            <span className={`inline-block rounded-full px-2 py-1 text-center text-xs font-medium leading-tight ${cls.badge}`}>
                <span className="block">осталось</span>
                <span className="block whitespace-nowrap tabular-nums">{text}</span>
            </span>
        );
    }

    return (
        <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium leading-tight ${cls.badge}`}>
            {isCounting ? `осталось ${text}` : text}
        </span>
    );
}
