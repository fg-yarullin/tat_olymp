import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';

const ATE_TYPE_LABELS = { isolated: 'Обособленная', unified: 'Объединённая' };
const LEVEL_LABELS = { 1: 'Начальная', 2: 'Основная', 3: 'Средняя' };
const TERRITORY_LABELS = { city: 'Город', rural: 'Село' };

function Field({ label, error, children }) {
    return (
        <div>
            <label className="mb-1 block text-xs font-medium text-gray-600">{label}</label>
            {children}
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}

function AteTab({ ates }) {
    const [editingId, setEditingId] = useState(null);
    const form = useForm({ ate_code: '', name: '', type: 'isolated' });
    const reset = () => {
        setEditingId(null);
        form.setData({ ate_code: '', name: '', type: 'isolated' });
        form.clearErrors();
    };
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: reset };
        editingId
            ? form.put(route('admin.territory.ate.update', editingId), opts)
            : form.post(route('admin.territory.ate.store'), opts);
    };
    const edit = (a) => {
        setEditingId(a.id);
        form.setData({ ate_code: a.ate_code, name: a.name, type: a.type });
        form.clearErrors();
    };
    const remove = (a) =>
        confirm(`Удалить АТЕ «${a.name}»?`) &&
        router.delete(route('admin.territory.ate.destroy', a.id), { preserveScroll: true });

    return (
        <div className="space-y-4">
            <form onSubmit={submit} className="grid gap-3 rounded bg-white p-6 shadow sm:grid-cols-4">
                <Field label="Код АТЕ" error={form.errors.ate_code}>
                    <input value={form.data.ate_code} onChange={(e) => form.setData('ate_code', e.target.value)}
                        className="w-full rounded border-gray-300 text-sm" />
                </Field>
                <Field label="Название" error={form.errors.name}>
                    <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)}
                        className="w-full rounded border-gray-300 text-sm" />
                </Field>
                <Field label="Тип" error={form.errors.type}>
                    <select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}
                        className="w-full rounded border-gray-300 text-sm">
                        <option value="isolated">Обособленная</option>
                        <option value="unified">Объединённая</option>
                    </select>
                </Field>
                <div className="flex items-end gap-2">
                    <button className="rounded bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                        {editingId ? 'Сохранить' : 'Добавить'}
                    </button>
                    {editingId && (
                        <button type="button" onClick={reset} className="rounded bg-gray-200 px-3 py-2 text-sm">
                            Отмена
                        </button>
                    )}
                </div>
            </form>
            <Table
                head={['Код', 'Название', 'Тип', 'МСУ', 'Школ', '']}
                rows={ates}
                render={(a) => (
                    <tr key={a.id}>
                        <td className="px-4 py-2">{a.ate_code}</td>
                        <td className="px-4 py-2 font-medium text-gray-800">{a.name}</td>
                        <td className="px-4 py-2 text-gray-600">{ATE_TYPE_LABELS[a.type]}</td>
                        <td className="px-4 py-2 text-gray-600">{a.msus_count}</td>
                        <td className="px-4 py-2 text-gray-600">{a.schools_count}</td>
                        <td className="px-4 py-2 text-right">
                            <button onClick={() => edit(a)} className="mr-3 text-indigo-600 hover:underline">Изменить</button>
                            {a.msus_count === 0 && a.schools_count === 0 && (
                                <button onClick={() => remove(a)} className="text-red-600 hover:underline">Удалить</button>
                            )}
                        </td>
                    </tr>
                )}
            />
        </div>
    );
}

