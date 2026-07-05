import ImportProgress from '@/Components/ImportProgress';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useChunkedImport } from '@/Hooks/useChunkedImport';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const STATUS_LABELS = { active: 'Активен', graduated: 'Выпустился', transferring: 'Переводится', departed: 'Выбыл' };

const blankStudent = {
    fio: '', birth_date: '', gender: '', snils: '', ovz: false, real_grade: '', class_letter: '',
};

export default function StudentsIndex({ students, letters, filters }) {
    const { errors } = usePage().props;
    const [q, setQ] = useState(filters.q ?? '');
    const [grade, setGrade] = useState(filters.grade ?? '');
    const [letter, setLetter] = useState(filters.letter ?? '');

    const apply = (overrides = {}) => {
        const params = { q, grade, letter, ...overrides };
        Object.keys(params).forEach((k) => {
            if (params[k] === '' || params[k] == null) delete params[k];
        });
        router.get(route('school.students.index'), params, {
            preserveState: true, preserveScroll: true, replace: true,
        });
    };

    // Создание / редактирование
    const [editingId, setEditingId] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const form = useForm({ ...blankStudent });
    const startCreate = () => {
        setEditingId(null);
        form.setData({ ...blankStudent });
        form.clearErrors();
        setShowForm(true);
    };
    const startEdit = (s) => {
        setEditingId(s.id);
        form.setData({
            fio: s.fio, birth_date: s.birth_date ?? '', gender: s.gender ?? '', snils: s.snils ?? '',
            ovz: !!s.ovz, real_grade: s.real_grade, class_letter: s.class_letter ?? '',
        });
        form.clearErrors();
        setShowForm(true);
    };
    const submitStudent = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setShowForm(false) };
        editingId
            ? form.put(route('school.students.update', editingId), opts)
            : form.post(route('school.students.store'), opts);
    };

    // Импорт (по частям, с прогресс-баром)
    const [importFile, setImportFile] = useState(null);
    const [importKey, setImportKey] = useState(0);
    const chunked = useChunkedImport({
        startUrl: route('school.students.import'),
        chunkUrl: (id) => route('school.students.import.chunk', id),
        errorsUrl: (id) => route('school.students.import.errors', id),
    });
    const submitImport = (e) => {
        e.preventDefault();
        chunked.run(importFile);
    };
    const resetImport = () => {
        chunked.reset();
        setImportFile(null);
        setImportKey((k) => k + 1); // пересоздаём <input type=file>, native не сбрасывается через value
    };
    // После завершения импорта обновляем список учащихся — прогресс-бар остаётся виден.
    useEffect(() => {
        if (chunked.progress?.done) {
            router.reload({ only: ['students', 'letters'] });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [chunked.progress?.done]);

    // Выбытие
    const [departing, setDeparting] = useState(null);
    const departForm = useForm({ transfer_settlement: '', transfer_school: '', departed_at: '' });
    const startDepart = (s) => {
        setDeparting(s);
        departForm.setData({ transfer_settlement: '', transfer_school: '', departed_at: '' });
        departForm.clearErrors();
    };
    const submitDepart = (e) => {
        e.preventDefault();
        departForm.post(route('school.students.depart', departing.id), {
            preserveScroll: true, onSuccess: () => setDeparting(null),
        });
    };
    const restore = (s) => router.post(route('school.students.restore', s.id), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Учащиеся</h2>}
        >
            <Head title="Учащиеся школы" />

            <div className="py-8">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {errors?.file && <div className="rounded bg-amber-50 p-3 text-sm text-amber-700">{errors.file}</div>}

                    <div className="flex flex-wrap items-center gap-3">
                        <button onClick={startCreate}
                            className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            + Новый ученик
                        </button>
                        <a href={route('school.students.template')}
                            className="rounded border border-indigo-300 px-3 py-2 text-sm text-indigo-700 hover:bg-indigo-50">
                            ↓ Шаблон (XLSX)
                        </a>
                        {!chunked.progress && (
                            <form onSubmit={submitImport} className="flex items-center gap-2">
                                <input key={importKey} type="file" accept=".xlsx,.ods,.csv,.txt"
                                    onChange={(e) => setImportFile(e.target.files[0] ?? null)} className="text-sm" />
                                <button type="submit" disabled={!importFile || chunked.running}
                                    className="rounded bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50">
                                    Импорт
                                </button>
                            </form>
                        )}
                    </div>

                    <ImportProgress progress={chunked.progress} error={chunked.error} errorsHref={chunked.errorsHref} onReset={resetImport} />

                    <Modal show={!!departing} onClose={() => setDeparting(null)} maxWidth="lg">
                        {departing && (
                            <form onSubmit={submitDepart} className="space-y-4 p-6">
                                <h3 className="font-semibold text-gray-800">Оформление выбытия</h3>
                                <p className="text-sm text-gray-600">
                                    Выбытие ученика <b>{departing.fio}</b> в другую школу:
                                </p>
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <Field label="Населённый пункт" error={departForm.errors.transfer_settlement}>
                                        <input value={departForm.data.transfer_settlement}
                                            onChange={(e) => departForm.setData('transfer_settlement', e.target.value)}
                                            className="w-full rounded border-gray-300 text-sm" />
                                    </Field>
                                    <Field label="Наименование ОО" error={departForm.errors.transfer_school}>
                                        <input value={departForm.data.transfer_school}
                                            onChange={(e) => departForm.setData('transfer_school', e.target.value)}
                                            className="w-full rounded border-gray-300 text-sm" />
                                    </Field>
                                    <Field label="Дата выбытия" error={departForm.errors.departed_at}>
                                        <input type="date" value={departForm.data.departed_at}
                                            onChange={(e) => departForm.setData('departed_at', e.target.value)}
                                            className="w-full rounded border-gray-300 text-sm" />
                                    </Field>
                                </div>
                                <div className="flex justify-end gap-2">
                                    <button type="button" onClick={() => setDeparting(null)} className="rounded bg-gray-200 px-4 py-2 text-sm">
                                        Отмена
                                    </button>
                                    <button type="submit" disabled={departForm.processing}
                                        className="rounded bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50">
                                        Отметить выбытие
                                    </button>
                                </div>
                            </form>
                        )}
                    </Modal>

                    <Modal show={showForm} onClose={() => setShowForm(false)} maxWidth="2xl">
                        <form onSubmit={submitStudent} className="grid gap-3 bg-white p-6 sm:grid-cols-3">
                            <h3 className="sm:col-span-3 font-semibold text-gray-800">
                                {editingId ? 'Редактирование ученика' : 'Новый ученик'}
                            </h3>
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
                            <Field label="СНИЛС (необязательно)" error={form.errors.snils}>
                                <input value={form.data.snils} onChange={(e) => form.setData('snils', e.target.value)}
                                    placeholder="123-456-789 00" className="w-full rounded border-gray-300 text-sm" />
                            </Field>
                            <Field label="Класс" error={form.errors.real_grade}>
                                <select value={form.data.real_grade}
                                    onChange={(e) => form.setData('real_grade', Number(e.target.value))}
                                    className="w-full rounded border-gray-300 text-sm">
                                    <option value="">—</option>
                                    {Array.from({ length: 11 }, (_, i) => i + 1).map((g) => (
                                        <option key={g} value={g}>{g}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Литера (буква/цифра или пусто)" error={form.errors.class_letter}>
                                <input value={form.data.class_letter}
                                    onChange={(e) => form.setData('class_letter', e.target.value.toUpperCase())}
                                    placeholder="А, Б, 1, 2 …" className="w-full rounded border-gray-300 text-sm" />
                            </Field>
                            <label className="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" checked={form.data.ovz}
                                    onChange={(e) => form.setData('ovz', e.target.checked)} />
                                ОВЗ
                            </label>
                            <div className="flex items-end gap-2 sm:col-span-2">
                                <button type="submit" disabled={form.processing}
                                    className="rounded bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50">
                                    {editingId ? 'Сохранить' : 'Добавить'}
                                </button>
                                <button type="button" onClick={() => setShowForm(false)}
                                    className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                            </div>
                        </form>
                    </Modal>

                    <div className="flex flex-wrap items-end gap-3 rounded bg-white p-4 shadow">
                        <div>
                            <label className="block text-xs text-gray-500">Класс</label>
                            <select value={grade}
                                onChange={(e) => { setGrade(e.target.value); apply({ grade: e.target.value }); }}
                                className="rounded border-gray-300 text-sm">
                                <option value="">Все</option>
                                {Array.from({ length: 11 }, (_, i) => i + 1).map((g) => (
                                    <option key={g} value={g}>{g}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">Литера</label>
                            <select value={letter}
                                onChange={(e) => { setLetter(e.target.value); apply({ letter: e.target.value }); }}
                                className="rounded border-gray-300 text-sm">
                                <option value="">Все</option>
                                {letters.map((l) => <option key={l} value={l}>{l}</option>)}
                            </select>
                        </div>
                        <form onSubmit={(e) => { e.preventDefault(); apply(); }} className="flex gap-2">
                            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Поиск: ФИО или СНИЛС"
                                className="rounded border-gray-300 text-sm" />
                            <button className="rounded bg-gray-200 px-3 py-2 text-sm hover:bg-gray-300">Найти</button>
                        </form>
                    </div>

                    <div className="text-sm text-gray-500">Всего: <b>{students.total}</b></div>

                    <div className="overflow-hidden rounded bg-white shadow">
                        {students.data.length === 0 ? (
                            <p className="px-6 py-8 text-center text-sm text-gray-400">Учащиеся не найдены.</p>
                        ) : (
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <tr>
                                        <th className="px-4 py-3">ФИО</th>
                                        <th className="px-4 py-3">Дата рожд.</th>
                                        <th className="px-4 py-3">Класс</th>
                                        <th className="px-4 py-3">Статус</th>
                                        <th className="px-4 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {students.data.map((s) => (
                                        <tr key={s.id}>
                                            <td className="px-4 py-3 font-medium text-gray-800">{s.fio}</td>
                                            <td className="px-4 py-3 text-gray-600">{s.birth_date}</td>
                                            <td className="px-4 py-3 text-gray-600">{s.class_name}</td>
                                            <td className="px-4 py-3 text-gray-500">
                                                {STATUS_LABELS[s.status] ?? s.status}
                                                {s.status === 'departed' && (
                                                    <div className="text-xs text-gray-400">
                                                        → {s.transfer_school}, {s.transfer_settlement}
                                                        {s.departed_at ? ` (${s.departed_at})` : ''}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-right">
                                                {s.status === 'departed' ? (
                                                    <button onClick={() => restore(s)} className="text-green-600 hover:underline">Вернуть</button>
                                                ) : (
                                                    <>
                                                        <button onClick={() => startEdit(s)} className="mr-3 text-indigo-600 hover:underline">Изменить</button>
                                                        <button onClick={() => startDepart(s)} className="text-amber-700 hover:underline">Выбытие</button>
                                                    </>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>

                    <div className="flex flex-wrap gap-1">
                        {students.links.map((link, i) => (
                            <button key={i} disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })}
                                className={`rounded px-3 py-1 text-sm ${
                                    link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'
                                } ${!link.url ? 'opacity-40' : ''}`}
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
