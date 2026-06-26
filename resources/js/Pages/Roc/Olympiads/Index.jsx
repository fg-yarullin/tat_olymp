import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const STAGE_LABELS = { school: 'Школьный', municipal: 'Муниципальный' };
const gradesLabel = (g) => (!g || g.length === 0 ? '—' : g.join(', '));

export default function RocOlympiadsIndex({ olympiads, subjects, filters = {} }) {
    const apply = (patch) => {
        const next = { subject: filters.subject ?? '', stage: filters.stage ?? '', ...patch };
        router.get(route('roc.olympiads.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v)), {
            preserveScroll: true, preserveState: true, replace: true,
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Протоколы ШЭ/МЭ</h2>}>
            <Head title="Протоколы ШЭ/МЭ" />

            <div className="py-8">
                <div className="mx-auto max-w-5xl space-y-4 px-4 sm:px-6 lg:px-8">
                    <p className="text-sm text-gray-500">
                        Олимпиады школьного и муниципального этапов текущего года. Выберите олимпиаду, чтобы посмотреть
                        и выгрузить протокол с фильтрами по АТЕ, классу и классу участия.
                    </p>

                    <div className="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow">
                        <div>
                            <label className="block text-xs text-gray-500">Предмет</label>
                            <select value={filters.subject ?? ''} onChange={(e) => apply({ subject: e.target.value })}
                                className="rounded border-gray-300 text-sm">
                                <option value="">Все предметы</option>
                                {subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">Этап</label>
                            <select value={filters.stage ?? ''} onChange={(e) => apply({ stage: e.target.value })}
                                className="rounded border-gray-300 text-sm">
                                <option value="">Оба этапа</option>
                                <option value="school">Школьный</option>
                                <option value="municipal">Муниципальный</option>
                            </select>
                        </div>
                    </div>

                    {olympiads.length === 0 ? (
                        <div className="rounded-lg bg-white p-10 text-center text-gray-400 shadow">
                            Нет олимпиад по заданным фильтрам.
                        </div>
                    ) : (
                        <div className="overflow-hidden rounded-lg bg-white shadow">
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <tr>
                                        <th className="px-6 py-3">Предмет</th>
                                        <th className="px-6 py-3">Этап</th>
                                        <th className="px-6 py-3">Классы</th>
                                        <th className="px-6 py-3">Участников</th>
                                        <th className="px-6 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {olympiads.map((o) => (
                                        <tr key={o.id} className="hover:bg-gray-50">
                                            <td className="px-6 py-3 font-medium text-gray-800">{o.subject}</td>
                                            <td className="px-6 py-3 text-gray-600">{STAGE_LABELS[o.stage] ?? o.stage}</td>
                                            <td className="px-6 py-3 text-gray-500">{gradesLabel(o.grades)}</td>
                                            <td className="px-6 py-3 text-gray-500">{o.participants}</td>
                                            <td className="px-6 py-3 text-right">
                                                <Link href={route('roc.olympiads.show', o.id)}
                                                    className="rounded bg-indigo-600 px-4 py-1.5 text-xs font-medium text-white hover:bg-indigo-700">
                                                    Протокол →
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
