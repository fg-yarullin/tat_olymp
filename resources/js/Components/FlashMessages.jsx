import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

const STYLES = {
    success: 'border-green-200 bg-green-50 text-green-800',
    error: 'border-red-200 bg-red-50 text-red-800',
    warning: 'border-amber-200 bg-amber-50 text-amber-800',
};

const SUCCESS_TTL = 5000; // успех исчезает сам через 5 секунд

/**
 * Глобальные flash-уведомления (тосты). Успех автоматически исчезает; ошибки и
 * предупреждения закрываются вручную и сбрасываются при следующей успешной операции.
 */
export default function FlashMessages() {
    const { flash } = usePage().props;
    const [items, setItems] = useState([]);
    const idRef = useRef(0);

    const remove = useCallback((id) => {
        setItems((prev) => prev.filter((i) => i.id !== id));
    }, []);

    useEffect(() => {
        const incoming = [];
        if (flash?.success) incoming.push({ type: 'success', message: flash.success });
        if (flash?.error) incoming.push({ type: 'error', message: flash.error });
        if (flash?.warning) incoming.push({ type: 'warning', message: flash.warning });
        if (incoming.length === 0) return;

        setItems((prev) => {
            // Успешная операция убирает прежние ошибки/предупреждения.
            const kept = incoming.some((i) => i.type === 'success') ? [] : prev;
            const added = incoming.map((i) => ({ id: (idRef.current += 1), ...i }));
            return [...kept, ...added];
        });
    }, [flash?.success, flash?.error, flash?.warning]);

    if (items.length === 0) return null;

    return (
        <div className="pointer-events-none fixed right-4 top-4 z-[60] flex w-full max-w-sm flex-col gap-2">
            {items.map((item) => (
                <Toast key={item.id} item={item} onClose={remove} />
            ))}
        </div>
    );
}

function Toast({ item, onClose }) {
    useEffect(() => {
        if (item.type !== 'success') return undefined;
        const t = setTimeout(() => onClose(item.id), SUCCESS_TTL);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [item.id]);

    return (
        <div
            role="alert"
            className={`pointer-events-auto flex items-start gap-3 rounded-lg border px-4 py-3 text-sm shadow-lg ${STYLES[item.type] ?? STYLES.success}`}
        >
            <div className="max-h-60 flex-1 overflow-y-auto whitespace-pre-line break-words">{item.message}</div>
            <button
                type="button"
                onClick={() => onClose(item.id)}
                className="-mr-1 -mt-0.5 shrink-0 rounded p-0.5 text-lg leading-none opacity-60 hover:opacity-100"
                aria-label="Закрыть"
            >
                ×
            </button>
        </div>
    );
}
