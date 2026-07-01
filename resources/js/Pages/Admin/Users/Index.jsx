import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const BLANK = {
    name: '',
    email: '',
    password: '',
    role: 'school_operator',
    is_active: true,
    ate_id: '',
    ate_ids: [],
    school_id: '',
};

// Супер-координатор Казани ведёт НАБОР АТЕ (мультивыбор), обычный координатор — один.
const needsAteMulti = (role) => role === 'super_coordinator';
const needsAteSingle = (role) => role === 'municipal_coordinator' || role === 'commission_chair';
const needsSchool = (role) => role === 'school_operator';

export default function UsersIndex({ users, filters, roles, ates, schools }) {
    const { errors } = usePage().props;
    const [editingId, setEditingId] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [search, setSearch] = useState(filters.q ?? '');
    const [fAte, setFAte] = useState(filters.ate_id ?? '');
    const [schoolFilterAte, setSchoolFilterAte] = useState('');
    const [genPwd, setGenPwd] = useState('');

    const form = useForm({ ...BLANK });

    // Генерация надёжного пароля (для ручного сброса вышестоящим). Показывается, чтобы передать пользователю.
    const generatePassword = () => {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        const rnd = crypto.getRandomValues(new Uint32Array(12));
        const pwd = Array.from(rnd, (n) => chars[n % chars.length]).join('');
        form.setData('password', pwd);
        setGenPwd(pwd);
    };

    const startCreate = () => {
        setEditingId(null);
        setSchoolFilterAte('');
        setGenPwd('');
        form.setData({ ...BLANK });
        form.clearErrors();
        setShowForm(true);
    };

    const startEdit = (u) => {
        setEditingId(u.id);
        setSchoolFilterAte('');
        setGenPwd('');
        form.setData({
            name: u.name,
            email: u.email,
            password: '',
            role: u.role,
            is_active: u.is_active,
            ate_id: ates.find((a) => a.name === u.ate)?.id ?? '',
            ate_ids: u.ate_ids ?? [],
            school_id: schools.find((s) => s.short_name === u.school)?.id ?? '',
        });
        form.clearErrors();
        setShowForm(true);
    };

    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setShowForm(false) };
        if (editingId) {
            form.put(route('admin.users.update', editingId), opts);
        } else {
            form.post(route('admin.users.store'), opts);
        }
    };

    const remove = (u) => {
        if (confirm(`Удалить пользователя «${u.name}»?`)) {
            router.delete(route('admin.users.destroy', u.id), { preserveScroll: true });
        }
    };

    const applyFilters = (overrides = {}) => {
        const params = { q: search, ate_id: fAte, ...overrides };
        Object.keys(params).forEach((k) => {
            if (params[k] === '' || params[k] == null) delete params[k];
        });
        router.get(route('admin.users.index'), params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };
    const runSearch = (e) => {
        e.preventDefault();
        applyFilters();
    };

    const schoolOptions = useMemo(() => {
        if (!schoolFilterAte) return schools;
        return schools.filter((s) => String(s.ate_id) === String(schoolFilterAte));
    }, [schools, schoolFilterAte]);

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Управление пользователями
                </h2>
            }
        >
            <Head title="Пользователи" />

            <div className="py-8">
                <div className="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {errors?.user && (
                        <div className="rounded bg-red-50 p-3 text-sm text-red-700">
                            {errors.user}
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-3">
                        <select
                            value={fAte}
                            onChange={(e) => {
                                setFAte(e.target.value);
                                applyFilters({ ate_id: e.target.value });
                            }}
                            className="rounded border-gray-300 text-sm"
                        >
                            <option value="">Все районы</option>
                            {ates.map((a) => (
                                <option key={a.id} value={a.id}>{a.name}</option>
                            ))}
                        </select>
                        <form onSubmit={runSearch} className="flex gap-2">
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Поиск по имени или e-mail"
                                className="rounded border-gray-300 text-sm"
                            />
                            <button className="rounded bg-gray-200 px-3 py-2 text-sm hover:bg-gray-300">
                                Найти
                            </button>
                        </form>
                        <button
                            onClick={startCreate}
                            className="ml-auto rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                        >
                            + Новый пользователь
                        </button>
                    </div>

                    <Modal show={showForm} onClose={() => setShowForm(false)} maxWidth="2xl">
                        <form onSubmit={submit} className="space-y-4 bg-white p-6">
                            <h3 className="font-semibold text-gray-800">
                                {editingId ? 'Редактирование' : 'Новый пользователь'}
                            </h3>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="ФИО" error={form.errors.name}>
                                    <input
                                        type="text"
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        className="w-full rounded border-gray-300 text-sm"
                                    />
                                </Field>
                                <Field label="E-mail" error={form.errors.email}>
                                    <input
                                        type="email"
                                        value={form.data.email}
                                        onChange={(e) => form.setData('email', e.target.value)}
                                        className="w-full rounded border-gray-300 text-sm"
                                    />
                                </Field>
                                <Field
                                    label={editingId ? 'Новый пароль (если меняем)' : 'Пароль'}
                                    error={form.errors.password}
                                >
                                    <div className="flex gap-2">
                                        <input
                                            type={genPwd ? 'text' : 'password'}
                                            value={form.data.password}
                                            onChange={(e) => { form.setData('password', e.target.value); setGenPwd(''); }}
                                            className="w-full rounded border-gray-300 text-sm"
                                        />
                                        <button type="button" onClick={generatePassword}
                                            className="shrink-0 rounded bg-gray-200 px-3 text-sm hover:bg-gray-300">Сгенерировать</button>
                                    </div>
                                    {genPwd && (
                                        <p className="mt-1 text-xs text-gray-500">
                                            Сгенерирован пароль: <span className="font-mono font-medium text-gray-800">{genPwd}</span> — сохраните и передайте пользователю.
                                        </p>
                                    )}
                                </Field>
                                <Field label="Роль" error={form.errors.role}>
                                    <select
                                        value={form.data.role}
                                        onChange={(e) => form.setData('role', e.target.value)}
                                        className="w-full rounded border-gray-300 text-sm"
                                    >
                                        {roles.map((r) => (
                                            <option key={r.value} value={r.value}>
                                                {r.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>

                                {needsAteSingle(form.data.role) && (
                                    <Field label="АТЕ" error={form.errors.ate_id}>
                                        <select
                                            value={form.data.ate_id}
                                            onChange={(e) => form.setData('ate_id', e.target.value)}
                                            className="w-full rounded border-gray-300 text-sm"
                                        >
                                            <option value="">— выберите АТЕ —</option>
                                            {ates.map((a) => (
                                                <option key={a.id} value={a.id}>
                                                    {a.name}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                )}

                                {needsAteMulti(form.data.role) && (
                                    <Field label="АТЕ (один или несколько)" error={form.errors.ate_ids}>
                                        <div className="max-h-44 overflow-y-auto rounded border border-gray-200 p-2">
                                            {ates.map((a) => {
                                                const checked = form.data.ate_ids.includes(a.id);
                                                return (
                                                    <label key={a.id} className="flex cursor-pointer items-center gap-2 py-0.5 text-sm">
                                                        <input type="checkbox" checked={checked}
                                                            onChange={() => form.setData('ate_ids', checked
                                                                ? form.data.ate_ids.filter((x) => x !== a.id)
                                                                : [...form.data.ate_ids, a.id])} />
                                                        {a.name}
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    </Field>
                                )}

                                {needsSchool(form.data.role) && (
                                    <Field label="Школа (ОО)" error={form.errors.school_id}>
                                        <select
                                            value={schoolFilterAte}
                                            onChange={(e) => setSchoolFilterAte(e.target.value)}
                                            className="mb-1 w-full rounded border-gray-300 text-xs text-gray-500"
                                        >
                                            <option value="">Фильтр по АТЕ (необязательно)</option>
                                            {ates.map((a) => (
                                                <option key={a.id} value={a.id}>
                                                    {a.name}
                                                </option>
                                            ))}
                                        </select>
                                        <select
                                            value={form.data.school_id}
                                            onChange={(e) =>
                                                form.setData('school_id', e.target.value)
                                            }
                                            className="w-full rounded border-gray-300 text-sm"
                                        >
                                            <option value="">— выберите школу —</option>
                                            {schoolOptions.map((s) => (
                                                <option key={s.id} value={s.id}>
                                                    {s.short_name}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                )}

                                <label className="flex items-center gap-2 text-sm text-gray-700">
                                    <input
                                        type="checkbox"
                                        checked={form.data.is_active}
                                        onChange={(e) =>
                                            form.setData('is_active', e.target.checked)
                                        }
                                    />
                                    Активен
                                </label>
                            </div>
                            {form.errors.is_active && (
                                <p className="text-sm text-red-600">{form.errors.is_active}</p>
                            )}

                            <div className="flex gap-2">
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
                                    <th className="px-6 py-3">ФИО</th>
                                    <th className="px-6 py-3">E-mail</th>
                                    <th className="px-6 py-3">Роль</th>
                                    <th className="px-6 py-3">Привязка</th>
                                    <th className="px-6 py-3">Активен</th>
                                    <th className="px-6 py-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {users.data.map((u) => (
                                    <tr key={u.id}>
                                        <td className="px-6 py-3 text-gray-800">{u.name}</td>
                                        <td className="px-6 py-3 text-gray-600">{u.email}</td>
                                        <td className="px-6 py-3 text-gray-600">{u.role_label}</td>
                                        <td className="px-6 py-3 text-gray-500">
                                            {u.ate || u.school || '—'}
                                        </td>
                                        <td className="px-6 py-3">
                                            {u.is_active ? (
                                                <span className="text-green-600">да</span>
                                            ) : (
                                                <span className="text-red-500">нет</span>
                                            )}
                                        </td>
                                        <td className="px-6 py-3 text-right">
                                            <button
                                                onClick={() => startEdit(u)}
                                                className="mr-3 text-indigo-600 hover:underline"
                                            >
                                                Изменить
                                            </button>
                                            <button
                                                onClick={() => remove(u)}
                                                className="text-red-600 hover:underline"
                                            >
                                                Удалить
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex flex-wrap gap-1">
                        {users.links.map((link, i) => (
                            <button
                                key={i}
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url)}
                                className={`rounded px-3 py-1 text-sm ${
                                    link.active
                                        ? 'bg-indigo-600 text-white'
                                        : 'bg-white text-gray-600 hover:bg-gray-100'
                                } ${!link.url ? 'opacity-40' : ''}`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
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
