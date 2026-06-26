import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const SECTIONS = [
    {
        title: 'Данные',
        cards: [
            ['admin.users.index', 'Пользователи', 'Координаторы и операторы, роли и доступ'],
            ['admin.years.index', 'Учебные годы', 'Текущий год и архив сезонов'],
            ['admin.subjects.index', 'Предметы', 'Справочник предметов олимпиад'],
            ['admin.tech.index', 'Технология: справочник', 'Направления и виды практик'],
            ['admin.olympiads.index', 'Олимпиады', 'Создание и публикация результатов'],
            ['admin.results.index', 'Результаты олимпиад', 'Просмотр внесённых баллов с фильтрами'],
            ['admin.protocols.index', 'Конструктор протоколов', 'Колонки протоколов по этапу и предмету'],
            ['admin.territory.index', 'Справочники АТЕ/МСУ/ОО', 'Территориальная структура и школы'],
            ['admin.students.index', 'Учащиеся', 'Карточки участников, статусы, перевод'],
            ['admin.snils.audit', 'Аудит СНИЛС', 'Дубли и подозрительные (выдуманные) СНИЛС'],
        ],
    },
    {
        title: 'Загрузка и обслуживание',
        cards: [
            ['admin.imports.index', 'Импорт справочников', 'Пакетная загрузка АТЕ/МСУ/ОО из Excel/CSV'],
            ['admin.maintenance', 'Обслуживание', 'Ротация сезона и очистка БД'],
        ],
    },
    {
        title: 'Аналитика',
        cards: [
            ['analytics.ratings', 'Рейтинги и отчётность', 'Рейтинги ОО и экспорт протоколов в Excel'],
        ],
    },
];

function Card({ routeName, title, description }) {
    return (
        <Link
            href={route(routeName)}
            className="block rounded-lg border border-gray-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 hover:shadow"
        >
            <h3 className="font-semibold text-gray-800">{title}</h3>
            <p className="mt-1 text-sm text-gray-500">{description}</p>
        </Link>
    );
}

export default function Home() {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Администрирование
                </h2>
            }
        >
            <Head title="Администрирование" />

            <div className="py-8">
                <div className="mx-auto max-w-5xl space-y-8 px-4 sm:px-6 lg:px-8">
                    {SECTIONS.map((section) => (
                        <section key={section.title}>
                            <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-400">
                                {section.title}
                            </h3>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {section.cards.map(([routeName, title, description]) => (
                                    <Card
                                        key={routeName}
                                        routeName={routeName}
                                        title={title}
                                        description={description}
                                    />
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
