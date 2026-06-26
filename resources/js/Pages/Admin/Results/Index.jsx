import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const STAGE_LABELS = { school: 'Школьный', municipal: 'Муниципальный', regional: 'Региональный' };
const LEVEL_LABELS = { regional: 'Региональный', republican: 'Республиканский' };
const RESULT = {
    participant: ['Участник', 'bg-gray-100 text-gray-600'],
    appealed: ['Апелляция', 'bg-amber-100 text-amber-700'],
    prize_winner: ['Призёр', 'bg-blue-100 text-blue-700'],
    winner: ['Победитель', 'bg-green-100 text-green-700'],
    disqualified: ['Дисквалификация', 'bg-red-100 text-red-700'],
};

const gradesLabel = (g) => {
    const arr = (g || '').split(',').filter(Boolean);
    return arr.length === 0 || arr.length === 11 ? 'все' : arr.join(', ');
};

export default function ResultsIndex({ results, filters, subjects, ates, msus, schools }) {
    const [subjectId, setSubjectId] = useState(filters.subjectId ?? '');
    const [stage, setStage] = useState(filters.stage ?? '');
    const [ateId, setAteId] = useState(filters.ateId ?? '');
    const [msuId, setMsuId] = useState(filters.msuId ?? '');
    const [schoolId, setSchoolId] = useState(filters.schoolId ?? '');
    const [q, setQ] = useState(filters.q ?? '');

    // Районы (МСУ) выбранной АТЕ — выбор показываем только если их несколько
    const ateMsus = useMemo(
        () => (ateId ? msus.filter((m) => String(m.ate_id) === String(ateId)) : []),
        [msus, ateId],
    );
    const schoolOptions = useMemo(
        () =>
            schools.filter(
                (s) =>
                    (!ateId || String(s.ate_id) === String(ateId)) &&
                    (!msuId || String(s.msu_id) === String(msuId)),
            ),
        [schools, ateId, msuId],
    );

    const apply = (overrides = {}) => {
        const params = {
            subject_id: subjectId,
            stage,
            ate_id: ateId,
            msu_id: msuId,
            school_id: schoolId,
            q,
            ...overrides,
        };
        Object.keys(params).forEach((k) => {
            if (params[k] === '' || params[k] == null) delete params[k];
        });
        router.get(route('admin.results.index'), params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const reset = () => {
        setSubjectId('');
        setStage('');
        setAteId('');
        setMsuId('');
        setSchoolId('');
        setQ('');
        router.get(route('admin.results.index'), {}, { preserveState: true });
    };

    const labelCls = 'block text-xs font-medium text-gray-500';
    const inputCls = 'mt-1 w-full rounded border-gray-300 text-sm';

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Результаты олимпиад
                </h2>
            }
        >
            <Head title="Результаты олимпиад" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="grid gap-4 rounded-lg bg-white p-6 shadow sm:grid-cols-2 lg:grid-cols-6">
                        <div>
                            <label className={labelCls}>
                                Предмет <span className="text-red-500">*</span>
                            </label>
                            <select
                                value={subjectId}
                                onChange={(e) => {
                                    setSubjectId(e.target.value);
                                    apply({ subject_id: e.target.value });
                                }}
                                className={inputCls}
                            >
                                <option value="">— выберите —</option>
                                {subjects.map((s) => (
                                    <option key={s.id} value={s.id}>{s.name}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className={labelCls}>Этап</label>
                            <select
                                value={stage}
                                onChange={(e) => {
                                    setStage(e.target.value);
                                    apply({ stage: e.target.value });
                                }}
                                className={inputCls}
                            >
                                <option value="">Все этапы</option>
                                {Object.entries(STAGE_LABELS).map(([value, label]) => (
                                    <option key={value} value={value}>{label}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className={labelCls}>АТЕ</label>
                            <select
                                value={ateId}
                                onChange={(e) => {
                                    setAteId(e.target.value);
                                    setMsuId('');
                                    setSchoolId('');
                                    apply({ ate_id: e.target.value, msu_id: '', school_id: '' });
                                }}
                                className={inputCls}
                            >
                                <option value="">Все АТЕ</option>
                                {ates.map((a) => (
                                    <option key={a.id} value={a.id}>{a.name}</option>
                                ))}
                            </select>
                        </div>
                        {ateMsus.length > 1 && (
                            <div>
                                <label className={labelCls}>Район (МСУ)</label>
                                <select
                                    value={msuId}
                                    onChange={(e) => {
                                        setMsuId(e.target.value);
                                        setSchoolId('');
                                        apply({ msu_id: e.target.value, school_id: '' });
                                    }}
                                    className={inputCls}
                                >
                                    <option value="">Все районы</option>
                                    {ateMsus.map((m) => (
                                        <option key={m.id} value={m.id}>{m.name}</option>
                                    ))}
                                </select>
                            </div>
                        )}
                        <div>
                            <label className={labelCls}>Школа</label>
                            <select
                                value={schoolId}
                                onChange={(e) => {
                                    setSchoolId(e.target.value);
                                    apply({ school_id: e.target.value });
                                }}
                                className={inputCls}
                            >
                                <option value="">Все школы</option>
                                {schoolOptions.map((s) => (
                                    <option key={s.id} value={s.id}>{s.short_name}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className={labelCls}>Участник</label>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    apply();
                                }}
                            >
                                <input
                                    type="text"
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder="ФИО или штрихкод"
                                    className={inputCls}
                                />
                            </form>
                        </div>
                    </div>

                    {results && (
                        <div className="flex items-center justify-between text-sm text-gray-500">
                            <span>Найдено результатов: <b>{results.total}</b></span>
                            <button onClick={reset} className="text-gray-500 hover:text-gray-700">
                                Сбросить фильтры
                            </button>
                        </div>
                    )}

                    {!results ? (
                        <div className="rounded-lg bg-white p-10 text-center text-gray-400 shadow">
                            Выберите предмет, чтобы увидеть внесённые результаты.
                        </div>
                    ) : results.data.length === 0 ? (
                        <div className="rounded-lg bg-white p-10 text-center text-gray-400 shadow">
                            По заданным условиям результатов не найдено.
                        </div>
                    ) : (
                        <>
                            <div className="overflow-x-auto rounded-lg bg-white shadow">
                                <table className="min-w-full text-sm">
                                    <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                        <tr>
                                            <th className="px-4 py-3">Участник</th>
                                            <th className="px-4 py-3">Школа</th>
                                            <th className="px-4 py-3">АТЕ / МСУ</th>
                                            <th className="px-4 py-3">Этап / уровень</th>
                                            <th className="px-4 py-3">Кл.</th>
                                            <th className="px-4 py-3">Балл</th>
                                            <th className="px-4 py-3">Результат</th>
                                            <th className="px-4 py-3">Штрихкод</th>
                                            <th className="px-4 py-3">Скан</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {results.data.map((r) => {
                                            const [label, cls] = RESULT[r.result_status] ?? [r.result_status, 'bg-gray-100 text-gray-600'];
                                            return (
                                                <tr key={r.id} className="hover:bg-gray-50">
                                                    <td className="px-4 py-3 font-medium text-gray-800">{r.fio}</td>
                                                    <td className="px-4 py-3 text-gray-600">{r.school}</td>
                                                    <td className="px-4 py-3 text-gray-500">
                                                        {r.ate}
                                                        {r.msu && r.msu !== r.ate ? ` · ${r.msu}` : ''}
                                                    </td>
                                                    <td className="px-4 py-3 text-gray-500">
                                                        {STAGE_LABELS[r.stage] ?? r.stage}
                                                        <span className="text-gray-400">
                                                            {' · '}{LEVEL_LABELS[r.level] ?? r.level}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-gray-600">{r.participation_grade}</td>
                                                    <td className="px-4 py-3 font-semibold text-gray-800">
                                                        {r.score ?? '—'}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${cls}`}>
                                                            {label}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-gray-500">{r.barcode ?? '—'}</td>
                                                    <td className="px-4 py-3">
                                                        {r.has_scan ? (
                                                            <span className="text-green-600">есть</span>
                                                        ) : (
                                                            <span className="text-gray-400">нет</span>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>

                            <div className="flex flex-wrap gap-1">
                                {results.links.map((link, i) => (
                                    <button
                                        key={i}
                                        disabled={!link.url}
                                        onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })}
                                        className={`rounded px-3 py-1 text-sm ${
                                            link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'
                                        } ${!link.url ? 'opacity-40' : ''}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
