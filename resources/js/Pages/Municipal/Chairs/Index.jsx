import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const BLANK = { name: '', email: '', password: '', is_active: true, olympiad_ids: [] };
const olympiadLabel = (o) => (!o.grades || o.grades.length === 0 || o.grades.length === 11
    ? o.subject
    : `${o.subject} (${o.grades.join(', ')})`);

export default function MunicipalChairsIndex({ chairs, olympiads }) {
    const [creating, setCreating] = useState(false);
    const createForm = useForm({ ...BLANK });
    const openCreate = () => { createForm.setData({ ...BLANK }); createForm.clearErrors(); setCreating(true); };
    const submitCreate = (e) => {
        e.preventDefault();
        createForm.post(route('municipal.chairs.store'), { preserveScroll: true, onSuccess: () => setCreating(false) });
    };

    const [editC, setEditC] = useState(null);
    const editForm = useForm({ ...BLANK });
    const openEdit = (c) => {
        setEditC(c);
        editForm.setData({ name: c.name, email: c.email, password: '', is_active: c.is_active, olympiad_ids: c.olympiads.map((o) => o.id) });
        editForm.clearErrors();
    };
    const submitEdit = (e) => {
        e.preventDefault();
        editForm.put(route('municipal.chairs.update', editC.id), { preserveScroll: true, onSuccess: () => setEditC(null) });
    };
    const remove = (c) => {
        if (confirm(`Удалить председателя «${c.name}»? Назначения на олимпиады будут сняты.`)) {
            router.delete(route('municipal.chairs.destroy', c.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Председатели предметных комиссий</h2>}>
            <Head title="Председатели комиссий" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-4 px-4 sm:px-6 lg:px-8">
                    <div className="flex items-start justify-between gap-4">
                        <p className="max-w-2xl text-sm text-gray-500">
                            Председатель комиссии проверяет работы вашего АТЕ обезличенно (по шифру). Один председатель
                            может вести несколько олимпиад (предметов) — отметьте их при создании.
                        </p>
                        {olympiads.length > 0 && (
                            <button onClick={openCreate}
                                className="shrink-0 rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                + Председатель
                            </button>
                        )}
                    </div>

                    {olympiads.length === 0 ? (
                        <div className="rounded-lg bg-white p-10 text-center text-gray-400 shadow">
                            Нет олимпиад муниципального этапа текущего года.
                        </div>
                    ) : chairs.length === 0 ? (
                        <div className="rounded-lg bg-white p-10 text-center text-gray-400 shadow">Председатели ещё не созданы.</div>
                    ) : (
                        <div className="overflow-hidden rounded-lg bg-white shadow">
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <tr>
                                        <th className="px-6 py-3">ФИО</th>
                                        <th className="px-6 py-3">E-mail</th>
                                        <th className="px-6 py-3">Олимпиады (предметы)</th>
                                        <th className="px-6 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {chairs.map((c) => (
                                        <tr key={c.id} className="hover:bg-gray-50">
                                            <td className="px-6 py-3 align-top font-medium">
                                                <span className={c.is_active ? 'text-gray-800' : 'text-gray-400 line-through'}>{c.name}</span>
                                            </td>
                                            <td className="px-6 py-3 align-top text-gray-500">{c.email}</td>
                                            <td className="px-6 py-3 align-top text-gray-600">
                                                {c.olympiads.length === 0
                                                    ? <span className="text-gray-400">не назначены</span>
                                                    : c.olympiads.map((o) => olympiadLabel(o)).join(', ')}
                                            </td>
                                            <td className="px-6 py-3 align-top text-right whitespace-nowrap">
                                                <button onClick={() => openEdit(c)} className="mr-3 text-xs text-indigo-600 hover:underline">изм.</button>
                                                <button onClick={() => remove(c)} className="text-xs text-red-600 hover:underline">удал.</button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>

            <Modal show={creating} onClose={() => setCreating(false)} maxWidth="lg">
                <form onSubmit={submitCreate} className="space-y-4 p-6">
                    <h3 className="font-semibold text-gray-800">Новый председатель</h3>
                    <ChairFields form={createForm} olympiads={olympiads} />
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setCreating(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button type="submit" disabled={createForm.processing}
                            className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">Создать</button>
                    </div>
                </form>
            </Modal>

            <Modal show={!!editC} onClose={() => setEditC(null)} maxWidth="lg">
                {editC && (
                    <form onSubmit={submitEdit} className="space-y-4 p-6">
                        <h3 className="font-semibold text-gray-800">Редактирование председателя</h3>
                        <ChairFields form={editForm} olympiads={olympiads} editing />
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setEditC(null)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                            <button type="submit" disabled={editForm.processing}
                                className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">Сохранить</button>
                        </div>
                    </form>
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}

function ChairFields({ form, olympiads, editing = false }) {
    const toggleOlympiad = (id) => {
        const has = form.data.olympiad_ids.includes(id);
        form.setData('olympiad_ids', has ? form.data.olympiad_ids.filter((x) => x !== id) : [...form.data.olympiad_ids, id]);
    };
    return (
        <>
            <div>
                <label className="block text-xs text-gray-500">ФИО</label>
                <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="w-full rounded border-gray-300 text-sm" />
                {form.errors.name && <p className="text-xs text-red-600">{form.errors.name}</p>}
            </div>
            <div>
                <label className="block text-xs text-gray-500">E-mail (логин)</label>
                <input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className="w-full rounded border-gray-300 text-sm" />
                {form.errors.email && <p className="text-xs text-red-600">{form.errors.email}</p>}
            </div>
            <div>
                <label className="block text-xs text-gray-500">{editing ? 'Новый пароль (если меняем)' : 'Пароль'}</label>
                <input type="password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} className="w-full rounded border-gray-300 text-sm" />
                {form.errors.password && <p className="text-xs text-red-600">{form.errors.password}</p>}
            </div>
            <div>
                <label className="block text-xs text-gray-500">Олимпиады (одна или несколько)</label>
                <div className="mt-1 flex flex-wrap gap-2">
                    {olympiads.map((o) => (
                        <label key={o.id} className={`cursor-pointer rounded border px-2 py-1 text-xs ${
                            form.data.olympiad_ids.includes(o.id) ? 'border-indigo-400 bg-indigo-50 text-indigo-700' : 'border-gray-300 text-gray-500'
                        }`}>
                            <input type="checkbox" className="hidden" checked={form.data.olympiad_ids.includes(o.id)} onChange={() => toggleOlympiad(o.id)} />
                            {olympiadLabel(o)}
                        </label>
                    ))}
                </div>
                {form.errors.olympiad_ids && <p className="text-xs text-red-600">{form.errors.olympiad_ids}</p>}
            </div>
            <label className="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                Активен
            </label>
        </>
    );
}
