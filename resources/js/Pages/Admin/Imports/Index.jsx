import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

// Импорт учётных записей по частям (чанками) с прогресс-баром — без таймаута и без очереди/воркера.
function BatchImportCard({ title, columns, startRoute, count, template }) {
    const csrf = usePage().props.csrf_token;
    const [file, setFile] = useState(null);
    const [running, setRunning] = useState(false);
    const [error, setError] = useState('');
    const [progress, setProgress] = useState(null); // { id, total, processed, created, updated, failed, done }

    const headers = { 'X-CSRF-TOKEN': csrf };

    const run = async (e) => {
        e.preventDefault();
        if (!file) return;
        setError('');
        setRunning(true);
        try {
            const fd = new FormData();
            fd.append('file', file);
            const { data } = await window.axios.post(route(startRoute), fd, { headers });
            let prog = { id: data.id, total: data.total, processed: 0, created: 0, updated: 0, failed: 0, done: false };
            setProgress(prog);
            while (!prog.done) {
                const res = await window.axios.post(route('admin.imports.users.chunk', prog.id), {}, { headers });
                prog = res.data;
                setProgress(prog);
            }
            setFile(null);
        } catch (err) {
            setError(err?.response?.data?.errors?.file?.[0] || err?.response?.data?.message || 'Ошибка импорта');
        } finally {
            setRunning(false);
        }
    };

    const reset = () => { setProgress(null); setError(''); };
    const pct = progress && progress.total > 0 ? Math.round((progress.processed / progress.total) * 100) : (progress?.done ? 100 : 0);

    return (
        <form onSubmit={run} className="space-y-3 rounded bg-white p-6 shadow">
            <div className="flex items-baseline justify-between">
                <h3 className="font-semibold text-gray-800">{title}</h3>
                <span className="text-sm text-gray-500">в базе: {count}</span>
            </div>
            <p className="text-xs text-gray-500">Колонки: <code className="rounded bg-gray-100 px-1">{columns}</code></p>
            <a href={`/templates/${template}`} download className="inline-block text-xs text-indigo-600 hover:underline">
                ↓ Скачать шаблон ({template})
            </a>

            {!progress && (
                <div className="flex items-center gap-2">
                    <input type="file" accept=".csv,.txt,.xlsx" onChange={(e) => setFile(e.target.files[0])} className="text-sm" />
                    <button type="submit" disabled={!file || running}
                        className="rounded bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50">
                        Импортировать
                    </button>
                </div>
            )}

            {progress && (
                <div className="space-y-2">
                    <div className="h-3 w-full overflow-hidden rounded bg-gray-200">
                        <div className={`h-full ${progress.done ? 'bg-green-600' : 'bg-indigo-600'} transition-all`} style={{ width: `${pct}%` }} />
                    </div>
                    <div className="flex items-center justify-between text-xs text-gray-600">
                        <span>{progress.done ? 'Готово' : 'Обработка…'} {progress.processed} из {progress.total} ({pct}%)</span>
                        <span>добавлено {progress.created}, обновлено {progress.updated}{progress.failed > 0 ? `, пропущено ${progress.failed}` : ''}</span>
                    </div>
                    {progress.done && (
                        <div className="flex flex-wrap items-center gap-3 pt-1">
                            {progress.failed > 0 && (
                                <a href={route('admin.imports.users.errors', progress.id)}
                                    className="rounded bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-700">
                                    ↓ Скачать строки с ошибками ({progress.failed})
                                </a>
                            )}
                            <button type="button" onClick={reset} className="text-xs text-indigo-600 hover:underline">Загрузить ещё файл</button>
                        </div>
                    )}
                </div>
            )}
            {error && <p className="text-sm text-red-600">{error}</p>}
        </form>
    );
}