function MsuTab({ msus, ateList }) {
    const [editingId, setEditingId] = useState(null);
    const form = useForm({ msu_code: '', name: '', ate_id: ateList[0]?.id ?? '' });
    const reset = () => {
        setEditingId(null);
        form.setData({ msu_code: '', name: '', ate_id: ateList[0]?.id ?? '' });
        form.clearErrors();
    };
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: reset };
        editingId
            ? form.put(route('admin.territory.msu.update', editingId), opts)
            : form.post(route('admin.territory.msu.store'), opts);
    };
    const edit = (m) => {
        setEditingId(m.id);
        form.setData({ msu_code: m.msu_code, name: m.name, ate_id: m.ate_id });
        form.clearErrors();
    };
    const remove = (m) =>
        confirm(`Удалить МСУ «${m.name}»?`) &&
        router.delete(route('admin.territory.msu.destroy', m.id), { preserveScroll: true });

    return (
        <div className="space-y-4">
            <form onSubmit={submit} className="grid gap-3 rounded bg-white p-6 shadow sm:grid-cols-4">
                <Field label="Код МСУ" error={form.errors.msu_code}>
                    <input value={form.data.msu_code} onChange={(e) => form.setData('msu_code', e.target.value)}
                        className="w-full rounded border-gray-300 text-sm" />
                </Field>
                <Field label="Название" error={form.errors.name}>
                    <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)}
                        className="w-full rounded border-gray-300 text-sm" />
                </Field>
                <Field label="АТЕ" error={form.errors.ate_id}>
                    <select value={form.data.ate_id} onChange={(e) => form.setData('ate_id', e.target.value)}
                        className="w-full rounded border-gray-300 text-sm">
                        {ateList.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                    </select>
                </Field>
                <div className="flex items-end gap-2">
                    <button className="rounded bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                        {editingId ? 'Сохранить' : 'Добавить'}
                    </button>
                    {editingId && (
                        <button type="button" onClick={reset} className="rounded bg-gray-200 px-3 py-2 text-sm">Отмена</button>
                    )}
                </div>
            </form>
            <Table
                head={['Код', 'Название', 'АТЕ', 'Школ', '']}
                rows={msus}
                render={(m) => (
                    <tr key={m.id}>
                        <td className="px-4 py-2">{m.msu_code}</td>
                        <td className="px-4 py-2 font-medium text-gray-800">{m.name}</td>
                        <td className="px-4 py-2 text-gray-600">{m.ate}</td>
                        <td className="px-4 py-2 text-gray-600">{m.schools_count}</td>
                        <td className="px-4 py-2 text-right">
                            <button onClick={() => edit(m)} className="mr-3 text-indigo-600 hover:underline">Изменить</button>
                            {m.schools_count === 0 && (
                                <button onClick={() => remove(m)} className="text-red-600 hover:underline">Удалить</button>
                            )}
                        </td>
                    </tr>
                )}
            />
        </div>
    );
}

