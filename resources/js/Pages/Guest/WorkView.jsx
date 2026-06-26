import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

const STAGE_LABELS = {
    school: 'Школьный',
    municipal: 'Муниципальный',
    regional: 'Региональный',
};

export default function WorkView({ work }) {
    const { flash } = usePage().props;
    const { post, processing } = useForm();

    const submitAppeal = () => {
        post(route('guest.appeal.submit', work.id), { preserveScroll: true });
    };

    return (
        <div className="min-h-screen bg-gray-100 py-10">
            <Head title={`Работа · ${work.subject}`} />

            <div className="mx-auto max-w-3xl px-4">
                <Link
                    href={route('guest.works')}
                    className="text-sm text-gray-600 underline hover:text-gray-900"
                >
                    ← К списку работ
                </Link>

                <div className="mt-4 rounded bg-white p-6 shadow">
                    <h1 className="text-xl font-semibold text-gray-800">
                        {work.subject}
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        {STAGE_LABELS[work.stage] ?? work.stage} этап · Балл:{' '}
                        {work.score ?? '—'}
                    </p>

                    {flash?.success && (
                        <div className="mt-4 rounded bg-green-50 p-3 text-sm text-green-700">
                            {flash.success}
                        </div>
                    )}
                    {flash?.error && (
                        <div className="mt-4 rounded bg-red-50 p-3 text-sm text-red-700">
                            {flash.error}
                        </div>
                    )}

                    <div className="mt-6">
                        {work.scan_url ? (
                            <iframe
                                title="Скан-копия работы"
                                src={work.scan_url}
                                className="h-[70vh] w-full rounded border"
                            />
                        ) : (
                            <div className="rounded border border-dashed p-8 text-center text-gray-400">
                                Скан-копия недоступна.
                            </div>
                        )}
                    </div>

                    {work.can_appeal && (
                        <div className="mt-6 flex justify-end">
                            <button
                                onClick={submitAppeal}
                                disabled={processing}
                                className="rounded bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                            >
                                Подать апелляцию
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
