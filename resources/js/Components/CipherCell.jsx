import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Ячейка ввода шифра (текст) с автосохранением при выходе (blur). Аналог ScoreCell, но без
 * фильтра цифр. Редактируема, только если editable; иначе — текст. Ошибки валидации (по ключу
 * cipher, включая дубликат шифра) подсвечивают ячейку.
 */
export default function CipherCell({ value, editable, url }) {
    const [v, setV] = useState(value ?? '');
    const [state, setState] = useState('idle'); // idle | saving | saved | error
    const [error, setError] = useState('');

    // Синхронизация с пропом при внешнем изменении (напр. массовая загрузка шифров): когда сервер
    // присылает новое значение, обновляем ячейку. Во время набора проп не меняется — ввод не сбивается.
    const [seen, setSeen] = useState(value);
    if (value !== seen) {
        setSeen(value);
        setV(value ?? '');
    }

    if (!editable) {
        return <span className="font-mono text-gray-700">{value == null || value === '' ? '—' : value}</span>;
    }

    const save = () => {
        if (String(value ?? '') === String(v ?? '')) return; // не изменилось
        setState('saving');
        setError('');
        router.post(url, { cipher: v }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { setState('saved'); setTimeout(() => setState('idle'), 1500); },
            onError: (errs) => { setError(errs.cipher || 'Ошибка'); setState('error'); },
        });
    };

    return (
        <span className="inline-flex items-center gap-1">
            <input
                type="text"
                value={v}
                onChange={(e) => { setV(e.target.value); if (state !== 'idle') setState('idle'); }}
                onBlur={save}
                onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }}
                className={`w-24 rounded border px-2 py-1 font-mono text-sm ${state === 'error' ? 'border-red-400 bg-red-50' : 'border-gray-300'}`}
                placeholder="шифр"
            />
            {state === 'saving' && <span className="text-xs text-gray-400">…</span>}
            {state === 'saved' && <span className="text-xs text-green-600">✓</span>}
            {state === 'error' && <span className="text-xs text-red-600" title={error}>!</span>}
        </span>
    );
}