function SchoolTab({ schools, msuList, ateList, typeList, filters }) {
    const [editingId, setEditingId] = useState(null);
    const [editingCode, setEditingCode] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [q, setQ] = useState(filters.school_q ?? '');
    const [fAte, setFAte] = useState(filters.school_ate ?? '');
    const [fMsu, setFMsu] = useState(filters.school_msu ?? '');
    const searchTimer = useRef(null);
    const blank = { short_name: '', full_name: '', education_level: 3, territorial_sign: 'city', msu_id: msuList[0]?.id ?? '', school_type_id: typeList[0]?.id ?? '' };
    const form = useForm({ ...blank });

    const create = () => {
        setEditingId(null);
        setEditingCode(null);
        form.setData({ ...blank });
        form.clearErrors();
        setShowForm(true);
    };
    const edit = (s) => {
        setEditingId(s.id);
        setEditingCode(s.oo_code);
        form.setData({
            short_name: s.short_name, full_name: s.full_name,
            education_level: s.education_level, territorial_sign: s.territorial_sign,
            msu_id: s.msu_id, school_type_id: s.school_type_id ?? (typeList[0]?.id ?? ''),
        });
        form.clearErrors();
        setShowForm(true);
    };
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setShowForm(false) };
        editingId
            ? form.put(route('admin.territory.school.update', editingId), opts)
            : form.post(route('admin.territory.school.store'), opts);
    };
    const remove = (s) =>
        confirm(`Удалить школу «${s.short_name}»?`) &&
        router.delete(route('admin.territory.school.destroy', s.id), { preserveScroll: true });
    const applyFilters = (overrides = {}) => {
        const params = { school_q: q, school_ate: fAte, school_msu: fMsu, ...overrides };
        Object.keys(params).forEach((k) => {
            if (params[k] === '' || params[k] == null) delete params[k];
        });
        router.get(route('admin.territory.index'), params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['schools', 'filters'],
        });
    };
    // Реактивный поиск: применяем с небольшой задержкой; очистка поля сбрасывает результаты.
    const onSearch = (val) => {
        setQ(val);
        clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(() => applyFilters({ school_q: val }), 300);
    };
    // Смена АТЕ сбрасывает выбор МСУ (МСУ зависят от АТЕ).
    const onAte = (val) => {
        setFAte(val);
        setFMsu('');
        applyFilters({ school_ate: val, school_msu: '' });
    };
    const onMsu = (val) => {
        setFMsu(val);
        applyFilters({ school_msu: val });
    };
    const msuOptions = fAte ? msuList.filter((m) => String(m.ate_id) === String(fAte)) : msuList;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <select value={fAte} onChange={(e) => onAte(e.target.value)} className="rounded border-gray-300 text-sm">
                    <option value="">Все АТЕ</option>
                    {ateList.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                </select>
                <select value={fMsu} onChange={(e) => onMsu(e.target.value)} className="rounded border-gray-300 text-sm">
                    <option value="">Все МСУ</option>
                    {msuOptions.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                </select>
                <input value={q} onChange={(e) => onSearch(e.target.value)} placeholder="Поиск: название или код ОО"
                    className="rounded border-gray-300 text-sm" />
                {(q || fAte || fMsu) && (
                    <button onClick={() => { setQ(''); setFAte(''); setFMsu(''); applyFilters({ school_q: '', school_ate: '', school_msu: '' }); }}
                        className="text-sm text-gray-500 hover:underline">Сбросить</button>
                )}
                <button onClick={create} className="ml-auto rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    + Новая школа
                </button>
            </div>

            <Modal show={showForm} onClose={() => setShowForm(false)} maxWidth="2xl">
                <form onSubmit={submit} className="grid gap-3 p-6 sm:grid-cols-2">
                    <h3 className="font-semibold text-gray-800 sm:col-span-2">{editingId ? 'Редактирование школы' : 'Новая школа'}</h3>
                    <Field label="Код ОО (присваивается автоматически)">
                        <input value={editingId ? editingCode : 'МСУ + тип + порядковый'} readOnly disabled
                            className="w-full rounded border-gray-200 bg-gray-50 text-sm text-gray-500" />
                    </Field>
                    <Field label="Тип ОО (3-я цифра кода)" error={form.errors.school_type_id}>
                        <select value={form.data.school_type_id} onChange={(e) => form.setData('school_type_id', Number(e.target.value))}
                            className="w-full rounded border-gray-300 text-sm">
                            {typeList.map((t) => <option key={t.id} value={t.id}>{t.digit} — {t.name}</option>)}
                        </select>
                    </Field>
                    <Field label="МСУ (определяет АТЕ и коды)" error={form.errors.msu_id}>
                        <select value={form.data.msu_id} onChange={(e) => form.setData('msu_id', e.target.value)}
                            className="w-full rounded border-gray-300 text-sm">
                            {msuList.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                        </select>
                    </Field>
                    <Field label="Краткое название" error={form.errors.short_name}>
                        <input value={form.data.short_name} onChange={(e) => form.setData('short_name', e.target.value)}
                            className="w-full rounded border-gray-300 text-sm" />
                    </Field>
                    <Field label="Полное название" error={form.errors.full_name}>
                        <input value={form.data.full_name} onChange={(e) => form.setData('full_name', e.target.value)}
                            className="w-full rounded border-gray-300 text-sm" />
                    </Field>
                    <Field label="Уровень" error={form.errors.education_level}>
                        <select value={form.data.education_level} onChange={(e) => form.setData('education_level', Number(e.target.value))}
                            className="w-full rounded border-gray-300 text-sm">
                            <option value={1}>Начальная</option>
                            <option value={2}>Основная</option>
                            <option value={3}>Средняя</option>
                        </select>
                    </Field>
                    <Field label="Территория" error={form.errors.territorial_sign}>
                        <select value={form.data.territorial_sign} onChange={(e) => form.setData('territorial_sign', e.target.value)}
                            className="w-full rounded border-gray-300 text-sm">
                            <option value="city">Город</option>
                            <option value="rural">Село</option>
                        </select>
                    </Field>
                    <div className="flex items-center justify-end gap-2 sm:col-span-2">
                        <button type="button" onClick={() => setShowForm(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button disabled={form.processing} className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </Modal>

            <Table
                head={['Код', 'Название', 'МСУ', 'АТЕ', 'Уровень', 'Терр.', '']}
                rows={schools.data}
                render={(s) => (
                    <tr key={s.id}>
                        <td className="px-4 py-2">{s.oo_code}</td>
                        <td className="px-4 py-2 text-gray-800">{s.short_name}</td>
                        <td className="px-4 py-2 text-gray-600">{s.msu}</td>
                        <td className="px-4 py-2 text-gray-600">{s.ate}</td>
                        <td className="px-4 py-2 text-gray-600">{LEVEL_LABELS[s.education_level]}</td>
                        <td className="px-4 py-2 text-gray-600">{TERRITORY_LABELS[s.territorial_sign]}</td>
                        <td className="px-4 py-2 text-right">
                            <button onClick={() => edit(s)} className="mr-3 text-indigo-600 hover:underline">Изменить</button>
                            <button onClick={() => remove(s)} className="text-red-600 hover:underline">Удалить</button>
                        </td>
                    </tr>
                )}
            />

            <div className="flex flex-wrap gap-1">
                {schools.links.map((link, i) => (
                    <button key={i} disabled={!link.url}
                        onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })}
                        className={`rounded px-3 py-1 text-sm ${link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'} ${!link.url ? 'opacity-40' : ''}`}
                        dangerouslySetInnerHTML={{ __html: link.label }} />
                ))}
            </div>
        </div>
    );
}

