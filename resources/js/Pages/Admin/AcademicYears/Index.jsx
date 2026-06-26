import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function AcademicYearsIndex({ years }) {
    const { errors } = usePage().props;
    const [editingId, setEditingId] = useState(null);
    const form = useForm({ name: '', status: 'archive' });

    const startCreate = () => {
        setEditingId(null);
        form.setData({ name: '', status: 'archive' });
        form.clearErrors();
    };

    const startEdit = (y) => {
        setEditingId(y.id);
        form.setData({ name: y.name, status: y.status });
        form.clearErrors();
    };

    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => startCreate() };
        if (editingId) {
            form.put(route('admin.years.update', editingId), opts);
        } else {
            form.post(route('admin.years.store'), opts);
        }
    };

    const makeCurrent = (y) =>
        router.post(route('admin.years.current', y.id), {}, { preserveScroll: true });

    const remove = (y) => {
        if (confirm(`Удалить учебный год «${y.name}»?`)) {
            router.delete(route('admin.years.destroy', y.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Учебные годы
                </h2>
            }
        >
            <Head title="Учебные годы" />

            <div className="py-8">
                <div className="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {errors?.year && (
                        <div className="rounded bg-red-50 p-3 text-sm text-red-700">
                            {errors.year}
                        </div>
                    )}

                    <form onSubmit={submit} className="flex flex-wrap items-end gap-3 rounded bg-white p-6 shadow">
                        <div>
                            <label className="block text-xs font-medium text-gray-600">
                                Название (ГГГГ/ГГГГ)
                            </label>
                            <input
                                type="text"
                                placeholder="2026/2027"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                className="rounded border-gray-300 text-sm"
                            />
                            {form.errors.name && (
                                <p className="text-xs text-red-600">{form.errors.name}</p>
                            )}
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-600">Статус</label>
                            <select
                                value={form.data.status}
                                onChange={(e) => form.setData('status', e.target.value)}
                                className="rounded border-gray-300 text-sm"
                            >
                                <option value="archive">Архив</option>
                                <option value="current">Текущий</option>
                            </select>
                        </div>
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
                                    <th className="px-6 py-3">Год</th>
                                    <th className="px-6 py-3">Статус</th>
                                    <th className="px-6 py-3">Олимпиад</th>
                                    <th className="px-6 py-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {years.map((y) => (
                                    <tr key={y.id}>
                                        <td className="px-6 py-3 font-medium text-gray-800">{y.name}</td>
                                        <td className="px-6 py-3">
                                            {y.status === 'current' ? (
                                                <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700">
                                                    Текущий
                                                </span>
                                            ) : (
                                                <span className="text-gray-500">Архив</span>
                                            )}
                                        </td>
                                        <td className="px-6 py-3 text-gray-600">{y.olympiads_count}</td>
                                        <td className="px-6 py-3 text-right">
                                            {y.status !== 'current' && (
                                                <button
                                                    onClick={() => makeCurrent(y)}
                                                    className="mr-3 text-green-600 hover:underline"
                                                >
                                                    Сделать текущим
                                                </button>
                                            )}
                                            <button
                                                onClick={() => startEdit(y)}
                                                className="mr-3 text-indigo-600 hover:underline"
                                            >
                                                Изменить
                                            </button>
                                            {y.olympiads_count === 0 && (
                                                <button
                                                    onClick={() => remove(y)}
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
