import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';

function Stat({ label, value }) {
    return (
        <div className="rounded bg-gray-50 p-4">
            <div className="text-2xl font-semibold text-gray-800">{value}</div>
            <div className="text-xs uppercase text-gray-500">{label}</div>
        </div>
    );
}

export default function Maintenance({ currentYear, stats, expiredSeasons }) {
    const { errors } = usePage().props;

    const rotateForm = useForm({ name: '' });
    const purgeForm = useForm({ years: 3 });

    const submitRotate = (e) => {
        e.preventDefault();
        if (
            !confirm(
                'Запустить ротацию сезона? Прежний год уйдёт в архив, 11 класс выпустится, остальные перейдут на класс выше. Действие необратимо.',
            )
        ) {
            return;
        }
        rotateForm.post(route('admin.maintenance.rotate'), { preserveScroll: true });
    };

    const submitPurge = (e) => {
        e.preventDefault();
        if (
            !confirm(
                'Запустить очистку БД? Сканы истёкших сезонов будут удалены, ПДн обезличены. Действие необратимо.',
            )
        ) {
            return;
        }
        purgeForm.post(route('admin.maintenance.purge'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Обслуживание системы
                </h2>
            }
        >
            <Head title="Обслуживание" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {errors?.maintenance && (
                        <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">
                            {errors.maintenance}
                        </pre>
                    )}

                    <div className="rounded bg-white p-6 shadow">
                        <h3 className="mb-1 font-semibold text-gray-800">Текущее состояние</h3>
                        <p className="mb-4 text-sm text-gray-500">
                            Текущий учебный год: <b>{currentYear ?? '— не задан —'}</b>
                        </p>
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <Stat label="Архивных лет" value={stats.archive_years} />
                            <Stat label="Активных учеников" value={stats.active_students} />
                            <Stat label="Выпустятся (11)" value={stats.graduating} />
                            <Stat label="Перейдут (1–10)" value={stats.promoting} />
                        </div>
                    </div>

                    <div className="rounded bg-white p-6 shadow">
                        <h3 className="mb-1 font-semibold text-gray-800">
                            Ротация сезона (ТЗ 4.1)
                        </h3>
                        <p className="mb-4 text-sm text-gray-500">
                            Новый учебный год, выпуск 11 класса, перевод 1–10 классов.
                        </p>
                        <form onSubmit={submitRotate} className="flex flex-wrap items-end gap-3">
                            <div>
                                <label className="block text-xs text-gray-500">
                                    Новый год (необязательно)
                                </label>
                                <input
                                    type="text"
                                    placeholder="напр. 2026/2027"
                                    value={rotateForm.data.name}
                                    onChange={(e) => rotateForm.setData('name', e.target.value)}
                                    className="rounded border-gray-300 text-sm"
                                />
                                {rotateForm.errors.name && (
                                    <p className="text-xs text-red-600">{rotateForm.errors.name}</p>
                                )}
                            </div>
                            <button
                                type="submit"
                                disabled={rotateForm.processing}
                                className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                            >
                                Запустить ротацию
                            </button>
                        </form>
                    </div>

                    <div className="rounded bg-white p-6 shadow">
                        <h3 className="mb-1 font-semibold text-gray-800">
                            Очистка и архивация БД (ТЗ 4.9)
                        </h3>
                        <p className="mb-2 text-sm text-gray-500">
                            Удаление сканов и обезличивание ПДн в сезонах старше N лет.
                        </p>
                        <p className="mb-4 text-sm text-gray-500">
                            Истёкшие сезоны:{' '}
                            {expiredSeasons.length ? (
                                <b>{expiredSeasons.join(', ')}</b>
                            ) : (
                                <span className="text-gray-400">нет</span>
                            )}
                        </p>
                        <form onSubmit={submitPurge} className="flex flex-wrap items-end gap-3">
                            <div>
                                <label className="block text-xs text-gray-500">Возраст (лет)</label>
                                <input
                                    type="number"
                                    min="1"
                                    max="50"
                                    value={purgeForm.data.years}
                                    onChange={(e) =>
                                        purgeForm.setData('years', Number(e.target.value))
                                    }
                                    className="w-24 rounded border-gray-300 text-sm"
                                />
                                {purgeForm.errors.years && (
                                    <p className="text-xs text-red-600">{purgeForm.errors.years}</p>
                                )}
                            </div>
                            <button
                                type="submit"
                                disabled={purgeForm.processing}
                                className="rounded bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                            >
                                Запустить очистку
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
