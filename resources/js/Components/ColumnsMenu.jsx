import { useEffect, useRef, useState } from 'react';

/**
 * Компактное меню видимости колонок: кнопка «Колонки ▾» открывает поповер с галочками.
 * options: [{ key, label }]. cols: { key: boolean }. onToggle(key).
 */
export default function ColumnsMenu({ options = [], cols = {}, onToggle }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        const onDoc = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, []);

    if (options.length === 0) return null;

    const activeCount = options.filter((o) => cols[o.key]).length;

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
            >
                Колонки{activeCount ? ` (${activeCount})` : ''} ▾
            </button>
            {open && (
                <div className="absolute right-0 z-20 mt-1 w-60 rounded-lg border border-gray-200 bg-white p-2 shadow-lg">
                    <p className="px-2 pb-1 pt-0.5 text-xs text-gray-400">Показать колонки</p>
                    {options.map((o) => (
                        <label key={o.key} className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                            <input type="checkbox" checked={!!cols[o.key]} onChange={() => onToggle(o.key)} className="rounded border-gray-300" />
                            {o.label}
                        </label>
                    ))}
                </div>
            )}
        </div>
    );
}
