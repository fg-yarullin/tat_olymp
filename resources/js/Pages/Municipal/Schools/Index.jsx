import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

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

export default function MunicipalSchoolsIndex({ schools, filters, ateList, msuList, typeList, multiAte, withoutOperatorCount = 0 }) {
    const [editingId, setEditingId] = useState(null);
    const [editingCode, setEditingCode] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [q, setQ] = useState(filters.school_q ?? '');
    const [fAte, setFAte] = useState(filters.school_ate ?? '');
    const [fMsu, setFMsu] = useState(filters.school_msu ?? '');
    const [fNoOp, setFNoOp] = useState(!!filters.without_operator);
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
            ? form.put(route('municipal.schools.update', editingId), opts)
            : form.post(route('municipal.schools.store'), opts);
    };

    const applyFilters = (overrides = {}) => {
        const params = { school_q: q, school_ate: fAte, school_msu: fMsu, without_operator: fNoOp ? 1 : '', ...overrides };
        Object.keys(params).forEach((k) => {
            if (params[k] === '' || params[k] == null) delete params[k];
        });
        router.get(route('municipal.schools.index'), params, { preserveState: true, preserveScroll: true, replace: true, only: ['schools', 'filters'] });
    };
    const onNoOp = (checked) => { setFNoOp(checked); applyFilters({ without_operator: checked ? 1 : '' }); };
    const onSearch = (val) => {
        setQ(val);
        clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(() => applyFilters({ school_q: val }), 300);
    };
    const onAte = (val) => { setFAte(val); setFMsu(''); applyFilters({ school_ate: val, school_msu: '' }); };
    const onMsu = (val) => { setFMsu(val); applyFilters({ school_msu: val }); };
    const msuOptions = fAte ? msuList.filter((m) => String(m.ate_id) === String(fAte)) : msuList;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Школы</h2>}>
            <Head title="Школы" />

            <div className="py-8">
                <div className="mx-auto max-w-6xl space-y-4 px-4 sm:px-6 lg:px-8">
                    <div className="flex flex-wrap items-center gap-3">
                        {multiAte && (
                            <select value={fAte} onChange={(e) => onAte(e.target.value)} className="rounded border-gray-300 text-sm">
                                <option value="">Все АТЕ</option>
                                {ateList.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                            </select>
                        )}
                        <select value={fMsu} onChange={(e) => onMsu(e.target.value)} className="rounded border-gray-300 text-sm">
                            <option value="">Все МСУ</option>
                            {msuOptions.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                        </select>
                        <input value={q} onChange={(e) => onSearch(e.target.value)} placeholder="Поиск: название или код ОО"
                            className="rounded border-gray-300 text-sm" />
                        <label className="flex items-center gap-1.5 text-sm text-gray-600">
                            <input type="checkbox" checked={fNoOp} onChange={(e) => onNoOp(e.target.checked)} />
                            Только без оператора
                            {withoutOperatorCount > 0 && <span className="rounded bg-red-100 px-1.5 text-xs font-medium text-red-700">{withoutOperatorCount}</span>}
                        </label>
                        {(q || fAte || fMsu || fNoOp) && (
                            <button onClick={() => { setQ(''); setFAte(''); setFMsu(''); setFNoOp(false); applyFilters({ school_q: '', school_ate: '', school_msu: '', without_operator: '' }); }}
                                className="text-sm text-gray-500 hover:underline">Сбросить</button>
                        )}
                        <button onClick={create} className="ml-auto rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            + Новая школа
                        </button>
                    </div>

                    <div className="overflow-hidden rounded bg-white shadow">
                        <table className="min-w-full text-sm">
                            <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <tr>
                                    {['Код', 'Название', 'Тип', 'МСУ', 'АТЕ', 'Уровень', 'Терр.', 'Оператор', ''].map((h, i) => <th key={i} className="px-4 py-3">{h}</th>)}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {schools.data.length === 0 ? (
                                    <tr><td colSpan={9} className="px-4 py-10 text-center text-gray-400">Школы не найдены.</td></tr>
                                ) : schools.data.map((s) => (
                                    <tr key={s.id} className={s.has_operator ? 'hover:bg-gray-50' : 'bg-red-50 hover:bg-red-100'}>
                                        <td className="px-4 py-2 font-mono">{s.oo_code}</td>
                                        <td className="px-4 py-2 text-gray-800">{s.short_name}</td>
                                        <td className="px-4 py-2 text-gray-500">{s.school_type ?? '—'}</td>
                                        <td className="px-4 py-2 text-gray-600">{s.msu}</td>
                                        <td className="px-4 py-2 text-gray-600">{s.ate}</td>
                                        <td className="px-4 py-2 text-gray-600">{LEVEL_LABELS[s.education_level]}</td>
                                        <td className="px-4 py-2 text-gray-600">{TERRITORY_LABELS[s.territorial_sign]}</td>
                                        <td className="px-4 py-2">
                                            {s.has_operator
                                                ? <span className="text-xs text-green-700">есть</span>
                                                : <span className="rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-700">нет</span>}
                                        </td>
                                        <td className="px-4 py-2 text-right">
                                            <button onClick={() => edit(s)} className="text-indigo-600 hover:underline">Изменить</button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {schools.links.length > 3 && (
                        <div className="flex flex-wrap gap-1">
                            {schools.links.map((link, i) => (
                                <button key={i} disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })}
                                    className={`rounded px-3 py-1 text-sm ${link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'} ${!link.url ? 'opacity-40' : ''}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }} />
                            ))}
                        </div>
                    )}
                </div>
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
                    <Field label="МСУ (определяет коды)" error={form.errors.msu_id}>
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
        </AuthenticatedLayout>
    );
}
