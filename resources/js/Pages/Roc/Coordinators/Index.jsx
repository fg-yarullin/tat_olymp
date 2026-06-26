import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const BLANK = { name: '', email: '', password: '', is_active: true, subject_ids: [] };

export default function RocCoordinatorsIndex({ coordinators, subjects }) {
    const [creating, setCreating] = useState(false);
    const createForm = useForm({ ...BLANK });
    const openCreate = () => { createForm.setData({ ...BLANK }); createForm.clearErrors(); setCreating(true); };
    const submitCreate = (e) => {
        e.preventDefault();
        createForm.post(route('roc.coordinators.store'), { preserveScroll: true, onSuccess: () => setCreating(false) });
    };

    const [editC, setEditC] = useState(null);
    const editForm = useForm({ ...BLANK });
    const openEdit = (c) => {
        setEditC(c);
        editForm.setData({ name: c.name, email: c.email, password: '', is_active: c.is_active, subject_ids: c.subjects.map((s) => s.id) });
        editForm.clearErrors();
    };
    const submitEdit = (e) => {
        e.preventDefault();
        editForm.put(route('roc.coordinators.update', editC.id), { preserveScroll: true, onSuccess: () => setEditC(null) });
    };
    const remove = (c) => {
        if (confirm(`Удалить координатора «${c.name}»?`)) {
            router.delete(route('roc.coordinators.destroy', c.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Координаторы РОЦ</h2>}>
            <Head title="Координаторы РОЦ" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-4 px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between">
                        <p className="max-w-2xl text-sm text-gray-500">
                            Координаторы РОЦ по предметам видят и выгружают протоколы ШЭ/МЭ только по назначенным предметам.
                        </p>
                        <button onClick={openCreate}
                            className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            + Координатор
                        </button>
                    </div>

                    {coordinators.length === 0 ? (
                        <div className="rounded-lg bg-white p-10 text-center text-gray-400 shadow">Координаторы РОЦ ещё не созданы.</div>
                    ) : (
                        <div className="overflow-hidden rounded-lg bg-white shadow">
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <tr>
                                        <th className="px-6 py-3">ФИО</th>
                                        <th className="px-6 py-3">E-mail</th>
                                        <th className="px-6 py-3">Предметы</th>
                                        <th className="px-6 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {coordinators.map((c) => (
                                        <tr key={c.id} className="hover:bg-gray-50">
                                            <td className="px-6 py-3 font-medium">
                                                <span className={c.is_active ? 'text-gray-800' : 'text-gray-400 line-through'}>{c.name}</span>
                                            </td>
                                            <td className="px-6 py-3 text-gray-500">{c.email}</td>
                                            <td className="px-6 py-3 text-gray-600">
                                                {c.subjects.length === 0 ? <span className="text-gray-400">—</span>
                                                    : c.subjects.map((s) => s.name).join(', ')}
                                            </td>
                                            <td className="px-6 py-3 text-right whitespace-nowrap">
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
                    <h3 className="font-semibold text-gray-800">Новый координатор РОЦ</h3>
                    <CoordinatorFields form={createForm} subjects={subjects} />
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
                        <h3 className="font-semibold text-gray-800">Редактирование координатора</h3>
                        <CoordinatorFields form={editForm} subjects={subjects} editing />
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

function CoordinatorFields({ form, subjects, editing = false }) {
    const toggleSubject = (id) => {
        const has = form.data.subject_ids.includes(id);
        form.setData('subject_ids', has ? form.data.subject_ids.filter((x) => x !== id) : [...form.data.subject_ids, id]);
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
                <label className="block text-xs text-gray-500">Предметы (один или несколько)</label>
                <div className="mt-1 flex flex-wrap gap-2">
                    {subjects.map((s) => (
                        <label key={s.id} className={`cursor-pointer rounded border px-2 py-1 text-xs ${
                            form.data.subject_ids.includes(s.id) ? 'border-indigo-400 bg-indigo-50 text-indigo-700' : 'border-gray-300 text-gray-500'
                        }`}>
                            <input type="checkbox" className="hidden" checked={form.data.subject_ids.includes(s.id)} onChange={() => toggleSubject(s.id)} />
                            {s.name}
                        </label>
                    ))}
                </div>
                {form.errors.subject_ids && <p className="text-xs text-red-600">{form.errors.subject_ids}</p>}
            </div>
            <label className="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                Активен
            </label>
        </>
    );
}
