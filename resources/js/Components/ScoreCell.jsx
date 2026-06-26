import { router } from '@inertiajs/react';
import { useState } from 'react';

const fmt = (n) => (n == null || n === '' ? '—' : String(n).replace('.', ','));

/**
 * Ячейка ввода балла с автосохранением при выходе (blur). Редактируема, только если editable
 * (окно ввода открыто); иначе — текст. Сохранение — POST на url с полем payloadKey; ошибки
 * валидации (по ключу errorKey) подсвечивают ячейку. Балл — целое/дробное (точка или запятая).
 */
export default function ScoreCell({ value, editable, url, payloadKey = 'score', errorKey, prefix = '' }) {
    const [v, setV] = useState(value ?? '');
    const [state, setState] = useState('idle'); // idle | saving | saved | error
    const [error, setError] = useState('');
    const key = errorKey || payloadKey;

    // Синхронизация с пропом при внешнем изменении (напр. массовый импорт баллов): когда сервер
    // присылает новое значение, обновляем ячейку. Во время набора проп не меняется — ввод не сбивается.
    const [seen, setSeen] = useState(value);
    if (value !== seen) {
        setSeen(value);
        setV(value ?? '');
    }

    if (!editable) {
        return <span className="text-gray-700">{prefix && value != null && value !== '' ? prefix : ''}{fmt(value)}</span>;
    }

    const save = () => {
        if (String(value ?? '') === String(v ?? '')) return; // не изменилось — не сохраняем
        setState('saving');
        setError('');
        router.post(url, { [payloadKey]: v === '' ? '' : String(v).replace(',', '.') }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { setState('saved'); setTimeout(() => setState('idle'), 1500); },
            onError: (errs) => { setError(errs[key] || 'Ошибка'); setState('error'); },
        });
    };

    return (
        <span className="inline-flex items-center gap-1">
            <input
                type="text"
                inputMode="decimal"
                value={v}
                onChange={(e) => { setV(e.target.value.replace(/[^\d.,]/g, '')); if (state !== 'idle') setState('idle'); }}
                onBlur={save}
                onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }}
                className={`w-16 rounded border px-2 py-1 text-sm ${state === 'error' ? 'border-red-400 bg-red-50' : 'border-gray-300'}`}
            />
            {state === 'saving' && <span className="text-xs text-gray-400">…</span>}
            {state === 'saved' && <span className="text-xs text-green-600">✓</span>}
            {state === 'error' && <span className="text-xs text-red-600" title={error}>!</span>}
        </span>
    );
}
