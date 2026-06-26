import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

const LEVEL_LABELS = { 1: 'Начальная', 2: 'Основная', 3: 'Средняя' };
const TERRITORY_LABELS = { city: 'Город', rural: 'Село' };

function Row({ label, value }) {
    return (
        <div className="flex flex-col gap-0.5 border-b border-gray-100 py-3 sm:flex-row sm:items-center">
            <dt className="w-64 shrink-0 text-sm text-gray-500">{label}</dt>
            <dd className="text-sm font-medium text-gray-800">{value || '—'}</dd>
        </div>
    );
}

export default function Info({ school }) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">Моя школа</h2>
            }
        >
            <Head title="Моя школа" />

            <div className="py-8">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    {!school ? (
                        <div className="rounded-lg bg-white p-8 text-center text-gray-400 shadow">
                            Школа не привязана к вашей учётной записи. Обратитесь к администратору.
                        </div>
                    ) : (
                        <div className="rounded-lg bg-white p-6 shadow">
                            <div className="mb-4 flex items-start justify-between">
                                <div>
                                    <h3 className="text-lg font-semibold text-gray-800">
                                        {school.short_name}
                                    </h3>
                                </div>
                                <span className="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-500">
                                    только просмотр
                                </span>
                            </div>

                            <dl>
                                <Row label="Краткое наименование" value={school.short_name} />
                                <Row label="Полное наименование" value={school.full_name} />
                                <Row
                                    label="Уровень образования"
                                    value={LEVEL_LABELS[school.education_level] ?? school.education_level}
                                />
                                <Row
                                    label="Территория"
                                    value={TERRITORY_LABELS[school.territorial_sign] ?? school.territorial_sign}
                                />
                                <Row label="АТЕ" value={school.ate} />
                                <Row label="МСУ" value={school.msu} />
                            </dl>

                            <p className="mt-4 text-xs text-gray-400">
                                Реквизиты школы изменяются администратором системы.
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
