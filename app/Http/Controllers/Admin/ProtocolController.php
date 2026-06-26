<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProtocolColumn;
use App\Models\ProtocolTemplate;
use App\Models\Subject;
use App\Support\ProtocolSources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Конструктор протоколов (админ): шаблоны по этапу/предмету и их колонки.
 */
class ProtocolController extends Controller
{
    private const STAGES = ['school', 'municipal', 'regional'];

    public function index(): Response
    {
        $templates = ProtocolTemplate::with('subject:id,name')->withCount('columns')
            ->orderBy('stage')->get()
            ->map(fn (ProtocolTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'stage' => $t->stage,
                'subject_id' => $t->subject_id,
                'subject' => $t->subject?->name,
                'columns_count' => $t->columns_count,
            ]);

        return Inertia::render('Admin/Protocols/Index', [
            'templates' => $templates,
            'stages' => self::STAGES,
            'subjects' => Subject::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateTemplate($request, null);
        ProtocolTemplate::create($data);

        return back()->with('success', 'Шаблон протокола создан.');
    }

    public function update(Request $request, ProtocolTemplate $protocol): RedirectResponse
    {
        $data = $this->validateTemplate($request, $protocol);
        $protocol->update($data);

        return back()->with('success', 'Шаблон обновлён.');
    }

    public function destroy(ProtocolTemplate $protocol): RedirectResponse
    {
        $protocol->delete();

        return redirect()->route('admin.protocols.index')->with('success', 'Шаблон удалён.');
    }

    /** Создаёт копию шаблона со всеми колонками (на другой этап/предмет — ограничение уникальности). */
    public function duplicate(Request $request, ProtocolTemplate $protocol): RedirectResponse
    {
        $data = $this->validateTemplate($request, null);
        $copy = ProtocolTemplate::create($data);

        foreach ($protocol->columns()->orderBy('position')->get() as $col) {
            $copy->columns()->create([
                'position' => $col->position,
                'header' => $col->header,
                'group_header' => $col->group_header,
                'source_key' => $col->source_key,
            ]);
        }

        return redirect()->route('admin.protocols.show', $copy->id)
            ->with('success', "Копия создана: «{$copy->name}» ({$copy->columns()->count()} колонок).");
    }

    public function show(ProtocolTemplate $protocol): Response
    {
        $protocol->load(['columns', 'subject:id,name']);

        return Inertia::render('Admin/Protocols/Show', [
            'template' => [
                'id' => $protocol->id,
                'name' => $protocol->name,
                'stage' => $protocol->stage,
                'subject' => $protocol->subject?->name,
                'columns' => $protocol->columns->map(fn (ProtocolColumn $c) => [
                    'id' => $c->id,
                    'position' => $c->position,
                    'header' => $c->header,
                    'group_header' => $c->group_header,
                    'source_key' => $c->source_key,
                ]),
            ],
            'sources' => ProtocolSources::options(),
        ]);
    }

    public function storeColumn(Request $request, ProtocolTemplate $protocol): RedirectResponse
    {
        $data = $this->validateColumn($request);
        $data['position'] = ((int) $protocol->columns()->max('position')) + 1;
        $protocol->columns()->create($data);

        return back()->with('success', 'Колонка добавлена.');
    }

    public function updateColumn(Request $request, ProtocolColumn $column): RedirectResponse
    {
        $column->update($this->validateColumn($request));

        return back()->with('success', 'Колонка обновлена.');
    }

    public function destroyColumn(ProtocolColumn $column): RedirectResponse
    {
        $column->delete();

        return back()->with('success', 'Колонка удалена.');
    }

    /** Переупорядочивание колонок: ids в нужном порядке. */
    public function reorder(Request $request, ProtocolTemplate $protocol): RedirectResponse
    {
        $validated = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        foreach ($validated['ids'] as $position => $id) {
            $protocol->columns()->whereKey($id)->update(['position' => $position + 1]);
        }

        return back()->with('success', 'Порядок колонок сохранён.');
    }

    private function validateTemplate(Request $request, ?ProtocolTemplate $template): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'stage' => ['required', Rule::in(self::STAGES)],
            'subject_id' => ['nullable', 'exists:subjects,id'],
        ]);

        // Один шаблон на (этап, предмет); общий (без предмета) — один на этап.
        $exists = ProtocolTemplate::where('stage', $data['stage'])
            ->where('subject_id', $data['subject_id'] ?? null)
            ->when($template, fn ($q) => $q->whereKeyNot($template->id))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'subject_id' => 'Шаблон для этого этапа и предмета уже существует.',
            ]);
        }

        return $data;
    }

    private function validateColumn(Request $request): array
    {
        return $request->validate([
            'header' => ['required', 'string', 'max:255'],
            'group_header' => ['nullable', 'string', 'max:255'],
            'source_key' => [
                'required', 'string',
                function ($attr, $value, $fail) {
                    $valid = array_key_exists($value, ProtocolSources::OPTIONS)
                        || preg_match('/^(question|appeal):\d+$/', $value);
                    if (! $valid) {
                        $fail('Неизвестный источник значения.');
                    }
                },
            ],
        ]);
    }
}
