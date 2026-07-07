import Countdown from '@/Components/Countdown';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const gradesLabel = (g) => (!g || g.length === 11 ? 'все' : g.join(', '));

export default function MunicipalResultsIndex({ olympiads }) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Муниципальный этап — состав участников
                </h2>
            }
        >
            <Head title="Муниципальный этап" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-4 px-4 sm:px-6 lg:px-8">

                    {olympiads.data.length === 0 ? (
                        <div className="rounded-lg bg-white p-10 text-center text-gray-400 shadow">
                            Нет олимпиад муниципального этапа текущего года.
                        </div>
                    ) : (
                        <div className="overflow-hidden rounded-lg bg-white shadow">
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <tr>
                                        <th className="px-6 py-3">Предмет</th>
                                        <th className="px-6 py-3">Классы</th>
                                        <th className="px-6 py-3">Участников</th>
                                        <th className="px-6 py-3">Сроки ввода результатов</th>
                                        <th className="px-6 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {olympiads.data.map((o) => (
                                        <tr key={o.id} className="hover:bg-gray-50">
                                            <td className="px-6 py-3 font-medium text-gray-800">{o.subject}</td>
                                            <td className="px-6 py-3 text-gray-500">{gradesLabel(o.grades)}</td>
                                            <td className="px-6 py-3 text-gray-600">{o.participants}</td>
                                            <td className="px-6 py-3">
                                                <div className="space-y-1">
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="text-xs text-gray-400">Первичный:</span>
                                                        <Countdown open={o.entry_open} deadline={o.entry_deadline} size="sm" closedLabel="закрыт" stack />
                                                    </div>
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="text-xs text-gray-400">Итоговый:</span>
                                                        {o.entry_open ? (
                                                            <span className="text-xs text-gray-400">после состава</span>
                                                        ) : (
                                                            <Countdown open={o.appeal_open} deadline={o.appeal_deadline} size="sm" closedLabel="закрыт" stack />
                                                        )}
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-3 text-right">
                                                <Link
                                                    href={route('municipal.results.show', o.id)}
                                                    className="whitespace-nowrap rounded bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700"
                                                >
                                                    Перейти →
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {olympiads.links?.length > 3 && (
                                <div className="flex flex-wrap gap-1 border-t px-6 py-3">
                                    {olympiads.links.map((link, i) => (
                                        <button key={i} disabled={!link.url}
                                            onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })}
                                            className={`rounded px-3 py-1 text-sm ${
                                                link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'
                                            } ${!link.url ? 'opacity-40' : ''}`}
                                            dangerouslySetInnerHTML={{ __html: link.label }} />
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
