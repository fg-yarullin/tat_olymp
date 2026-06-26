import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const STAGE_LABELS = { school: 'Школьный', municipal: 'Муниципальный', regional: 'Региональный' };

export default function ProtocolShow({ template, sources }) {
    const [editingId, setEditingId] = useState(null);
    const form = useForm({ header: '', group_header: '', source_key: '' });

    const reset = () => {
        setEditingId(null);
        form.setData({ header: '', group_header: '', source_key: '' });
        form.clearErrors();
    };
    const startEdit = (c) => {
        setEditingId(c.id);
        form.setData({ header: c.header, group_header: c.group_header ?? '', source_key: c.source_key });
        form.clearErrors();
    };
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: reset };
        editingId
            ? form.put(route('admin.protocols.columns.update', editingId), opts)
            : form.post(route('admin.protocols.columns.store', template.id), opts);
    };
    const remove = (c) => {
        if (confirm(`Удалить колонку «${c.header}»?`)) {
            router.delete(route('admin.protocols.columns.destroy', c.id), { preserveScroll: true });
        }
    };
    const move = (index, dir) => {
        const cols = [...template.columns];
        const j = index + dir;
        if (j < 0 || j >= cols.length) return;
        [cols[index], cols[j]] = [cols[j], cols[index]];
        router.post(route('admin.protocols.reorder', template.id), { ids: cols.map((c) => c.id) }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Колонки протокола</h2>}
        >
            <Head title={`Колонки · ${template.name}`} />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <Link href={route('admin.protocols.index')} className="text-sm text-gray-500 hover:underline">
                        ← К списку шаблонов
                    </Link>

                    <div className="rounded bg-white p-4 text-sm text-gray-600 shadow">
                        <b>{template.name}</b> · {STAGE_LABELS[template.stage]} этап · предмет: {template.subject ?? 'общий'}
                    </div>

                    <form onSubmit={submit} className="grid gap-3 rounded bg-white p-6 shadow sm:grid-cols-4">
                        <div className="sm:col-span-2">
                            <label className="block text-xs font-medium text-gray-600">Заголовок колонки</label>
                            <input value={form.data.header} onChange={(e) => form.setData('header', e.target.value)}
                                className="w-full rounded border-gray-300 text-sm" />
                            {form.errors.header && <p className="text-xs text-red-600">{form.errors.header}</p>}
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-600">Группа (верхняя шапка)</label>
                            <input value={form.data.group_header} onChange={(e) => form.setData('group_header', e.target.value)}
                                placeholder="напр. Вопросы" className="w-full rounded border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-600">Источник значения</label>
                            <input list="src" value={form.data.source_key} onChange={(e) => form.setData('source_key', e.target.value)}
                                placeholder="напр. student.snils или question:1" className="w-full rounded border-gray-300 text-sm" />
                            <datalist id="src">
                                {Object.entries(sources).map(([key, label]) => (
                                    <option key={key} value={key}>{label}</option>
                                ))}
                            </datalist>
                            {form.errors.source_key && <p className="text-xs text-red-600">{form.errors.source_key}</p>}
                        </div>
                        <div className="flex items-end gap-2 sm:col-span-4">
                            <button className="rounded bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                                {editingId ? 'Сохранить' : 'Добавить колонку'}
                            </button>
                            {editingId && (
                                <button type="button" onClick={reset} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                            )}
                        </div>
                    </form>

                    <div className="overflow-hidden rounded bg-white shadow">
                        {template.columns.length === 0 ? (
                            <p className="px-6 py-8 text-center text-sm text-gray-400">Колонок пока нет.</p>
                        ) : (
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <tr>
                                        <th className="px-4 py-3">#</th>
                                        <th className="px-4 py-3">Группа</th>
                                        <th className="px-4 py-3">Заголовок</th>
                                        <th className="px-4 py-3">Источник</th>
                                        <th className="px-4 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {template.columns.map((c, i) => (
                                        <tr key={c.id}>
                                            <td className="px-4 py-2 text-gray-400">{i + 1}</td>
                                            <td className="px-4 py-2 text-gray-500">{c.group_header ?? '—'}</td>
                                            <td className="px-4 py-2 font-medium text-gray-800">{c.header}</td>
                                            <td className="px-4 py-2 text-gray-500">
                                                <code className="rounded bg-gray-100 px-1 text-xs">{c.source_key}</code>
                                                {sources[c.source_key] ? ` · ${sources[c.source_key]}` : ''}
                                            </td>
                                            <td className="px-4 py-2 whitespace-nowrap text-right">
                                                <button onClick={() => move(i, -1)} disabled={i === 0} className="mr-1 text-gray-500 disabled:opacity-30">↑</button>
                                                <button onClick={() => move(i, 1)} disabled={i === template.columns.length - 1} className="mr-3 text-gray-500 disabled:opacity-30">↓</button>
                                                <button onClick={() => startEdit(c)} className="mr-3 text-indigo-600 hover:underline">Изменить</button>
                                                <button onClick={() => remove(c)} className="text-red-600 hover:underline">Удалить</button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
