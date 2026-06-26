import { Head, Link } from '@inertiajs/react';

export default function Welcome({ auth }) {
    const year = new Date().getFullYear();

    return (
        <>
            <Head title="Главная" />
            <div className="flex min-h-screen flex-col bg-gradient-to-b from-slate-50 to-indigo-50">
                <main className="flex flex-1 items-center justify-center px-6 py-12">
                    <div className="w-full max-w-xl text-center">
                        {/* Эмблема */}
                        <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-2xl bg-indigo-600 shadow-lg shadow-indigo-600/20">
                            <span className="text-2xl font-bold tracking-tight text-white">РИСО</span>
                        </div>

                        <h1 className="mt-8 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                            РИСО «Тат-олимп»
                        </h1>
                        <p className="mx-auto mt-4 max-w-md text-base leading-relaxed text-gray-600">
                            Региональная информационная система олимпиад школьников
                            Республики Татарстан
                        </p>

                        {/* Действия */}
                        {auth.user ? (
                            <div className="mt-10">
                                <Link
                                    href={route('dashboard')}
                                    className="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-6 py-3 text-base font-medium text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                                >
                                    Перейти в кабинет →
                                </Link>
                            </div>
                        ) : (
                            <div className="mt-10 flex flex-col items-stretch justify-center gap-3 sm:flex-row">
                                <Link
                                    href={route('login')}
                                    className="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-6 py-3 text-base font-medium text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                                >
                                    Вход для сотрудников
                                </Link>
                                <Link
                                    href={route('guest.login')}
                                    className="inline-flex items-center justify-center rounded-lg border border-indigo-200 bg-white px-6 py-3 text-base font-medium text-indigo-700 shadow-sm transition hover:bg-indigo-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                                >
                                    Онлайн-показ работ
                                </Link>
                            </div>
                        )}

                        <div className="mt-6">
                            <Link href={route('schedule.public')} className="text-sm text-indigo-600 hover:underline">
                                Расписание олимпиад →
                            </Link>
                        </div>
                    </div>
                </main>

                <footer className="px-6 py-6 text-center text-sm text-gray-500">
                    © {year} · Министерство образования и науки Республики Татарстан
                </footer>
            </div>
        </>
    );
}
