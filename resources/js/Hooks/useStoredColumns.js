import { useEffect, useState } from 'react';

/**
 * Видимость опциональных колонок таблицы, сохраняемая в localStorage браузера.
 * Переживает обновление страницы и повторный вход (на сервер не пишется).
 *
 * @param {string} storageKey  ключ хранения (свой на тип страницы)
 * @param {Object} defaults    значения по умолчанию { colKey: boolean }
 * @returns {[Object, (key: string) => void]} [состояние, переключатель]
 */
export function useStoredColumns(storageKey, defaults) {
    const [cols, setCols] = useState(() => {
        try {
            const raw = localStorage.getItem(storageKey);
            return raw ? { ...defaults, ...JSON.parse(raw) } : defaults;
        } catch {
            return defaults;
        }
    });

    useEffect(() => {
        try {
            localStorage.setItem(storageKey, JSON.stringify(cols));
        } catch {
            // приватный режим / переполнение — просто не сохраняем
        }
    }, [storageKey, cols]);

    const toggle = (key) => setCols((c) => ({ ...c, [key]: !c[key] }));

    return [cols, toggle];
}
