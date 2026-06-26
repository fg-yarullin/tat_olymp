import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';

const LEVEL_LABELS = { regional: 'Региональный', republican: 'Республиканский' };

const gradesLabel = (g) => (!g || g.length === 11 ? 'все' : g.join(', '));

export default function ResultsIndex({ olympiads }) {

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Ввод результатов — школьный этап
                </h2>
            }
        >
            <Head title="Результаты" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-4 px-4 sm:px-6 lg:px-8">

                    {olympiads.length === 0 ? (
                        <div className="rounded-lg bg-white p-10 text-center text-gray-400 shadow">
                            Нет олимпиад школьного этапа текущего года.
                        </div>
                    ) : (
                        <div className="overflow-hidden rounded-lg bg-white shadow">
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <tr>
                                        <th className="px-6 py-3">Предмет</th>
                                        <th className="px-6 py-3">Уровень</th>
                                        <th className="px-6 py-3">Классы</th>
                                        <th className="px-6 py-3">Участий</th>
                                        <th className="px-6 py-3">Ввод</th>
                                        <th className="px-6 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {olympiads.map((o) => (
                                        <tr key={o.id} className="hover:bg-gray-50">
                                            <td className="px-6 py-3 font-medium text-gray-800">{o.subject}</td>
                                            <td className="px-6 py-3 text-gray-500">{LEVEL_LABELS[o.level] ?? o.level}</td>
                                            <td className="px-6 py-3 text-gray-500">{gradesLabel(o.grades)}</td>
                                            <td className="px-6 py-3 text-gray-600">{o.participations}</td>
                                            <td className="px-6 py-3">
                                                {o.import_open ? (
                                                    <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700">открыт</span>
                                                ) : (
                                                    <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">закрыт</span>
                                                )}
                                            </td>
                                            <td className="px-6 py-3 text-right">
                                                <Link
                                                    href={route('school.results.show', o.id)}
                                                    className="text-indigo-600 hover:underline"
                                                >
                                                    Ввести результаты →
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
        </AuthenticatedLayout>
    );
}