function Table({ head, rows, render }) {
    return (
        <div className="overflow-hidden rounded bg-white shadow">
            <table className="min-w-full text-sm">
                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>{head.map((h, i) => <th key={i} className="px-4 py-3">{h}</th>)}</tr>
                </thead>
                <tbody className="divide-y divide-gray-100">{rows.map(render)}</tbody>
            </table>
        </div>
    );
}

function TypeTab({ types }) {
    const [editingId, setEditingId] = useState(null);
    const form = useForm({ digit: '', name: '' });
    const reset = () => {
        setEditingId(null);
        form.setData({ digit: '', name: '' });
        form.clearErrors();
    };
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: reset };
        editingId
            ? form.put(route('admin.territory.school-type.update', editingId), opts)
            : form.post(route('admin.territory.school-type.store'), opts);
    };
    const edit = (t) => {
        setEditingId(t.id);
        form.setData({ digit: t.digit, name: t.name });
        form.clearErrors();
    };
    const remove = (t) =>
        confirm(`Удалить тип «${t.name}»?`) &&
        router.delete(route('admin.territory.school-type.destroy', t.id), { preserveScroll: true });

    return (
        <div className="space-y-4">
            <p className="text-sm text-gray-500">
                Тип ОО (организационно-правовая форма) задаёт <b>3-ю цифру кода ОО</b>. Код школы собирается автоматически:
                код МСУ (2) + цифра типа (1) + порядковый номер (3).
            </p>
            <form onSubmit={submit} className="grid gap-3 rounded bg-white p-6 shadow sm:grid-cols-4">
                <Field label="Цифра (0–9)" error={form.errors.digit}>
                    <input type="number" min="0" max="9" value={form.data.digit} onChange={(e) => form.setData('digit', e.target.value)}
                        className="w-full rounded border-gray-300 text-sm" />
                </Field>
                <Field label="Название" error={form.errors.name}>
                    <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)}
                        className="w-full rounded border-gray-300 text-sm" />
                </Field>
                <div className="flex items-end gap-2">
                    <button className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" disabled={form.processing}>
                        {editingId ? 'Сохранить' : 'Добавить'}
                    </button>
                    {editingId && <button type="button" onClick={reset} className="rounded bg-gray-200 px-3 py-2 text-sm">Отмена</button>}
                </div>
            </form>

            <div className="overflow-hidden rounded-lg bg-white shadow">
                <table className="min-w-full text-sm">
                    <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th className="px-6 py-3">Цифра</th>
                            <th className="px-6 py-3">Название</th>
                            <th className="px-6 py-3">Школ</th>
                            <th className="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {types.map((t) => (
                            <tr key={t.id} className="hover:bg-gray-50">
                                <td className="px-6 py-3 font-mono font-medium text-gray-800">{t.digit}</td>
                                <td className="px-6 py-3 text-gray-700">{t.name}</td>
                                <td className="px-6 py-3 text-gray-500">{t.schools_count}</td>
                                <td className="px-6 py-3 text-right whitespace-nowrap">
                                    <button onClick={() => edit(t)} className="mr-3 text-xs text-indigo-600 hover:underline">изм.</button>
                                    <button onClick={() => remove(t)} className="text-xs text-red-600 hover:underline disabled:opacity-40"
                                        disabled={t.schools_count > 0} title={t.schools_count > 0 ? 'Есть школы этого типа' : ''}>удал.</button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export default function TerritoryIndex({ ates, msus, schools, schoolTypes, filters, ateList, msuList, typeList }) {
    const { errors } = usePage().props;
    const [tab, setTab] = useState('ate');

    const tabs = [
        ['ate', 'АТЕ'],
        ['msu', 'МСУ'],
        ['school', 'Школы (ОО)'],
        ['type', 'Типы ОО'],
    ];

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Территориальные справочники
                </h2>
            }
        >
            <Head title="Справочники АТЕ/МСУ/ОО" />

            <div className="py-8">
                <div className="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {(errors?.ate || errors?.msu || errors?.school || errors?.school_type) && (
                        <div className="rounded bg-red-50 p-3 text-sm text-red-700">
                            {errors.ate || errors.msu || errors.school || errors.school_type}
                        </div>
                    )}

                    <div className="flex gap-2 border-b">
                        {tabs.map(([key, label]) => (
                            <button
                                key={key}
                                onClick={() => setTab(key)}
                                className={`px-4 py-2 text-sm font-medium ${
                                    tab === key
                                        ? 'border-b-2 border-indigo-600 text-indigo-700'
                                        : 'text-gray-500 hover:text-gray-700'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>

                    {tab === 'ate' && <AteTab ates={ates} />}
                    {tab === 'msu' && <MsuTab msus={msus} ateList={ateList} />}
                    {tab === 'school' && (
                        <SchoolTab schools={schools} msuList={msuList} ateList={ateList} typeList={typeList} filters={filters} />
                    )}
                    {tab === 'type' && <TypeTab types={schoolTypes} />}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
