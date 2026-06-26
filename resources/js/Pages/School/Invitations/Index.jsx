import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const LEVEL_LABELS = { regional: 'Региональный', republican: 'Республиканский' };
const gradesLabel = (g) => (!g || g.length === 11 ? 'все' : g.join(', '));

export default function SchoolInvitationsIndex({ olympiads }) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Приглашённые на муниципальный этап</h2>}
        >
            <Head title="Приглашения на МЭ" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-4 px-4 sm:px-6 lg:px-8">
                    <p className="text-sm text-gray-500">
                        Учащиеся вашей школы, приглашённые на муниципальный этап. Список можно скачать в XLSX
                        для оповещения участников.
                    </p>

                    {olympiads.length === 0 ? (
                        <div className="rounded-lg bg-white p-10 text-center text-gray-400 shadow">
                            Пока нет приглашений на муниципальный этап.
                        </div>
                    ) : (
                        <div className="overflow-hidden rounded-lg bg-white shadow">
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <tr>
                                        <th className="px-6 py-3">Предмет</th>
                                        <th className="px-6 py-3">Уровень</th>
                                        <th className="px-6 py-3">Классы</th>
                                        <th className="px-6 py-3">Дата</th>
                                        <th className="px-6 py-3">Приглашено</th>
                                        <th className="px-6 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {olympiads.map((o) => (
                                        <tr key={o.id} className="hover:bg-gray-50">
                                            <td className="px-6 py-3 font-medium text-gray-800">{o.subject}</td>
                                            <td className="px-6 py-3 text-gray-500">{LEVEL_LABELS[o.level] ?? o.level}</td>
                                            <td className="px-6 py-3 text-gray-500">{gradesLabel(o.grades)}</td>
                                            <td className="px-6 py-3 text-gray-500">{o.date_held ?? '—'}</td>
                                            <td className="px-6 py-3 text-gray-600">{o.invited}</td>
                                            <td className="px-6 py-3 whitespace-nowrap text-right">
                                                <Link href={route('school.invitations.show', o.id)} className="mr-3 text-indigo-600 hover:underline">
                                                    Показать
                                                </Link>
                                                <a href={route('school.invitations.xlsx', o.id)} className="text-emerald-700 hover:underline">
                                                    XLSX
                                                </a>
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
