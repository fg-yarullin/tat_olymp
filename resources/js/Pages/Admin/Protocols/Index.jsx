import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const STAGE_LABELS = { school: 'Школьный', municipal: 'Муниципальный', regional: 'Региональный' };

export default function ProtocolsIndex({ templates, stages, subjects }) {
    const { errors } = usePage().props;
    const form = useForm({ name: '', stage: 'school', subject_id: '' });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('admin.protocols.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };
    const remove = (t) => {
        if (confirm(`Удалить шаблон «${t.name}»?`)) {
            router.delete(route('admin.protocols.destroy', t.id), { preserveScroll: true });
        }
    };

    // Копия шаблона: цель — другой этап/предмет (ограничение уникальности), колонки копируются.
    const [copySource, setCopySource] = useState(null);
    const copyForm = useForm({ name: '', stage: 'school', subject_id: '' });
    const openCopy = (t) => {
        setCopySource(t);
        copyForm.clearErrors();
        copyForm.setData({ name: `${t.name} (копия)`, stage: t.stage, subject_id: t.subject_id ?? '' });
    };
    const submitCopy = (e) => {
        e.preventDefault();
        copyForm.post(route('admin.protocols.duplicate', copySource.id), {
            onSuccess: () => setCopySource(null),
        });
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Конструктор протоколов</h2>}
        >
            <Head title="Протоколы" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {errors?.subject_id && <div className="rounded bg-red-50 p-3 text-sm text-red-700">{errors.subject_id}</div>}

                    <form onSubmit={submit} className="flex flex-wrap items-end gap-3 rounded bg-white p-6 shadow">
                        <div className="flex-1 min-w-[180px]">
                            <label className="block text-xs font-medium text-gray-600">Название</label>
                            <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="напр. Протокол МЭ (общий)" className="w-full rounded border-gray-300 text-sm" />
                            {form.errors.name && <p className="text-xs text-red-600">{form.errors.name}</p>}
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-600">Этап</label>
                            <select value={form.data.stage} onChange={(e) => form.setData('stage', e.target.value)}
                                className="rounded border-gray-300 text-sm">
                                {stages.map((s) => <option key={s} value={s}>{STAGE_LABELS[s]}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-600">Предмет</label>
                            <select value={form.data.subject_id} onChange={(e) => form.setData('subject_id', e.target.value)}
                                className="rounded border-gray-300 text-sm">
                                <option value="">— общий —</option>
                                {subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                        </div>
                        <button className="rounded bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                            Создать
                        </button>
                    </form>

                    <div className="overflow-hidden rounded bg-white shadow">
                        {templates.length === 0 ? (
                            <p className="px-6 py-8 text-center text-sm text-gray-400">Шаблонов пока нет.</p>
                        ) : (
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <tr>
                                        <th className="px-6 py-3">Название</th>
                                        <th className="px-6 py-3">Этап</th>
                                        <th className="px-6 py-3">Предмет</th>
                                        <th className="px-6 py-3">Колонок</th>
                                        <th className="px-6 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {templates.map((t) => (
                                        <tr key={t.id}>
                                            <td className="px-6 py-3 font-medium text-gray-800">{t.name}</td>
                                            <td className="px-6 py-3 text-gray-600">{STAGE_LABELS[t.stage]}</td>
                                            <td className="px-6 py-3 text-gray-500">{t.subject ?? 'общий'}</td>
                                            <td className="px-6 py-3 text-gray-600">{t.columns_count}</td>
                                            <td className="px-6 py-3 text-right">
                                                <Link href={route('admin.protocols.show', t.id)} className="mr-3 text-indigo-600 hover:underline">
                                                    Колонки
                                                </Link>
                                                <button onClick={() => openCopy(t)} className="mr-3 text-gray-600 hover:underline">Копия</button>
                                                <button onClick={() => remove(t)} className="text-red-600 hover:underline">Удалить</button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </div>

            <Modal show={!!copySource} onClose={() => setCopySource(null)} maxWidth="lg">
                <form onSubmit={submitCopy} className="space-y-4 p-6">
                    <h3 className="font-semibold text-gray-800">Копия шаблона</h3>
                    <p className="text-xs text-gray-500">
                        Копируются все колонки. Укажите другой этап и/или предмет — для одной пары
                        «этап + предмет» может быть только один шаблон.
                    </p>
                    <div>
                        <label className="block text-xs font-medium text-gray-600">Название</label>
                        <input value={copyForm.data.name} onChange={(e) => copyForm.setData('name', e.target.value)}
                            className="w-full rounded border-gray-300 text-sm" />
                        {copyForm.errors.name && <p className="text-xs text-red-600">{copyForm.errors.name}</p>}
                    </div>
                    <div className="flex gap-3">
                        <div>
                            <label className="block text-xs font-medium text-gray-600">Этап</label>
                            <select value={copyForm.data.stage} onChange={(e) => copyForm.setData('stage', e.target.value)}
                                className="rounded border-gray-300 text-sm">
                                {stages.map((s) => <option key={s} value={s}>{STAGE_LABELS[s]}</option>)}
                            </select>
                        </div>
                        <div className="flex-1">
                            <label className="block text-xs font-medium text-gray-600">Предмет</label>
                            <select value={copyForm.data.subject_id} onChange={(e) => copyForm.setData('subject_id', e.target.value)}
                                className="w-full rounded border-gray-300 text-sm">
                                <option value="">— общий —</option>
                                {subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                        </div>
                    </div>
                    {copyForm.errors.subject_id && <p className="text-xs text-red-600">{copyForm.errors.subject_id}</p>}
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setCopySource(null)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button type="submit" disabled={copyForm.processing}
                            className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                            Создать копию
                        </button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
