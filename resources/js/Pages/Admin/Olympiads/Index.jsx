import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const STAGE_LABELS = {
    school: 'Школьный',
    municipal: 'Муниципальный',
    regional: 'Региональный',
};
const ALL_GRADES = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];

const LEVEL_LABELS = { regional: 'Региональный', republican: 'Республиканский' };

const gradesLabel = (grades) => {
    if (!grades || grades.length === 0 || grades.length === 11) return 'все';
    return grades.join(', ');
};

// Превью сроков МЭ от даты ШЭ (YYYY-MM-DD): +1 мес (проведение), +1 мес +5/+8 дн (сроки) — как на сервере.
const munDatesPreview = (dateStr) => {
    const [y, m, d] = dateStr.split('-').map(Number);
    const mk = (addDays) => {
        const dt = new Date(y, m - 1, d);
        dt.setMonth(dt.getMonth() + 1);
        dt.setDate(dt.getDate() + addDays);
        return dt;
    };
    const f = (dt) => `${String(dt.getDate()).padStart(2, '0')}.${String(dt.getMonth() + 1).padStart(2, '0')}`;
    return `пров. ${f(mk(0))}; перв. ${f(mk(5))} 16:00; итог ${f(mk(8))} 16:00`;
};

const blank = (years, subjects) => ({
    academic_year_id: years[0]?.id ?? '',
    subject_id: subjects[0]?.id ?? '',
    stage: 'school',
    level: 'regional',
    grades: [...ALL_GRADES],
    question_count: 0,
    max_scores: {},
    thresholds: {},
    auto_status_mode: 'operator',
    date_held: '',
    results_deadline: '',
    final_results_deadline: '',
});

const MODE_LABELS = { operator: 'Школьный оператор', admin: 'Только администратор' };

