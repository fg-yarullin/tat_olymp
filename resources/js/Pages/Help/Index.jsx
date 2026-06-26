import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function HelpIndex({ docs = [] }) {
    // Группировка по категориям с сохранением порядка появления.
    const categories = [];
    const byCat = {};
    docs.forEach((d) => {
        if (!byCat[d.category]) {
            byCat[d.category] = [];
            categories.push(d.category);
        }
        byCat[d.category].push(d);
    });

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Справка</h2>}
        >
            <Head title="Справка" />

            <div className="py-8">
                <div className="mx-auto max-w-3xl space-y-8 px-4 sm:px-6 lg:px-8">
                    <p className="text-sm text-gray-500">Инструкции и ответы на частые вопросы по работе с системой.</p>

                    {docs.length === 0 ? (
                        <div className="rounded-lg bg-white p-10 text-center text-gray-400 shadow">
                            Материалы пока не добавлены.
                        </div>
                    ) : (
                        categories.map((cat) => (
                            <div key={cat} className="space-y-3">
                                <h3 className="text-sm font-semibold uppercase tracking-wide text-gray-400">{cat}</h3>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {byCat[cat].map((d) => (
                                        <Link
                                            key={d.slug}
                                            href={route('help.show', d.slug)}
                                            className="block rounded-lg bg-white p-5 shadow transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                                        >
                                            <div className="font-medium text-gray-800">{d.title}</div>
                                            <div className="mt-1 text-sm text-gray-500">{d.description}</div>
                                            <div className="mt-3 text-sm font-medium text-indigo-600">Открыть →</div>
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
