import { Head, Link, router, usePage } from '@inertiajs/react';

const STAGE_LABELS = {
    school: 'Школьный',
    municipal: 'Муниципальный',
    regional: 'Региональный',
};

const RESULT_LABELS = {
    participant: 'Участник',
    appealed: 'Подана апелляция',
    prize_winner: 'Призёр',
    winner: 'Победитель',
    disqualified: 'Дисквалифицирован',
};

export default function AvailableWorksList({ works }) {
    const { flash } = usePage().props;

    const logout = () => router.post(route('guest.logout'));

    return (
        <div className="min-h-screen bg-gray-100 py-10">
            <Head title="Доступные работы" />

            <div className="mx-auto max-w-3xl px-4">
                <div className="mb-4 flex items-center justify-between">
                    <h1 className="text-xl font-semibold text-gray-800">
                        Доступные к показу работы
                    </h1>
                    <button
                        onClick={logout}
                        className="text-sm text-gray-600 underline hover:text-gray-900"
                    >
                        Выйти
                    </button>
                </div>

                {flash?.success && (
                    <div className="mb-4 rounded bg-green-50 p-3 text-sm text-green-700">
                        {flash.success}
                    </div>
                )}

                {works.length === 0 ? (
                    <div className="rounded bg-white p-6 text-center text-gray-500 shadow">
                        Сейчас нет работ, доступных для просмотра.
                    </div>
                ) : (
                    <div className="overflow-hidden rounded bg-white shadow">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-gray-50 text-gray-600">
                                <tr>
                                    <th className="px-4 py-3">Предмет</th>
                                    <th className="px-4 py-3">Этап</th>
                                    <th className="px-4 py-3">Балл</th>
                                    <th className="px-4 py-3">Статус</th>
                                    <th className="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {works.map((w) => (
                                    <tr key={w.id}>
                                        <td className="px-4 py-3">{w.subject}</td>
                                        <td className="px-4 py-3">
                                            {STAGE_LABELS[w.stage] ?? w.stage}
                                        </td>
                                        <td className="px-4 py-3">
                                            {w.score ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {RESULT_LABELS[w.result_status] ??
                                                w.result_status}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link
                                                href={route(
                                                    'guest.work.view',
                                                    w.id,
                                                )}
                                                className="text-indigo-600 hover:underline"
                                            >
                                                Открыть
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}
