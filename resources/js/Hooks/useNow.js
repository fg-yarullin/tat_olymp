import { useEffect, useState } from 'react';

/** Тикающее «сейчас» (мс от эпохи), обновляется раз в секунду — для живого обратного отсчёта. */
export function useNow(intervalMs = 1000) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), intervalMs);
        return () => clearInterval(id);
    }, [intervalMs]);

    return now;
}
