import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function HelpDocument({ title, html }) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">{title}</h2>}
        >
            <Head title={title} />

            <div className="py-8">
                <div className="mx-auto max-w-3xl space-y-4 px-4 sm:px-6 lg:px-8">
                    <Link href={route('help.index')} className="inline-block text-sm text-gray-500 hover:underline">
                        ← К справке
                    </Link>
                    <div className="rounded-lg bg-white p-6 shadow sm:p-8">
                        <div className="doc" dangerouslySetInnerHTML={{ __html: html }} />
                    </div>
                </div>
            </div>

            {/* Базовое оформление markdown (без плагина typography) */}
            <style>{`
                .doc { color: #374151; font-size: 0.925rem; line-height: 1.65; }
                .doc h1 { font-size: 1.5rem; font-weight: 700; color: #111827; margin: 0 0 1rem; }
                .doc h2 { font-size: 1.15rem; font-weight: 600; color: #111827; margin: 1.75rem 0 0.75rem; }
                .doc h3 { font-size: 1rem; font-weight: 600; color: #111827; margin: 1.25rem 0 0.5rem; }
                .doc p { margin: 0.75rem 0; }
                .doc ul, .doc ol { margin: 0.75rem 0; padding-left: 1.5rem; }
                .doc ul { list-style: disc; }
                .doc ol { list-style: decimal; }
                .doc li { margin: 0.3rem 0; }
                .doc a { color: #4f46e5; text-decoration: underline; }
                .doc code { background: #f3f4f6; padding: 0.1rem 0.35rem; border-radius: 0.25rem; font-size: 0.85em; }
                .doc pre { background: #1e293b; color: #e2e8f0; padding: 1rem; border-radius: 0.5rem; overflow-x: auto; margin: 0.75rem 0; }
                .doc pre code { background: transparent; padding: 0; color: inherit; }
                .doc blockquote { border-left: 3px solid #c7d2fe; background: #eef2ff; padding: 0.5rem 1rem; margin: 0.75rem 0; color: #4338ca; border-radius: 0 0.25rem 0.25rem 0; }
                .doc table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 0.875rem; }
                .doc th, .doc td { border: 1px solid #e5e7eb; padding: 0.5rem 0.75rem; text-align: left; vertical-align: top; }
                .doc th { background: #f9fafb; font-weight: 600; }
                .doc hr { border: 0; border-top: 1px solid #e5e7eb; margin: 1.5rem 0; }
            `}</style>
        </AuthenticatedLayout>
    );
}
