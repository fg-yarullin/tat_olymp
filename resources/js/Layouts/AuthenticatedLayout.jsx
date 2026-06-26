import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import FlashMessages from '@/Components/FlashMessages';
import NavLink from '@/Components/NavLink';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

// Разделы кабинетов по ролям (ТЗ 5). Ключ — значение UserRole, value — пункты меню.
const ROLE_NAV = {
    admin: [
        { name: 'admin.home', pattern: 'admin.*', label: 'Администрирование' },
        { name: 'analytics.ratings', pattern: 'analytics.*', label: 'Рейтинги' },
    ],
    super_coordinator: [
        { name: 'municipal.results.index', pattern: 'municipal.results.*', label: 'Муниципальный этап' },
        { name: 'municipal.chairs.index', pattern: 'municipal.chairs.*', label: 'Председатели комиссий' },
        { name: 'municipal.schools.index', pattern: 'municipal.schools.*', label: 'Школы' },
        { name: 'city.subject-coordinators.index', pattern: 'city.subject-coordinators.*', label: 'Ответственные по предметам' },
        { name: 'analytics.ratings', pattern: 'analytics.*', label: 'Рейтинги' },
    ],
    kazan_subject_coordinator: [
        { name: 'municipal.results.index', pattern: 'municipal.results.*', label: 'Муниципальный этап' },
        { name: 'municipal.chairs.index', pattern: 'municipal.chairs.*', label: 'Председатели комиссий' },
    ],
    municipal_coordinator: [
        { name: 'municipal.results.index', pattern: 'municipal.results.*', label: 'Муниципальный этап' },
        { name: 'municipal.chairs.index', pattern: 'municipal.chairs.*', label: 'Председатели комиссий' },
        { name: 'municipal.schools.index', pattern: 'municipal.schools.*', label: 'Школы' },
        { name: 'analytics.ratings', pattern: 'analytics.*', label: 'Рейтинги' },
    ],
    commission_chair: [
        { name: 'commission.results.index', pattern: 'commission.results.*', label: 'Проверка работ МЭ' },
    ],
    roc_representative: [
        { name: 'roc.olympiads.index', pattern: 'roc.olympiads.*', label: 'Протоколы ШЭ/МЭ' },
        { name: 'roc.coordinators.index', pattern: 'roc.coordinators.*', label: 'Координаторы РОЦ' },
    ],
    roc_subject_coordinator: [
        { name: 'roc.olympiads.index', pattern: 'roc.olympiads.*', label: 'Протоколы ШЭ/МЭ' },
    ],
    school_operator: [
        { name: 'school.info', pattern: 'school.info', label: 'Моя школа' },
        { name: 'school.students.index', pattern: 'school.students.*', label: 'Учащиеся' },
        { name: 'school.results.index', pattern: 'school.results.*', label: 'Результаты' },
        { name: 'school.invitations.index', pattern: 'school.invitations.*', label: 'Приглашения на МЭ' },
    ],
};

export default function AuthenticatedLayout({ header, children }) {
    const user = usePage().props.auth.user;
    const navItems = ROLE_NAV[user.role] ?? [];

    const [showingNavigationDropdown, setShowingNavigationDropdown] =
        useState(false);

    return (
        <div className="min-h-screen bg-gray-100">
            <FlashMessages />
            <nav className="border-b border-gray-100 bg-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex">
                            <div className="flex shrink-0 items-center">
                                <Link href="/">
                                    <ApplicationLogo className="block h-9 w-auto fill-current text-gray-800" />
                                </Link>
                            </div>

                            <div className="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                                <NavLink
                                    href={route('dashboard')}
                                    active={route().current('dashboard')}
                                >
                                    Главная
                                </NavLink>
                                {navItems.map((item) => (
                                    <NavLink
                                        key={item.name}
                                        href={route(item.name)}
                                        active={route().current(item.pattern)}
                                    >
                                        {item.label}
                                    </NavLink>
                                ))}
                                <NavLink
                                    href={route('schedule')}
                                    active={route().current('schedule')}
                                >
                                    Расписание
                                </NavLink>
                                <NavLink
                                    href={route('help.index')}
                                    active={route().current('help.*')}
                                >
                                    Справка
                                </NavLink>
                            </div>
                        </div>

                        <div className="hidden sm:ms-6 sm:flex sm:items-center">
                            <div className="relative ms-3">
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <span className="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                className="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                            >
                                                {user.name}

                                                <svg
                                                    className="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fillRule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clipRule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </Dropdown.Trigger>

                                    <Dropdown.Content>
                                        <Dropdown.Link
                                            href={route('profile.edit')}
                                        >
                                            Профиль
                                        </Dropdown.Link>
                                        <Dropdown.Link
                                            href={route('logout')}
                                            method="post"
                                            as="button"
                                        >
                                            Выйти
                                        </Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>
                            </div>
                        </div>

                        <div className="-me-2 flex items-center sm:hidden">
                            <button
                                onClick={() =>
                                    setShowingNavigationDropdown(
                                        (previousState) => !previousState,
                                    )
                                }
                                className="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <svg
                                    className="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        className={
                                            !showingNavigationDropdown
                                                ? 'inline-flex'
                                                : 'hidden'
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        className={
                                            showingNavigationDropdown
                                                ? 'inline-flex'
                                                : 'hidden'
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    className={
                        (showingNavigationDropdown ? 'block' : 'hidden') +
                        ' sm:hidden'
                    }
                >
                    <div className="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            href={route('dashboard')}
                            active={route().current('dashboard')}
                        >
                            Главная
                        </ResponsiveNavLink>
                        {navItems.map((item) => (
                            <ResponsiveNavLink
                                key={item.name}
                                href={route(item.name)}
                                active={route().current(item.pattern)}
                            >
                                {item.label}
                            </ResponsiveNavLink>
                        ))}
                        <ResponsiveNavLink
                            href={route('schedule')}
                            active={route().current('schedule')}
                        >
                            Расписание
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('help.index')}
                            active={route().current('help.*')}
                        >
                            Справка
                        </ResponsiveNavLink>
                    </div>

                    <div className="border-t border-gray-200 pb-1 pt-4">
                        <div className="px-4">
                            <div className="text-base font-medium text-gray-800">
                                {user.name}
                            </div>
                            <div className="text-sm font-medium text-gray-500">
                                {user.email}
                            </div>
                        </div>

                        <div className="mt-3 space-y-1">
                            <ResponsiveNavLink href={route('profile.edit')}>
                                Профиль
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                method="post"
                                href={route('logout')}
                                as="button"
                            >
                                Выйти
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            {header && (
                <header className="bg-white shadow">
                    <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        {header}
                    </div>
                </header>
            )}

            <main>{children}</main>
        </div>
    );
}
