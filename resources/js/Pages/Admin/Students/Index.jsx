import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const STATUS_LABELS = {
    active: 'Активен',
    graduated: 'Выпустился',
    transferring: 'Переводится',
    departed: 'Выбыл',
};

const GENDER_LABELS = { male: 'М', female: 'Ж' };

export default function StudentsIndex({ students, filters, statuses, ates, schools }) {
    const { errors } = usePage().props;
    const [editingId, setEditingId] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [q, setQ] = useState(filters.q ?? '');
    const [ateFilter, setAteFilter] = useState('');
    // Состояние фильтров списка (район / школа / класс)
    const [fAte, setFAte] = useState(filters.ate_id ?? '');
    const [fSchool, setFSchool] = useState(filters.school_id ?? '');
    const [fGrade, setFGrade] = useState(filters.grade ?? '');

    const blank = {
        fio: '', birth_date: '', gender: '', snils: '', school_id: schools[0]?.id ?? '',
        real_grade: 1, status: 'active', ovz: '',
    };

    // null/'' — не указано, true — есть ОВЗ, false — нет
    const ovzToForm = (v) => (v === null || v === undefined ? '' : v ? '1' : '0');
    const form = useForm({ ...blank });

    const create = () => {
        setEditingId(null);
        setAteFilter('');
        form.setData({ ...blank });
        form.clearErrors();
        setShowForm(true);
    };
    const edit = (s) => {
        setEditingId(s.id);
        setAteFilter('');
        form.setData({
            fio: s.fio, birth_date: s.birth_date ?? '', gender: s.gender ?? '', snils: s.snils ?? '',
            school_id: s.school_id, real_grade: s.real_grade, status: s.status,
            ovz: ovzToForm(s.ovz),
        });
        form.clearErrors();
        setShowForm(true);
    };
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setShowForm(false) };
        editingId
            ? form.put(route('admin.students.update', editingId), opts)
            : form.post(route('admin.students.store'), opts);
    };
    const remove = (s) =>
        confirm(`Удалить учащегося «${s.fio}»?`) &&
        router.delete(route('admin.students.destroy', s.id), { preserveScroll: true });
    const applyFilters = (overrides = {}) => {
        const params = { q, ate_id: fAte, school_id: fSchool, grade: fGrade, ...overrides };
        Object.keys(params).forEach((k) => {
            if (params[k] === '' || params[k] == null) delete params[k];
        });
        router.get(route('admin.students.index'), params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const resetFilters = () => {
        setQ('');
        setFAte('');
        setFSchool('');
        setFGrade('');
        router.get(route('admin.students.index'), {}, { preserveState: true });
    };

    const search = (e) => {
        e.preventDefault();
        applyFilters();
    };

    // Школы для формы (создание/редактирование), сужаются выбором АТЕ в форме
    const schoolOptions = useMemo(
        () => (ateFilter ? schools.filter((s) => String(s.ate_id) === String(ateFilter)) : schools),
        [schools, ateFilter],
    );
    // Школы для фильтра списка, сужаются выбранным районом
    const filterSchoolOptions = useMemo(
        () => (fAte ? schools.filter((s) => String(s.ate_id) === String(fAte)) : schools),
        [schools, fAte],
    );

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">Учащиеся</h2>
            }
        >
            <Head title="Учащиеся" />

            <div className="py-8">
                <div className="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {errors?.student && (
                        <div className="rounded bg-red-50 p-3 text-sm text-red-700">{errors.student}</div>
                    )}

                    <div className="flex flex-wrap items-end gap-3 rounded bg-white p-4 shadow">
                        <div>
                            <label className="block text-xs text-gray-500">Район (АТЕ)</label>
                            <select
                                value={fAte}
                                onChange={(e) => {
                                    setFAte(e.target.value);
                                    setFSchool('');
                                    applyFilters({ ate_id: e.target.value, school_id: '' });
                                }}
                                className="rounded border-gray-300 text-sm"
                            >
                                <option value="">Все районы</option>
                                {ates.map((a) => (
                                    <option key={a.id} value={a.id}>{a.name}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">Школа</label>
                            <select
                                value={fSchool}
                                onChange={(e) => {
                                    setFSchool(e.target.value);
                                    applyFilters({ school_id: e.target.value });
                                }}
                                className="max-w-xs rounded border-gray-300 text-sm"
                            >
                                <option value="">Все школы</option>
                                {filterSchoolOptions.map((s) => (
                                    <option key={s.id} value={s.id}>{s.short_name}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">Класс</label>
                            <select
                                value={fGrade}
                                onChange={(e) => {
                                    setFGrade(e.target.value);
                                    applyFilters({ grade: e.target.value });
                                }}
                                className="rounded border-gray-300 text-sm"
                            >
                                <option value="">Все классы</option>
                                {Array.from({ length: 11 }, (_, i) => i + 1).map((g) => (
                                    <option key={g} value={g}>{g}</option>
                                ))}
                            </select>
                        </div>
                        <form onSubmit={search} className="flex gap-2">
                            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Поиск: ФИО или СНИЛС"
                                className="rounded border-gray-300 text-sm" />
                            <button className="rounded bg-gray-200 px-3 py-2 text-sm hover:bg-gray-300">Найти</button>
                        </form>
                        <button type="button" onClick={resetFilters}
                            className="rounded px-3 py-2 text-sm text-gray-500 hover:text-gray-700">
                            Сбросить
                        </button>
                        <button onClick={create} className="ml-auto rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            + Новый учащийся
                        </button>
                    </div>

                    {showForm && (
                        <form onSubmit={submit} className="grid gap-3 rounded bg-white p-6 shadow sm:grid-cols-2">
                            <Field label="ФИО" error={form.errors.fio}>
                                <input value={form.data.fio} onChange={(e) => form.setData('fio', e.target.value)}
                                    className="w-full rounded border-gray-300 text-sm" />
                            </Field>
                            <Field label="Дата рождения" error={form.errors.birth_date}>
                                <input type="date" value={form.data.birth_date}
                                    onChange={(e) => form.setData('birth_date', e.target.value)}
                                    className="w-full rounded border-gray-300 text-sm" />
                            </Field>
                            <Field label="Пол" error={form.errors.gender}>
                                <select value={form.data.gender} onChange={(e) => form.setData('gender', e.target.value)}
                                    className="w-full rounded border-gray-300 text-sm">
                                    <option value="">Не указано</option>
                                    <option value="male">Мужской</option>
                                    <option value="female">Женский</option>
                                </select>
                            </Field>
                            <Field label="СНИЛС" error={form.errors.snils}>
                                <input value={form.data.snils} onChange={(e) => form.setData('snils', e.target.value)}
                                    placeholder="123-456-789 00" className="w-full rounded border-gray-300 text-sm" />
                            </Field>
                            <Field label="Школа" error={form.errors.school_id}>
                                <select value={ateFilter} onChange={(e) => setAteFilter(e.target.value)}
                                    className="mb-1 w-full rounded border-gray-300 text-xs text-gray-500">
                                    <option value="">Фильтр по АТЕ (необязательно)</option>
                                    {ates.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                                </select>
                                <select value={form.data.school_id} onChange={(e) => form.setData('school_id', e.target.value)}
                                    className="w-full rounded border-gray-300 text-sm">
                                    {schoolOptions.map((s) => <option key={s.id} value={s.id}>{s.short_name}</option>)}
                                </select>
                            </Field>
                            <Field label="Класс обучения" error={form.errors.real_grade}>
                                <select value={form.data.real_grade}
                                    onChange={(e) => form.setData('real_grade', Number(e.target.value))}
                                    className="w-full rounded border-gray-300 text-sm">
                                    {Array.from({ length: 11 }, (_, i) => i + 1).map((g) => (
                                        <option key={g} value={g}>{g}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Статус" error={form.errors.status}>
                                <select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}
                                    className="w-full rounded border-gray-300 text-sm">
                                    {statuses.map((s) => <option key={s} value={s}>{STATUS_LABELS[s]}</option>)}
                                </select>
                            </Field>
                            <Field label="ОВЗ" error={form.errors.ovz}>
                                <select value={form.data.ovz} onChange={(e) => form.setData('ovz', e.target.value)}
                                    className="w-full rounded border-gray-300 text-sm">
                                    <option value="">Не указано</option>
                                    <option value="1">Есть ОВЗ</option>
                                    <option value="0">Нет</option>
                                </select>
                            </Field>
                            <div className="flex items-end gap-2">
                                <button className="rounded bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                                    Сохранить
                                </button>
                                <button type="button" onClick={() => setShowForm(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">
                                    Отмена
                                </button>
                            </div>
                        </form>
                    )}

                    <div className="overflow-hidden rounded bg-white shadow">
                        <table className="min-w-full text-sm">
                            <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <tr>
                                    <th className="px-4 py-3">ФИО</th>
                                    <th className="px-4 py-3">Дата рожд.</th>
                                    <th className="px-4 py-3">Пол</th>
                                    <th className="px-4 py-3">Класс</th>
                                    <th className="px-4 py-3">Школа</th>
                                    <th className="px-4 py-3">Статус</th>
                                    <th className="px-4 py-3">ОВЗ</th>
                                    <th className="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {students.data.map((s) => (
                                    <tr key={s.id} className={s.anonymized ? 'bg-gray-50 text-gray-400' : ''}>
                                        <td className="px-4 py-2">
                                            {s.fio}
                                            {s.anonymized && (
                                                <span className="ml-2 rounded bg-gray-200 px-1 text-xs">ПДн удалены</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2">{s.birth_date}</td>
                                        <td className="px-4 py-2">{GENDER_LABELS[s.gender] ?? '—'}</td>
                                        <td className="px-4 py-2">{s.real_grade}</td>
                                        <td className="px-4 py-2">{s.school}</td>
                                        <td className="px-4 py-2">{STATUS_LABELS[s.status]}</td>
                                        <td className="px-4 py-2">
                                            {s.ovz === true ? 'Есть' : s.ovz === false ? 'Нет' : '—'}
                                        </td>
                                        <td className="px-4 py-2 text-right">
                                            {!s.anonymized && (
                                                <button onClick={() => edit(s)} className="mr-3 text-indigo-600 hover:underline">
                                                    Изменить
                                                </button>
                                            )}
                                            <button onClick={() => remove(s)} className="text-red-600 hover:underline">
                                                Удалить
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex flex-wrap gap-1">
                        {students.links.map((link, i) => (
                            <button key={i} disabled={!link.url} onClick={() => link.url && router.get(link.url)}
                                className={`rounded px-3 py-1 text-sm ${link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'} ${!link.url ? 'opacity-40' : ''}`}
                                dangerouslySetInnerHTML={{ __html: link.label }} />
                        ))}
                    </div>
                </div>
            </div>
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
