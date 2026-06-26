import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const LEVEL_LABELS = { regional: 'Региональный', republican: 'Республиканский' };

export default function SchoolInvitationsShow({ olympiad, participants }) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Приглашённые на МЭ · {olympiad.subject}</h2>}
        >
            <Head title={`Приглашённые на МЭ · ${olympiad.subject}`} />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between">
                        <Link href={route('school.invitations.index')} className="text-sm text-gray-500 hover:underline">
                            ← К списку
                        </Link>
                        {participants.length > 0 && (
                            <a href={route('school.invitations.xlsx', olympiad.id)}
                                className="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                                ↓ Скачать список (XLSX)
                            </a>
                        )}
                    </div>

                    <div className="rounded-lg bg-white p-4 text-sm text-gray-600 shadow">
                        <b>{olympiad.subject}</b> · {LEVEL_LABELS[olympiad.level] ?? olympiad.level} уровень ·
                        классы {olympiad.grades.join(', ')}
                        {olympiad.date_held ? ` · ${olympiad.date_held}` : ''}
                    </div>

                    <div className="overflow-hidden rounded-lg bg-white shadow">
                        <div className="border-b px-6 py-3">
                            <h3 className="font-semibold text-gray-800">Приглашённые ({participants.length})</h3>
                        </div>
                        {participants.length === 0 ? (
                            <p className="px-6 py-8 text-center text-sm text-gray-400">Приглашённых из вашей школы нет.</p>
                        ) : (
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <tr>
                                        <th className="px-3 py-3">№</th>
                                        <th className="px-3 py-3">ФИО</th>
                                        <th className="px-3 py-3">Класс</th>
                                        <th className="px-3 py-3">Класс участия</th>
                                        <th className="px-3 py-3">Основание</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {participants.map((p, i) => (
                                        <tr key={p.id} className="hover:bg-gray-50">
                                            <td className="px-3 py-2 text-gray-400">{i + 1}</td>
                                            <td className="px-3 py-2 font-medium text-gray-800">{p.fio}</td>
                                            <td className="px-3 py-2 text-gray-600">{p.class}</td>
                                            <td className="px-3 py-2 text-gray-600">{p.participation_grade}</td>
                                            <td className="px-3 py-2 text-xs text-gray-500">{p.basis || '—'}</td>
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
