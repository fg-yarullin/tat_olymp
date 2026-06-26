<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Центр справки: инструкции и FAQ. Сами материалы — markdown-файлы в docs/, здесь только реестр.
 * Чтобы добавить новый материал: создать файл в docs/ и добавить запись в DOCS. Необязательный
 * ключ 'roles' ограничивает показ в индексе теми ролями (админ/Казань видят всё).
 */
class HelpController extends Controller
{
    private const DOCS = [
        'scans' => [
            'title' => 'Загрузка сканов работ',
            'description' => 'Как загрузить сканы работ муниципального этапа — через сайт и через сервер (большие архивы).',
            'category' => 'Инструкции',
            'file' => 'docs/Загрузка_сканов.md',
            'roles' => ['municipal_coordinator'],
        ],
        'faq-school' => [
            'title' => 'FAQ — для школы',
            'description' => 'Учащиеся, ввод результатов ШЭ, сроки, призёры, приглашения на МЭ.',
            'category' => 'FAQ',
            'file' => 'docs/FAQ_школа.md',
            'roles' => ['school_operator'],
        ],
        'faq-municipal' => [
            'title' => 'FAQ — для координатора',
            'description' => 'Состав МЭ, шифры, сканы, председатели комиссий, протокол.',
            'category' => 'FAQ',
            'file' => 'docs/FAQ_координатор.md',
            'roles' => ['municipal_coordinator'],
        ],
        'faq-chair' => [
            'title' => 'FAQ — для председателя',
            'description' => 'Обезличенный ввод по шифру, массовый ввод, окно ввода.',
            'category' => 'FAQ',
            'file' => 'docs/FAQ_председатель.md',
            'roles' => ['commission_chair'],
        ],
    ];

    public function index(Request $request): Response
    {
        return Inertia::render('Help/Index', ['docs' => $this->docsFor($request->user()->role->value)]);
    }

    public function show(string $doc): Response
    {
        $meta = self::DOCS[$doc] ?? abort(404);
        $path = base_path($meta['file']);
        abort_unless(is_file($path), 404);

        return Inertia::render('Help/Document', [
            'title' => $meta['title'],
            'html' => (string) (new GithubFlavoredMarkdownConverter())->convert(file_get_contents($path)),
        ]);
    }

    /**
     * Материалы, доступные роли: общие (без 'roles') — всем; адресные — указанным ролям;
     * админ и Казань видят всё.
     *
     * @return Collection<int, array{slug:string,title:string,description:string,category:string}>
     */
    private function docsFor(string $role): Collection
    {
        $seesAll = in_array($role, ['admin', 'super_coordinator'], true);

        return collect(self::DOCS)
            ->filter(fn ($d) => $seesAll || ! isset($d['roles']) || in_array($role, $d['roles'], true))
            ->map(fn ($d, $slug) => [
                'slug' => $slug,
                'title' => $d['title'],
                'description' => $d['description'],
                'category' => $d['category'],
            ])->values();
    }
}