function ImportCard({ title, columns, routeName, count, template }) {
    const form = useForm({ file: null });

    const submit = (e) => {
        e.preventDefault();
        form.post(route(routeName), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset('file'),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded bg-white p-6 shadow">
            <div className="flex items-baseline justify-between">
                <h3 className="font-semibold text-gray-800">{title}</h3>
                <span className="text-sm text-gray-500">в базе: {count}</span>
            </div>
            <p className="text-xs text-gray-500">
                Колонки: <code className="rounded bg-gray-100 px-1">{columns}</code>
            </p>
            <a
                href={`/templates/${template}`}
                download
                className="inline-block text-xs text-indigo-600 hover:underline"
            >
                ↓ Скачать шаблон ({template})
            </a>
            <div className="flex items-center gap-2">
                <input
                    type="file"
                    accept=".csv,.txt,.xlsx"
                    onChange={(e) => form.setData('file', e.target.files[0])}
                    className="text-sm"
                />
                <button
                    type="submit"
                    disabled={!form.data.file || form.processing}
                    className="rounded bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                >
                    Импортировать
                </button>
            </div>
            {form.errors.file && <p className="text-sm text-red-600">{form.errors.file}</p>}
        </form>
    );
}

export default function ImportsIndex({ counts, coordinatorsCount, importErrors }) {
    const { errors } = usePage().props;

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Импорт данных
                </h2>
            }
        >
            <Head title="Импорт данных" />

            <div className="py-8">
                <div className="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {errors?.import && (
                        <div className="rounded bg-amber-50 p-3 text-sm text-amber-700">
                            {errors.import}
                        </div>
                    )}
                    {importErrors && (
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
                            <span>
                                Последний импорт «{importErrors.label}»: строк с ошибками —{' '}
                                <b>{importErrors.count}</b>. Скачайте их, исправьте и загрузите
                                повторно.
                            </span>
                            <a
                                href={route('admin.imports.errors')}
                                className="shrink-0 rounded bg-amber-600 px-3 py-2 font-medium text-white hover:bg-amber-700"
                            >
                                ↓ Скачать строки с ошибками
                            </a>
                        </div>
                    )}

                    <p className="rounded bg-blue-50 p-3 text-sm text-blue-700">
                        Принимаются файлы <b>.xlsx</b> и <b>.csv</b>. Первая строка — заголовок.
                        Импорт обновляет записи по ключу и не удаляет остальные. У каждой карточки —
                        ссылка на шаблон с примерами.
                    </p>

                    <h3 className="text-sm font-semibold uppercase tracking-wide text-gray-400">
                        Справочники территории (порядок: АТЕ → МСУ → Школы)
                    </h3>
                    <ImportCard
                        title="1. АТЕ"
                        columns="№, код_АТЕ, название"
                        routeName="admin.imports.ates"
                        count={counts.ates}
                        template="import_ates.csv"
                    />
                    <ImportCard
                        title="2. МСУ"
                        columns="№, код_АТЕ, код_МСУ, название"
                        routeName="admin.imports.msus"
                        count={counts.msus}
                        template="import_msus.csv"
                    />
                    <ImportCard
                        title="3. Школы (ОО)"
                        columns="№, код_ОО, полное_имя, краткое_имя, уровень, код_АТЕ, код_МСУ, город(1/0)"
                        routeName="admin.imports.schools"
                        count={counts.schools}
                        template="import_schools.csv"
                    />

                    <h3 className="border-t pt-6 text-sm font-semibold uppercase tracking-wide text-gray-400">
                        Данные (требуют заполненных справочников)
                    </h3>
                    <ImportCard
                        title="Предметы"
                        columns="название, активен(1/0)"
                        routeName="admin.imports.subjects"
                        count={counts.subjects}
                        template="import_subjects.csv"
                    />
                    <ImportCard
                        title="Олимпиады"
                        columns="уч_год, предмет, этап, статус, дата, срок_первичных, крайний_срок_итоговых, классы(4-6/пусто=все), уровень(регион./республ.)"
                        routeName="admin.imports.olympiads"
                        count={counts.olympiads}
                        template="import_olympiads.csv"
                    />
                    <ImportCard
                        title="Учащиеся"
                        columns="ФИО, дата_рождения(ДД-ММ-ГГГГ), СНИЛС, код_ОО, класс, статус, ОВЗ(1/0/пусто), пол(м/ж)"
                        routeName="admin.imports.students"
                        count={counts.students}
                        template="import_students.csv"
                    />

                    <h3 className="border-t pt-6 text-sm font-semibold uppercase tracking-wide text-gray-400">
                        Учётные записи
                    </h3>
                    <p className="rounded bg-blue-50 p-3 text-sm text-blue-700">
                        Код привязки — код АТЕ (координаторы) или код ОО (оператор); для
                        администратора не нужен. Запись обновляется по e-mail; пароль обязателен
                        только для новых учётных записей.
                    </p>
                    <BatchImportCard
                        title="Пользователи (все роли)"
                        columns="ФИО, email, роль, код_привязки, пароль"
                        startRoute="admin.imports.users"
                        count={counts.users}
                        template="import_users.csv"
                    />
                    <BatchImportCard
                        title="Координаторы / операторы (пул, без admin)"
                        columns="ФИО, email, роль, код_привязки, пароль"
                        startRoute="admin.imports.coordinators"
                        count={coordinatorsCount}
                        template="import_coordinators.csv"
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
