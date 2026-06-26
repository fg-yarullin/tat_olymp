import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function SubjectsIndex({ subjects }) {
    const { errors } = usePage().props;
    const [editingId, setEditingId] = useState(null);
    const form = useForm({ name: '', is_active: true });

    const startCreate = () => {
        setEditingId(null);
        form.setData({ name: '', is_active: true });
        form.clearErrors();
    };

    const startEdit = (s) => {
        setEditingId(s.id);
        form.setData({ name: s.name, is_active: s.is_active });
        form.clearErrors();
    };

    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => startCreate() };
        if (editingId) {
            form.put(route('admin.subjects.update', editingId), opts);
        } else {
            form.post(route('admin.subjects.store'), opts);
        }
    };

    const remove = (s) => {
        if (confirm(`Удалить предмет «${s.name}»?`)) {
            router.delete(route('admin.subjects.destroy', s.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Предметы
                </h2>
            }
        >
            <Head title="Предметы" />

            <div className="py-8">
                <div className="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {errors?.subject && (
                        <div className="rounded bg-red-50 p-3 text-sm text-red-700">
                            {errors.subject}
                        </div>
                    )}

                    <form onSubmit={submit} className="flex flex-wrap items-end gap-3 rounded bg-white p-6 shadow">
                        <div className="flex-1">
                            <label className="block text-xs font-medium text-gray-600">
                                Название предмета
                            </label>
                            <input
                                type="text"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                className="w-full rounded border-gray-300 text-sm"
                            />
                            {form.errors.name && (
                                <p className="text-xs text-red-600">{form.errors.name}</p>
                            )}
                        </div>
                        <label className="flex items-center gap-2 text-sm text-gray-700">
                            <input
                                type="checkbox"
                                checked={form.data.is_active}
                                onChange={(e) => form.setData('is_active', e.target.checked)}
                            />
                            Активен
                        </label>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                        >
                            {editingId ? 'Сохранить' : 'Добавить'}
                        </button>
                        {editingId && (
                            <button
                                type="button"
                                onClick={startCreate}
                                className="rounded bg-gray-200 px-4 py-2 text-sm hover:bg-gray-300"
                            >
                                Отмена
                            </button>
                        )}
                    </form>

                    <div className="overflow-hidden rounded bg-white shadow">
                        <table className="min-w-full text-sm">
                            <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <tr>
                                    <th className="px-6 py-3">Предмет</th>
                                    <th className="px-6 py-3">Активен</th>
                                    <th className="px-6 py-3">Олимпиад</th>
                                    <th className="px-6 py-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {subjects.map((s) => (
                                    <tr key={s.id}>
                                        <td className="px-6 py-3 font-medium text-gray-800">{s.name}</td>
                                        <td className="px-6 py-3">
                                            {s.is_active ? (
                                                <span className="text-green-600">да</span>
                                            ) : (
                                                <span className="text-gray-400">нет</span>
                                            )}
                                        </td>
                                        <td className="px-6 py-3 text-gray-600">{s.olympiads_count}</td>
                                        <td className="px-6 py-3 text-right">
                                            <button
                                                onClick={() => startEdit(s)}
                                                className="mr-3 text-indigo-600 hover:underline"
                                            >
                                                Изменить
                                            </button>
                                            {s.olympiads_count === 0 && (
                                                <button
                                                    onClick={() => remove(s)}
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
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
