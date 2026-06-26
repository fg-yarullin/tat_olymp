import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const TERRITORY_LABELS = { city: 'Город', rural: 'Село' };

export default function Ratings({
    ate,
    isKazanCross,
    selectedYear,
    years,
    schoolRatings,
    republican,
}) {
    const [territory, setTerritory] = useState('all');

    const changeYear = (year) =>
        router.get(route('analytics.ratings'), { year }, { preserveState: true });

    const visibleRatings =
        territory === 'all'
            ? schoolRatings
            : schoolRatings.filter((r) => r.territorial_sign === territory);

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Аналитика и рейтинги
                </h2>
            }
        >
            <Head title="Рейтинги" />

            <div className="py-8">
                <div className="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="flex flex-wrap items-center gap-4 rounded bg-white p-4 shadow">
                        <div>
                            <label className="mr-2 text-sm text-gray-600">Учебный год:</label>
                            <select
                                value={selectedYear ?? ''}
                                onChange={(e) => changeYear(e.target.value)}
                                className="rounded border-gray-300 text-sm"
                            >
                                {years.map((y) => (
                                    <option key={y} value={y}>
                                        {y}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mr-2 text-sm text-gray-600">Срез:</label>
                            <select
                                value={territory}
                                onChange={(e) => setTerritory(e.target.value)}
                                className="rounded border-gray-300 text-sm"
                            >
                                <option value="all">Все ОО</option>
                                <option value="city">Городские</option>
                                <option value="rural">Сельские</option>
                            </select>
                        </div>
                        <a
                            href={route('analytics.ratings.xlsx', {
                                year: selectedYear,
                                territory,
                            })}
                            className="ml-auto rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700"
                        >
                            Экспорт протокола (Excel)
                        </a>
                    </div>

                    <section className="rounded bg-white shadow">
                        <h3 className="border-b px-6 py-3 font-semibold text-gray-800">
                            {isKazanCross
                                ? 'Сквозной рейтинг ОО — городской округ Казань'
                                : `Рейтинг ОО — ${ate?.name ?? 'АТЕ'}`}
                        </h3>
                        {visibleRatings.length === 0 ? (
                            <p className="px-6 py-6 text-sm text-gray-400">
                                Нет данных за выбранный год.
                            </p>
                        ) : (
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <tr>
                                        <th className="px-6 py-2">Место</th>
                                        <th className="px-6 py-2">ОО</th>
                                        {isKazanCross && <th className="px-6 py-2">МСУ</th>}
                                        <th className="px-6 py-2">Признак</th>
                                        <th className="px-6 py-2">Участников</th>
                                        <th className="px-6 py-2">Призёров</th>
                                        <th className="px-6 py-2">Победителей</th>
                                        <th className="px-6 py-2">Баллы</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {visibleRatings.map((r) => (
                                        <tr key={r.oo_code}>
                                            <td className="px-6 py-2 font-semibold">{r.rank}</td>
                                            <td className="px-6 py-2">{r.school}</td>
                                            {isKazanCross && (
                                                <td className="px-6 py-2 text-gray-500">
                                                    {r.msu_code}
                                                </td>
                                            )}
                                            <td className="px-6 py-2 text-gray-500">
                                                {TERRITORY_LABELS[r.territorial_sign] ??
                                                    r.territorial_sign}
                                            </td>
                                            <td className="px-6 py-2">{r.participants}</td>
                                            <td className="px-6 py-2">{r.prizes}</td>
                                            <td className="px-6 py-2">{r.winners}</td>
                                            <td className="px-6 py-2 font-medium">{r.points}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>

                    <section className="rounded bg-white shadow">
                        <h3 className="border-b px-6 py-3 font-semibold text-gray-800">
                            Республиканский срез по годам
                        </h3>
                        <table className="min-w-full text-sm">
                            <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <tr>
                                    <th className="px-6 py-2">Год</th>
                                    <th className="px-6 py-2">Статус</th>
                                    <th className="px-6 py-2">Участников</th>
                                    <th className="px-6 py-2">Призёров</th>
                                    <th className="px-6 py-2">Победителей</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {republican.map((y) => (
                                    <tr key={y.year}>
                                        <td className="px-6 py-2 font-medium">{y.year}</td>
                                        <td className="px-6 py-2 text-gray-500">
                                            {y.status === 'current' ? 'Текущий' : 'Архив'}
                                        </td>
                                        <td className="px-6 py-2">{y.participants}</td>
                                        <td className="px-6 py-2">{y.prizes}</td>
                                        <td className="px-6 py-2">{y.winners}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
