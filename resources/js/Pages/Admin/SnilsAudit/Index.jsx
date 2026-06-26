import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function SnilsAuditIndex({ duplicates, suspicious, limit }) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Аудит СНИЛС</h2>}
        >
            <Head title="Аудит СНИЛС" />

            <div className="py-8">
                <div className="mx-auto max-w-5xl space-y-8 px-4 sm:px-6 lg:px-8">
                    <p className="rounded bg-blue-50 p-3 text-sm text-blue-700">
                        Показаны первые {limit} записей каждого типа. СНИЛС уникален в рамках одной ОО,
                        поэтому один и тот же номер в разных школах допустим, но может указывать на
                        ошибку в данных.
                    </p>

                    <section>
                        <h3 className="mb-3 font-semibold text-gray-800">
                            Дубли СНИЛС <span className="text-gray-400">({duplicates.length})</span>
                        </h3>
                        {duplicates.length === 0 ? (
                            <div className="rounded bg-white p-6 text-center text-sm text-gray-400 shadow">
                                Дублей не найдено.
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {duplicates.map((d) => (
                                    <div key={d.snils} className="rounded bg-white p-4 shadow">
                                        <div className="mb-2 font-mono text-sm font-semibold text-gray-800">
                                            {d.snils} — {d.students.length} ученик(а/ов)
                                        </div>
                                        <ul className="space-y-1 text-sm text-gray-600">
                                            {d.students.map((s) => (
                                                <li key={s.id} className="flex justify-between border-t border-gray-50 py-1">
                                                    <span>{s.fio}</span>
                                                    <span className="text-gray-400">{s.school ?? '—'}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ))}
                            </div>
                        )}
                    </section>

                    <section>
                        <h3 className="mb-3 font-semibold text-gray-800">
                            Подозрительные СНИЛС <span className="text-gray-400">({suspicious.length})</span>
                        </h3>
                        {suspicious.length === 0 ? (
                            <div className="rounded bg-white p-6 text-center text-sm text-gray-400 shadow">
                                Подозрительных не найдено.
                            </div>
                        ) : (
                            <div className="overflow-hidden rounded bg-white shadow">
                                <table className="min-w-full text-sm">
                                    <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                        <tr>
                                            <th className="px-4 py-3">СНИЛС</th>
                                            <th className="px-4 py-3">Причина</th>
                                            <th className="px-4 py-3">ФИО</th>
                                            <th className="px-4 py-3">Школа</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {suspicious.map((s) => (
                                            <tr key={s.id}>
                                                <td className="px-4 py-2 font-mono text-gray-800">{s.snils}</td>
                                                <td className="px-4 py-2 text-amber-700">{s.reason}</td>
                                                <td className="px-4 py-2 text-gray-700">{s.fio}</td>
                                                <td className="px-4 py-2 text-gray-500">{s.school ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