export default function OlympiadsIndex({ olympiads, filters, years, subjects, stages, levels = [], status_modes, ates = [], msus = [], max_extension_hours = 48, school_for_municipal = [] }) {
    const { errors, flash } = usePage().props;
    const [search, setSearch] = useState(filters.q ?? '');
    const [editingId, setEditingId] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [tab, setTab] = useState('main');
    const form = useForm(blank(years, subjects));

    // Создание МЭ из ШЭ текущего года.
    const [showMunFromShe, setShowMunFromShe] = useState(false);
    const munForm = useForm({ school_olympiad_ids: [] });
    const selectableShe = school_for_municipal.filter((s) => !s.has_municipal && s.date_held);
    const openMunFromShe = () => {
        munForm.setData('school_olympiad_ids', selectableShe.map((s) => s.id));
        munForm.clearErrors();
        setShowMunFromShe(true);
        // Снимаем «Скрыть ШЭ», чтобы школьный этап был виден при работе с источником.
        if (filters.hide_school) applyFilters({ hide_school: 0 });
    };
    const toggleShe = (id) => {
        const has = munForm.data.school_olympiad_ids.includes(id);
        munForm.setData('school_olympiad_ids', has
            ? munForm.data.school_olympiad_ids.filter((x) => x !== id)
            : [...munForm.data.school_olympiad_ids, id]);
    };
    const allSheSelected = selectableShe.length > 0 && selectableShe.every((s) => munForm.data.school_olympiad_ids.includes(s.id));
    const toggleAllShe = () => munForm.setData('school_olympiad_ids', allSheSelected ? [] : selectableShe.map((s) => s.id));
    const submitMunFromShe = (e) => {
        e.preventDefault();
        munForm.post(route('admin.olympiads.create-municipal'), { preserveScroll: true, onSuccess: () => setShowMunFromShe(false) });
    };

    // Продление ввода результатов (школьный этап). Держим id, объект берём из свежих props.
    const [extId, setExtId] = useState(null);
    const extOlympiad = olympiads.data.find((o) => o.id === extId) ?? null;
    const [extSchools, setExtSchools] = useState([]);
    const [extAteFilter, setExtAteFilter] = useState('');
    const extForm = useForm({ phase: 'primary', scope: 'all', ate_id: '', msu_id: '', school_id: '', hours: 24 });
    const openExtend = (o) => {
        setExtId(o.id);
        setExtAteFilter('');
        setExtSchools([]);
        extForm.clearErrors();
        extForm.setData({ phase: 'primary', scope: 'all', ate_id: '', msu_id: '', school_id: '', hours: 24 });
    };
    const loadExtSchools = (ateId) => {
        setExtAteFilter(ateId);
        extForm.setData('school_id', '');
        if (!ateId) return setExtSchools([]);
        fetch(route('admin.olympiads.schools', { ate_id: ateId }))
            .then((r) => r.json())
            .then(setExtSchools);
    };
    const submitExtend = (e) => {
        e.preventDefault();
        extForm.post(route('admin.olympiads.extend', extOlympiad.id), {
            preserveScroll: true, onSuccess: () => setExtId(null),
        });
    };
    const revokeExtension = (extId) => {
        if (confirm('Отменить это продление?')) {
            router.delete(route('admin.olympiads.extensions.destroy', extId), { preserveScroll: true });
        }
    };

    const startCreate = () => {
        setEditingId(null);
        setTab('main');
        form.setData(blank(years, subjects));
        form.clearErrors();
        setShowForm(true);
    };

    const startEdit = (o) => {
        setEditingId(o.id);
        setTab('main');
        form.setData({
            academic_year_id: years.find((y) => y.name === o.year)?.id ?? '',
            subject_id: subjects.find((s) => s.name === o.subject)?.id ?? '',
            stage: o.stage,
            level: o.level ?? 'regional',
            grades: o.grades?.length ? o.grades : [...ALL_GRADES],
            question_count: o.question_count ?? 0,
            max_scores: o.max_scores ?? {},
            thresholds: o.thresholds ?? {},
            auto_status_mode: o.auto_status_mode ?? 'operator',
            date_held: o.date_held ?? '',
            results_deadline: o.results_deadline ?? '',
            final_results_deadline: o.final_results_deadline ?? '',
        });
        form.clearErrors();
        setShowForm(true);
    };

    const submit = (e) => {
        e.preventDefault();
        const opts = {
            preserveScroll: true,
            onSuccess: () => setShowForm(false),
            // Переключаемся на вкладку с ошибкой, чтобы она была видна.
            onError: (errs) => {
                const statusKeys = ['thresholds', 'auto_status_mode'];
                const keys = Object.keys(errs);
                if (keys.some((k) => !statusKeys.includes(k))) setTab('main');
                else if (keys.length) setTab('status');
            },
        };
        if (editingId) {
            form.put(route('admin.olympiads.update', editingId), opts);
        } else {
            form.post(route('admin.olympiads.store'), opts);
        }
    };

    const publish = (o) => {
        if (confirm('Опубликовать результаты? Откроется 48-часовой онлайн-показ работ.')) {
            router.post(route('admin.olympiads.publish', o.id), {}, { preserveScroll: true });
        }
    };

    // Загрузка сканов МЭ ZIP-архивом по запросу конкретного АТЕ (имена файлов = шифры).
    const [scanId, setScanId] = useState(null);
    const scanOlympiad = olympiads.data.find((o) => o.id === scanId) ?? null;
    const scanForm = useForm({ ate_id: '', file: null });
    const openScans = (o) => {
        setScanId(o.id);
        scanForm.clearErrors();
        scanForm.setData({ ate_id: '', file: null });
    };
    const submitScans = (e) => {
        e.preventDefault();
        scanForm.post(route('admin.olympiads.scans', scanId), {
            preserveScroll: true, forceFormData: true,
            onSuccess: () => setScanId(null),
        });
    };

    const remove = (o) => {
        if (confirm('Удалить олимпиаду?')) {
            router.delete(route('admin.olympiads.destroy', o.id), { preserveScroll: true });
        }
    };

    const applyAutoStatus = (o) => {
        if (confirm('Расставить статусы по порогам для всех школ? Текущие статусы участий с баллом будут перезаписаны.')) {
            router.post(route('admin.olympiads.auto-status', o.id), {}, { preserveScroll: true });
        }
    };

    const applyFilters = (overrides = {}) => {
        const params = {
            year: filters.year ?? '', q: search, level: filters.level ?? '',
            hide_school: filters.hide_school ? 1 : 0, hide_municipal: filters.hide_municipal ? 1 : 0, ...overrides,
        };
        Object.keys(params).forEach((k) => {
            if (params[k] === '' || params[k] == null) delete params[k];
        });
        router.get(route('admin.olympiads.index'), params, { preserveState: true, preserveScroll: true, replace: true });
    };
    const runSearch = (e) => {
        e.preventDefault();
        applyFilters();
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">Олимпиады</h2>
            }
        >
            <Head title="Олимпиады" />

            <div className="py-8">
                <div className="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {errors?.olympiad && (
                        <div className="rounded bg-red-50 p-3 text-sm text-red-700">
                            {errors.olympiad}
                        </div>
                    )}
                    {errors?.file && (
                        <div className="rounded bg-amber-50 p-3 text-sm text-amber-700">{errors.file}</div>
                    )}
                    {flash?.import_skipped?.length > 0 && (
                        <div className="rounded bg-amber-50 p-3 text-sm text-amber-800">
                            Часть файлов не сопоставлена ({flash.import_skipped.length}):
                            <ul className="mt-1 list-disc pl-5 text-xs">
                                {flash.import_skipped.map((s, i) => <li key={i}>{s}</li>)}
                            </ul>
                        </div>
                    )}
                    {flash?.mun_create_skipped?.length > 0 && (
                        <div className="rounded bg-amber-50 p-3 text-sm text-amber-800">
                            Пропущено при создании МЭ ({flash.mun_create_skipped.length}):
                            <ul className="mt-1 list-disc pl-5 text-xs">
                                {flash.mun_create_skipped.map((s, i) => <li key={i}>{s}</li>)}
                            </ul>
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-3">
                        <select
                            value={filters.year ?? ''}
                            onChange={(e) => applyFilters({ year: e.target.value })}
                            className="rounded border-gray-300 text-sm"
                        >
                            <option value="">Все учебные годы</option>
                            {years.map((y) => (
                                <option key={y.id} value={y.id}>
                                    {y.name}
                                </option>
                            ))}
                        </select>
                        <select
                            value={filters.level ?? ''}
                            onChange={(e) => applyFilters({ level: e.target.value })}
                            className="rounded border-gray-300 text-sm"
                        >
                            <option value="">Все уровни</option>
                            {levels.map((l) => <option key={l} value={l}>{LEVEL_LABELS[l] ?? l}</option>)}
                        </select>
                        <form onSubmit={runSearch} className="flex gap-2">
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Поиск по предмету"
                                className="rounded border-gray-300 text-sm"
                            />
                            <button type="submit" className="rounded bg-gray-200 px-3 py-2 text-sm hover:bg-gray-300">Найти</button>
                            {filters.q && (
                                <button type="button" onClick={() => { setSearch(''); applyFilters({ q: '' }); }}
                                    className="rounded px-2 py-2 text-sm text-gray-500 hover:underline">Сброс</button>
                            )}
                        </form>
                        <label className="flex items-center gap-1.5 text-sm text-gray-600">
                            <input type="checkbox" checked={!!filters.hide_school}
                                onChange={(e) => applyFilters({ hide_school: e.target.checked ? 1 : 0 })} />
                            Скрыть ШЭ
                        </label>
                        <label className="flex items-center gap-1.5 text-sm text-gray-600">
                            <input type="checkbox" checked={!!filters.hide_municipal}
                                onChange={(e) => applyFilters({ hide_municipal: e.target.checked ? 1 : 0 })} />
                            Скрыть МЭ
                        </label>
                        <button
                            onClick={openMunFromShe}
                            className="ml-auto rounded border border-indigo-600 px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50"
                        >
                            Создать МЭ из ШЭ
                        </button>
                        <button
                            onClick={startCreate}
                            className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                        >
                            + Новая олимпиада
                        </button>
                    </div>

                    <Modal show={showMunFromShe} onClose={() => setShowMunFromShe(false)} maxWidth="2xl">
                        <form onSubmit={submitMunFromShe} className="space-y-4 p-6">
                            <h3 className="font-semibold text-gray-800">Создать олимпиады МЭ из ШЭ</h3>
                            <p className="text-xs text-gray-500">
                                Для каждой выбранной олимпиады школьного этапа текущего года создаётся муниципальная того же
                                предмета (копируются классы и уровень). Сроки считаются от даты проведения ШЭ:
                                проведение МЭ — <b>+1 месяц</b>; первичные результаты — <b>+1 мес +5 дн, 16:00</b>;
                                итоговые (после апелляций) — <b>+1 мес +8 дн, 16:00</b>. Предметы, по которым МЭ уже есть, недоступны.
                            </p>
                            {munForm.errors.school_olympiad_ids && <p className="text-xs text-red-600">{munForm.errors.school_olympiad_ids}</p>}

                            {school_for_municipal.length === 0 ? (
                                <p className="rounded bg-gray-50 p-4 text-sm text-gray-400">Нет олимпиад школьного этапа в текущем учебном году.</p>
                            ) : (
                                <>
                                    <label className="flex items-center gap-2 text-sm font-medium text-gray-700">
                                        <input type="checkbox" checked={allSheSelected} onChange={toggleAllShe} disabled={selectableShe.length === 0} />
                                        Выбрать все доступные ({selectableShe.length})
                                    </label>
                                    <div className="max-h-80 overflow-y-auto rounded border border-gray-200">
                                        <table className="min-w-full text-sm">
                                            <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                                <tr>
                                                    <th className="px-3 py-2"></th>
                                                    <th className="px-3 py-2">Предмет</th>
                                                    <th className="px-3 py-2">Классы</th>
                                                    <th className="px-3 py-2">Дата ШЭ</th>
                                                    <th className="px-3 py-2">МЭ (расчёт)</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-100">
                                                {school_for_municipal.map((s) => {
                                                    const disabled = s.has_municipal || !s.date_held;
                                                    return (
                                                        <tr key={s.id} className={disabled ? 'bg-gray-50 text-gray-400' : 'hover:bg-gray-50'}>
                                                            <td className="px-3 py-2">
                                                                <input type="checkbox" disabled={disabled}
                                                                    checked={munForm.data.school_olympiad_ids.includes(s.id)}
                                                                    onChange={() => toggleShe(s.id)} />
                                                            </td>
                                                            <td className="px-3 py-2 font-medium">{s.subject}</td>
                                                            <td className="px-3 py-2">{gradesLabel(s.grades)}</td>
                                                            <td className="px-3 py-2">{s.date_held ?? '—'}</td>
                                                            <td className="px-3 py-2 text-xs">
                                                                {s.has_municipal ? 'МЭ уже есть'
                                                                    : !s.date_held ? 'нет даты ШЭ'
                                                                    : munDatesPreview(s.date_held)}
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                </>
                            )}

                            <div className="flex justify-end gap-2">
                                <button type="button" onClick={() => setShowMunFromShe(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                                <button type="submit" disabled={munForm.processing || munForm.data.school_olympiad_ids.length === 0}
                                    className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                                    Создать ({munForm.data.school_olympiad_ids.length})
                                </button>
                            </div>
                        </form>
                    </Modal>

                    <Modal show={showForm} onClose={() => setShowForm(false)} maxWidth="2xl">
                        <form onSubmit={submit} className="grid gap-4 bg-white p-6 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <h3 className="font-semibold text-gray-800">
                                    {editingId ? 'Редактирование олимпиады' : 'Новая олимпиада'}
                                </h3>
                                <div className="mt-2 flex gap-2 border-b">
                                    {[['main', 'Олимпиада'], ['status', 'Статусы']].map(([key, label]) => (
                                        <button key={key} type="button" onClick={() => setTab(key)}
                                            className={`-mb-px border-b-2 px-3 py-2 text-sm ${
                                                tab === key
                                                    ? 'border-indigo-600 font-medium text-indigo-600'
                                                    : 'border-transparent text-gray-500 hover:text-gray-700'
                                            }`}>
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {tab === 'main' && (<>
                            <Field label="Учебный год" error={form.errors.academic_year_id}>
                                <select
                                    value={form.data.academic_year_id}
                                    onChange={(e) => form.setData('academic_year_id', e.target.value)}
                                    className="w-full rounded border-gray-300 text-sm"
                                >
                                    {years.map((y) => (
                                        <option key={y.id} value={y.id}>{y.name}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Предмет" error={form.errors.subject_id}>
                                <select
                                    value={form.data.subject_id}
                                    onChange={(e) => form.setData('subject_id', e.target.value)}
                                    className="w-full rounded border-gray-300 text-sm"
                                >
                                    {subjects.map((s) => (
                                        <option key={s.id} value={s.id}>{s.name}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Этап" error={form.errors.stage}>
                                <select
                                    value={form.data.stage}
                                    onChange={(e) => form.setData('stage', e.target.value)}
                                    className="w-full rounded border-gray-300 text-sm"
                                >
                                    {stages.map((s) => (
                                        <option key={s} value={s}>{STAGE_LABELS[s]}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Уровень" error={form.errors.level}>
                                <select
                                    value={form.data.level}
                                    onChange={(e) => form.setData('level', e.target.value)}
                                    className="w-full rounded border-gray-300 text-sm"
                                >
                                    <option value="regional">Региональный</option>
                                    <option value="republican">Республиканский</option>
                                </select>
                            </Field>
                            <Field label="Классы участия" error={form.errors.grades}>
                                <div className="flex flex-wrap items-center gap-2">
                                    {ALL_GRADES.map((g) => {
                                        const on = form.data.grades.includes(g);
                                        return (
                                            <button
                                                key={g}
                                                type="button"
                                                onClick={() =>
                                                    form.setData(
                                                        'grades',
                                                        on
                                                            ? form.data.grades.filter((x) => x !== g)
                                                            : [...form.data.grades, g].sort((a, b) => a - b),
                                                    )
                                                }
                                                className={`h-8 w-8 rounded text-sm ${
                                                    on
                                                        ? 'bg-indigo-600 text-white'
                                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                                }`}
                                            >
                                                {g}
                                            </button>
                                        );
                                    })}
                                    <button
                                        type="button"
                                        onClick={() => form.setData('grades', [...ALL_GRADES])}
                                        className="ml-2 text-xs text-indigo-600 hover:underline"
                                    >
                                        все
                                    </button>
                                </div>
                            </Field>
                            <Field label="Дата проведения" error={form.errors.date_held}>
                                <input
                                    type="date"
                                    value={form.data.date_held}
                                    onChange={(e) => form.setData('date_held', e.target.value)}
                                    className="w-full rounded border-gray-300 text-sm"
                                />
                            </Field>
                            <Field label="Количество заданий (0 — единый балл)" error={form.errors.question_count}>
                                <input
                                    type="number"
                                    min="0"
                                    max="60"
                                    value={form.data.question_count}
                                    onChange={(e) => form.setData('question_count', e.target.value)}
                                    className="w-full rounded border-gray-300 text-sm"
                                />
                            </Field>
                            <div className="sm:col-span-2">
                                <label className="mb-1 block text-xs font-medium text-gray-600">
                                    Макс. балл по классам (после проведения)
                                </label>
                                <div className="flex flex-wrap gap-3">
                                    {form.data.grades.map((g) => (
                                        <div key={g} className="flex items-center gap-1">
                                            <span className="w-10 text-sm text-gray-600">{g} кл.</span>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={form.data.max_scores?.[g] ?? ''}
                                                onChange={(e) =>
                                                    form.setData('max_scores', {
                                                        ...form.data.max_scores,
                                                        [g]: e.target.value,
                                                    })
                                                }
                                                className="w-24 rounded border-gray-300 text-sm"
                                            />
                                        </div>
                                    ))}
                                </div>
                                {form.errors.max_scores && (
                                    <p className="mt-1 text-xs text-red-600">{form.errors.max_scores}</p>
                                )}
                            </div>
                            </>)}

                            {tab === 'status' && (<>
                            <Field label="Расстановка статусов" error={form.errors.auto_status_mode}>
                                <select
                                    value={form.data.auto_status_mode}
                                    onChange={(e) => form.setData('auto_status_mode', e.target.value)}
                                    className="w-full rounded border-gray-300 text-sm"
                                >
                                    {(status_modes ?? ['operator', 'admin']).map((m) => (
                                        <option key={m} value={m}>{MODE_LABELS[m] ?? m}</option>
                                    ))}
                                </select>
                            </Field>

                            <div className="sm:col-span-2">
                                <label className="mb-1 block text-xs font-medium text-gray-600">
                                    Порог призёра по классам (балл; % от макс. — рядом)
                                </label>
                                <p className="mb-2 text-xs text-gray-400">
                                    Победитель — участник(и) с максимальным баллом в школе; этот статус оператор ставит вручную.
                                </p>
                                <div className="space-y-2">
                                    {form.data.grades.map((g) => {
                                        const max = Number(form.data.max_scores?.[g]) || 0;
                                        const t = form.data.thresholds?.[g] ?? {};
                                        const pct = (v) =>
                                            max && v !== '' && v != null ? ` (${Math.round((Number(v) / max) * 100)}%)` : '';
                                        const setT = (val) =>
                                            form.setData('thresholds', {
                                                ...form.data.thresholds,
                                                [g]: { prize_from: val },
                                            });
                                        return (
                                            <div key={g} className="flex flex-wrap items-center gap-2 text-sm">
                                                <span className="w-24 text-gray-600">
                                                    {g} кл.{max ? ` (макс ${max})` : ''}
                                                </span>
                                                {max ? (
                                                    <>
                                                        <span className="text-gray-500">Призёр от</span>
                                                        <input type="number" step="0.01" min="0"
                                                            value={t.prize_from ?? ''}
                                                            onChange={(e) => setT(e.target.value)}
                                                            className="w-20 rounded border-gray-300 text-sm" />
                                                        <span className="w-12 text-xs text-gray-400">{pct(t.prize_from)}</span>
                                                    </>
                                                ) : (
                                                    <span className="text-xs text-gray-400">введите макс. балл</span>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                                {form.errors.thresholds && (
                                    <p className="mt-1 text-xs text-red-600">{form.errors.thresholds}</p>
                                )}
                            </div>
                            </>)}

                            {tab === 'main' && (<>
                            {form.data.stage !== 'regional' && (
                                <Field
                                    label={
                                        form.data.stage === 'municipal'
                                            ? 'Срок внесения первичных результатов'
                                            : 'Крайний срок внесения результатов'
                                    }
                                    error={form.errors.results_deadline}
                                >
                                    <input
                                        type="datetime-local"
                                        value={form.data.results_deadline}
                                        onChange={(e) => form.setData('results_deadline', e.target.value)}
                                        className="w-full rounded border-gray-300 text-sm"
                                    />
                                </Field>
                            )}
                            {form.data.stage === 'municipal' && (
                                <Field
                                    label="Крайний срок итоговых результатов (после апелляций)"
                                    error={form.errors.final_results_deadline}
                                >
                                    <input
                                        type="datetime-local"
                                        value={form.data.final_results_deadline}
                                        onChange={(e) => form.setData('final_results_deadline', e.target.value)}
                                        className="w-full rounded border-gray-300 text-sm"
                                    />
                                </Field>
                            )}
                            </>)}

                            <div className="flex items-end gap-2 sm:col-span-2">
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="rounded bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                                >
                                    Сохранить
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setShowForm(false)}
                                    className="rounded bg-gray-200 px-4 py-2 text-sm hover:bg-gray-300"
                                >
                                    Отмена
                                </button>
                            </div>
                        </form>
                    </Modal>

                    <div className="overflow-hidden rounded bg-white shadow">
                        <table className="min-w-full text-sm">
                            <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <tr>
                                    <th className="px-4 py-3">Год</th>
                                    <th className="px-4 py-3">Предмет</th>
                                    <th className="px-4 py-3">Этап</th>
                                    <th className="px-4 py-3">Уровень</th>
                                    <th className="px-4 py-3">Классы</th>
                                    <th className="px-4 py-3">Участников</th>
                                    <th className="px-4 py-3">Опубл.</th>
                                    <th className="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {olympiads.data.map((o) => (
                                    <tr key={o.id}>
                                        <td className="px-4 py-3 text-gray-600">{o.year}</td>
                                        <td className="px-4 py-3 font-medium text-gray-800">
                                            {o.subject}
                                            <span className="ml-2 text-xs font-normal text-gray-400">#{o.id}</span>
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">{STAGE_LABELS[o.stage]}</td>
                                        <td className="px-4 py-3 text-gray-600">{LEVEL_LABELS[o.level] ?? o.level}</td>
                                        <td className="px-4 py-3 text-gray-600">{gradesLabel(o.grades)}</td>
                                        <td className="px-4 py-3 text-gray-600">{o.participants}</td>
                                        <td className="px-4 py-3 text-xs">
                                            {o.published_at
                                                ? <span className="text-green-700">{o.published_at}</span>
                                                : <span className="text-gray-400">—</span>}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {(o.stage === 'school' || o.stage === 'municipal') && (
                                                <button
                                                    onClick={() => openExtend(o)}
                                                    className="mr-3 text-blue-600 hover:underline"
                                                    title="Продлить ввод результатов"
                                                >
                                                    Продлить{o.extensions?.some((e) => e.active) ? ` (${o.extensions.filter((e) => e.active).length})` : ''}
                                                </button>
                                            )}
                                            {o.stage === 'municipal' && (
                                                <button
                                                    onClick={() => openScans(o)}
                                                    className="mr-3 text-purple-600 hover:underline"
                                                    title="Загрузить сканы работ ZIP-архивом для конкретного АТЕ (имена файлов = шифры участников)"
                                                >
                                                    Сканы (ZIP)
                                                </button>
                                            )}
                                            {o.stage === 'municipal' && (
                                                <a
                                                    href={route('help.show', 'scans')}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="mr-3 text-gray-400 hover:text-gray-600 hover:underline"
                                                    title="Инструкция по загрузке сканов"
                                                >
                                                    ?
                                                </a>
                                            )}
                                            {o.stage !== 'school' && !o.published && (
                                                <button
                                                    onClick={() => publish(o)}
                                                    className="mr-3 text-green-600 hover:underline"
                                                >
                                                    Опубликовать
                                                </button>
                                            )}
                                            {o.thresholds && Object.keys(o.thresholds).length > 0 && (
                                                <button
                                                    onClick={() => applyAutoStatus(o)}
                                                    className="mr-3 text-amber-600 hover:underline"
                                                    title="Расставить статусы по порогам для всех школ"
                                                >
                                                    Авто-статусы
                                                </button>
                                            )}
                                            <button
                                                onClick={() => startEdit(o)}
                                                className="mr-3 text-indigo-600 hover:underline"
                                            >
                                                Изменить
                                            </button>
                                            {o.participants === 0 && (
                                                <button
                                                    onClick={() => remove(o)}
                                                    className="text-red-600 hover:underline"
                                                >
                                                    Удалить
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex flex-wrap gap-1">
                        {olympiads.links.map((link, i) => (
                            <button
                                key={i}
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url)}
                                className={`rounded px-3 py-1 text-sm ${
                                    link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'
                                } ${!link.url ? 'opacity-40' : ''}`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                </div>
            </div>

            <Modal show={!!extOlympiad} onClose={() => setExtId(null)} maxWidth="lg">
                {extOlympiad && (() => {
                    const isMun = extOlympiad.stage === 'municipal';
                    const phaseDeadline = extForm.data.phase === 'appeal' ? extOlympiad.final_results_deadline : extOlympiad.results_deadline;
                    return (
                    <div className="space-y-4 p-6">
                        <h3 className="font-semibold text-gray-800">Продление ввода — {extOlympiad.subject}</h3>
                        {errors?.extend && <div className="rounded bg-red-50 p-2 text-sm text-red-700">{errors.extend}</div>}

                        {isMun && (
                            <div>
                                <label className="block text-xs font-medium text-gray-600">Фаза</label>
                                <select value={extForm.data.phase} onChange={(e) => extForm.setData('phase', e.target.value)}
                                    className="rounded border-gray-300 text-sm">
                                    <option value="primary">Первичные результаты</option>
                                    <option value="appeal">Добавочные баллы (апелляции)</option>
                                </select>
                            </div>
                        )}

                        {!phaseDeadline ? (
                            <p className="text-sm text-amber-700">
                                Сначала задайте срок для этой фазы в настройках олимпиады — от него считается потолок (+48 ч).
                            </p>
                        ) : (
                            <form onSubmit={submitExtend} className="space-y-3">
                                <p className="text-xs text-gray-500">
                                    Срок закрытия: {phaseDeadline.replace('T', ' ')}. Продление считается от текущего
                                    момента, но не далее +48 ч от срока закрытия.
                                </p>
                                <div className="flex gap-3">
                                    <div>
                                        <label className="block text-xs font-medium text-gray-600">Кому</label>
                                        <select value={extForm.data.scope}
                                            onChange={(e) => extForm.setData({ ...extForm.data, scope: e.target.value, ate_id: '', msu_id: '', school_id: '' })}
                                            className="rounded border-gray-300 text-sm">
                                            <option value="all">Всем</option>
                                            <option value="ate">Конкретному АТЕ</option>
                                            {!isMun && <option value="msu">Конкретному МСУ</option>}
                                            {!isMun && <option value="school">Конкретной школе</option>}
                                        </select>
                                    </div>
                                    <div className="w-28">
                                        <label className="block text-xs font-medium text-gray-600">Часов</label>
                                        <input type="number" min="1" max={max_extension_hours} value={extForm.data.hours}
                                            onChange={(e) => extForm.setData('hours', e.target.value)}
                                            className="w-full rounded border-gray-300 text-sm" />
                                    </div>
                                </div>
                                {extForm.errors.hours && <p className="text-xs text-red-600">{extForm.errors.hours}</p>}

                                {extForm.data.scope === 'ate' && (
                                    <select value={extForm.data.ate_id} onChange={(e) => extForm.setData('ate_id', e.target.value)}
                                        className="w-full rounded border-gray-300 text-sm">
                                        <option value="">— выберите АТЕ —</option>
                                        {ates.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                                    </select>
                                )}
                                {extForm.data.scope === 'msu' && (
                                    <select value={extForm.data.msu_id} onChange={(e) => extForm.setData('msu_id', e.target.value)}
                                        className="w-full rounded border-gray-300 text-sm">
                                        <option value="">— выберите МСУ —</option>
                                        {msus.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                                    </select>
                                )}
                                {extForm.data.scope === 'school' && (
                                    <div className="space-y-2">
                                        <select value={extAteFilter} onChange={(e) => loadExtSchools(e.target.value)}
                                            className="w-full rounded border-gray-300 text-sm">
                                            <option value="">— выберите АТЕ —</option>
                                            {ates.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                                        </select>
                                        <select value={extForm.data.school_id} disabled={!extAteFilter}
                                            onChange={(e) => extForm.setData('school_id', e.target.value)}
                                            className="w-full rounded border-gray-300 text-sm disabled:bg-gray-100">
                                            <option value="">{extAteFilter ? '— выберите школу —' : 'сначала АТЕ'}</option>
                                            {extSchools.map((s) => <option key={s.id} value={s.id}>{s.short_name}</option>)}
                                        </select>
                                    </div>
                                )}
                                {(extForm.errors.ate_id || extForm.errors.msu_id || extForm.errors.school_id) && (
                                    <p className="text-xs text-red-600">Выберите цель продления.</p>
                                )}

                                <div className="flex justify-end gap-2">
                                    <button type="button" onClick={() => setExtId(null)} className="rounded bg-gray-200 px-4 py-2 text-sm">Закрыть</button>
                                    <button type="submit" disabled={extForm.processing}
                                        className="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                                        Продлить
                                    </button>
                                </div>
                            </form>
                        )}

                        {extOlympiad.extensions?.length > 0 && (
                            <div className="border-t pt-3">
                                <h4 className="mb-1 text-xs font-medium uppercase text-gray-500">Действующие продления</h4>
                                <ul className="space-y-1 text-sm">
                                    {extOlympiad.extensions.map((e) => (
                                        <li key={e.id} className="flex items-center justify-between gap-2">
                                            <span className={e.active ? 'text-gray-700' : 'text-gray-400 line-through'}>
                                                {e.target}{e.phase === 'appeal' ? ' · апелляции' : ''} — до {e.extended_until}
                                            </span>
                                            <button onClick={() => revokeExtension(e.id)} className="text-red-600 hover:underline">Отменить</button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                    );
                })()}
            </Modal>

            {/* Загрузка сканов работ конкретного АТЕ */}
            <Modal show={!!scanOlympiad} onClose={() => setScanId(null)} maxWidth="lg">
                {scanOlympiad && (
                    <form onSubmit={submitScans} className="space-y-4 p-6">
                        <h3 className="font-semibold text-gray-800">Сканы работ — {scanOlympiad.subject}</h3>
                        <p className="text-xs text-gray-500">
                            Загрузка по запросу конкретного АТЕ. Имя каждого файла в архиве — шифр участника
                            (напр. «A-014.pdf»), форматы pdf/jpg/png. Сопоставляются с работами выбранного АТЕ;
                            чужие и несопоставленные — пропускаются.
                        </p>
                        <Field label="АТЕ" error={scanForm.errors.ate_id}>
                            <select
                                value={scanForm.data.ate_id}
                                onChange={(e) => scanForm.setData('ate_id', e.target.value)}
                                className="w-full rounded border-gray-300 text-sm"
                            >
                                <option value="">— выберите АТЕ —</option>
                                {ates.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                            </select>
                        </Field>
                        <Field label="Архив со сканами (ZIP)" error={scanForm.errors.file}>
                            <input
                                type="file"
                                accept=".zip"
                                onChange={(e) => scanForm.setData('file', e.target.files[0] ?? null)}
                                className="block w-full text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm"
                            />
                        </Field>
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setScanId(null)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                            <button type="submit" disabled={scanForm.processing || !scanForm.data.ate_id || !scanForm.data.file}
                                className="rounded bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 disabled:opacity-50">
                                Загрузить
                            </button>
                        </div>
                    </form>
                )}
            </Modal>

        </AuthenticatedLayout>
    );
}

function Field({ label, error, children }) {
    return (
        <div>
            <label className="mb-1 block text-xs font-medium text-gray-600">{label}</label>
            {children}
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}
