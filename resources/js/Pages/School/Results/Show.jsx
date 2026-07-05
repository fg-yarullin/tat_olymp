import ColumnsMenu from '@/Components/ColumnsMenu';
import Countdown from '@/Components/Countdown';
import ImportProgress from '@/Components/ImportProgress';
import Modal from '@/Components/Modal';
import ScoreCell from '@/Components/ScoreCell';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useChunkedImport } from '@/Hooks/useChunkedImport';
import { useStoredColumns } from '@/Hooks/useStoredColumns';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const LEVEL_LABELS = { regional: 'Региональный', republican: 'Республиканский' };
const STATUS_LABELS = { participant: 'Участник', prize_winner: 'Призёр', winner: 'Победитель' };
// Российский формат дробных чисел: разделитель — запятая.
const fmt = (n) => (n == null || n === '' ? '—' : String(n).replace('.', ','));

const blank = {
    student_id: '',
    participation_grade: '',
    score: '',
    result_status: 'participant',
    prev_municipal_winner: false,
    teacher_name: '',
    teacher_workplace: '',
    profile: '',
    practice_types: '',
};

export default function ResultsShow({ olympiad, participations, filters, grade_options = [], pgrade_options = [], over_max_count = 0, students, letters, is_technology, tech_profiles = [], school_name = '', teachers = [], auto_status = { mode: 'operator', thresholds: {} } }) {
    const { errors } = usePage().props;
    const open = olympiad.import_open;

    // Опциональные колонки (технология): видимость хранится в браузере, по умолчанию скрыты.
    const [cols, toggleCol] = useStoredColumns('cols:school-results', { profile: false, practice: false });
    const colOptions = is_technology
        ? [{ key: 'profile', label: 'Направление' }, { key: 'practice', label: 'Вид практики' }]
        : [];

    // Поиск / сортировка / пагинация — состояние живёт в URL, сервер хранит последний вид в сессии.
    const [search, setSearch] = useState(filters.q ?? '');
    const go = (params) =>
        router.get(
            route('school.results.show', olympiad.id),
            {
                q: search || undefined,
                sort: filters.sort !== 'default' ? filters.sort : undefined,
                dir: filters.dir,
                grade: filters.grade ?? undefined,
                pgrade: filters.pgrade ?? undefined,
                over: filters.over ? 1 : undefined,
                ...params,
            },
            { preserveState: true, preserveScroll: true },
        );
    const sortBy = (col) => {
        const dir = filters.sort === col && filters.dir === 'asc' ? 'desc' : 'asc';
        go({ sort: col, dir, page: undefined });
    };
    const submitSearch = (e) => {
        e.preventDefault();
        go({ page: undefined });
    };

    // Авто-статусы: доступны оператору, если режим operator и админ задал пороги.
    const thresholdGrades = Object.keys(auto_status.thresholds ?? {});
    const canAutoStatus = auto_status.mode === 'operator' && thresholdGrades.length > 0;
    const [showAutoStatus, setShowAutoStatus] = useState(false);
    const [showTechRef, setShowTechRef] = useState(false);
    const applyAutoStatus = () => {
        router.post(route('school.results.auto-status', olympiad.id), {}, {
            preserveScroll: true, onSuccess: () => setShowAutoStatus(false),
        });
    };

    const [tplGrade, setTplGrade] = useState('');
    const [tplLetter, setTplLetter] = useState('');
    const templateUrl = route('school.olympiad.template', {
        olympiad: olympiad.id,
        ...(tplGrade ? { grade: tplGrade } : {}),
        ...(tplLetter ? { letter: tplLetter } : {}),
    });

    const [importFile, setImportFile] = useState(null);
    // Ключ для пересоздания <input type=file> — нативный input не очищается через value/reset.
    const [importKey, setImportKey] = useState(0);
    const chunked = useChunkedImport({
        startUrl: route('school.olympiad.import', olympiad.id),
        chunkUrl: (id) => route('school.olympiad.import.chunk', id),
        errorsUrl: (id) => route('school.olympiad.import.errors', id),
    });
    const submitImport = (e) => {
        e.preventDefault();
        chunked.run(importFile);
    };
    const resetImport = () => {
        chunked.reset();
        setImportFile(null);
        setImportKey((k) => k + 1);
    };
    // После завершения импорта обновляем таблицу — прогресс-бар остаётся виден.
    useEffect(() => {
        if (chunked.progress?.done) {
            router.reload({ only: ['participations', 'over_max_count', 'teachers'] });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [chunked.progress?.done]);

    // Модальное окно ввода/редактирования участия
    const [showModal, setShowModal] = useState(false);
    const [editing, setEditing] = useState(false);
    // Сначала выбираем класс (параллель + литера) из имеющихся в школе, затем ученика.
    const [pickClass, setPickClass] = useState('');
    const form = useForm({ ...blank });
    const selectedStudent = students.find((s) => String(s.id) === String(form.data.student_id));
    const gradeOptions = useMemo(() => {
        if (!selectedStudent) return olympiad.grades;
        return olympiad.grades.filter((g) => g >= selectedStudent.real_grade);
    }, [selectedStudent, olympiad.grades]);
    // Имеющиеся в школе классы: уникальные пары (класс + литера), напр. «7-А» или «7» без литеры.
    const classList = useMemo(() => {
        const map = new Map();
        for (const s of students) {
            const letter = s.class_letter || '';
            const key = `${s.real_grade}|${letter}`;
            if (!map.has(key)) {
                map.set(key, { key, grade: s.real_grade, letter, label: letter ? `${s.real_grade}-${letter}` : `${s.real_grade}` });
            }
        }
        return [...map.values()].sort((a, b) => a.grade - b.grade || a.letter.localeCompare(b.letter));
    }, [students]);
    const studentsToShow = useMemo(
        () => students.filter((s) => `${s.real_grade}|${s.class_letter || ''}` === pickClass),
        [students, pickClass],
    );
    // Макс. балл по выбранному классу участия (только показ; задаёт администратор).
    const currentMax = form.data.participation_grade
        ? olympiad.max_scores?.[form.data.participation_grade]
        : undefined;
    // Технология: выбранное направление определяет список видов практик.
    const techProfile = useMemo(
        () => tech_profiles.find((p) => p.name === form.data.profile),
        [tech_profiles, form.data.profile],
    );
    // Тренер-тренер работает в той же школе → место работы = название ОО, иначе ручной ввод.
    const [sameSchool, setSameSchool] = useState(false);
    const teacherByName = useMemo(() => Object.fromEntries(teachers.map((t) => [t.name, t.workplace ?? ''])), [teachers]);
    const workplaceOptions = useMemo(() => [...new Set(teachers.map((t) => t.workplace).filter(Boolean))], [teachers]);
    // Выбор известного тренера автозаполняет место работы и галочку «эта школа».
    const onTeacherName = (val) => {
        const next = { ...form.data, teacher_name: val };
        if (Object.prototype.hasOwnProperty.call(teacherByName, val)) {
            next.teacher_workplace = teacherByName[val];
            setSameSchool(teacherByName[val] === school_name);
        }
        form.setData(next);
    };
    const toggleSameSchool = (checked) => {
        setSameSchool(checked);
        form.setData('teacher_workplace', checked ? school_name : '');
    };

    const openAdd = () => {
        setEditing(false);
        setPickClass('');
        setSameSchool(false);
        form.setData({ ...blank });
        form.clearErrors();
        setShowModal(true);
    };
    const openEdit = (p) => {
        setEditing(true);
        setPickClass(`${p.real_grade}|${p.class_letter || ''}`);
        setSameSchool(!!p.teacher_workplace && p.teacher_workplace === school_name);
        form.setData({
            student_id: String(p.student_id),
            participation_grade: String(p.participation_grade),
            score: p.score ?? '',
            result_status: p.result_status ?? 'participant',
            prev_municipal_winner: !!p.prev_municipal_winner,
            teacher_name: p.teacher_name ?? '',
            teacher_workplace: p.teacher_workplace ?? '',
            profile: p.profile ?? '',
            practice_types: p.practice_types ?? '',
        });
        form.clearErrors();
        setShowModal(true);
    };
    const submit = (e) => {
        e.preventDefault();
        form.post(route('school.results.store', olympiad.id), {
            preserveScroll: true, onSuccess: () => setShowModal(false),
        });
    };
    const removeRow = (p) => {
        if (confirm(`Удалить участие «${p.fio}» (класс ${p.participation_grade})?`)) {
            router.delete(route('school.results.destroy', p.id), { preserveScroll: true });
        }
    };

    // Массовое удаление: чекбоксы по строкам, «выбрать все по фильтру» и полная очистка олимпиады.
    const [selected, setSelected] = useState(() => new Set());
    const [selectAllFiltered, setSelectAllFiltered] = useState(false);
    const [showWipeModal, setShowWipeModal] = useState(false);
    const [wipeConfirmText, setWipeConfirmText] = useState('');
    const clearSelection = () => { setSelected(new Set()); setSelectAllFiltered(false); };
    // Сброс выбора при смене страницы/фильтров — иначе можно случайно удалить не то, что видно на экране.
    useEffect(() => {
        clearSelection();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [participations.current_page, filters.q, filters.grade, filters.pgrade, filters.over, filters.sort, filters.dir]);

    const pageIds = participations.data.map((p) => p.id);
    const allOnPageSelected = pageIds.length > 0 && pageIds.every((id) => selected.has(id));
    const selectedCount = selectAllFiltered ? participations.total : selected.size;
    const toggleRow = (id) => {
        setSelectAllFiltered(false);
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    };
    const toggleAllOnPage = () => {
        setSelectAllFiltered(false);
        setSelected((prev) => {
            if (allOnPageSelected) {
                const next = new Set(prev);
                pageIds.forEach((id) => next.delete(id));
                return next;
            }
            return new Set([...prev, ...pageIds]);
        });
    };
    // Текущий вид (фильтры/сортировка/страница) — чтобы сервер после удаления знал, куда
    // вернуть оператора (и подвинул страницу назад, если текущая опустела).
    const currentView = () => ({
        q: filters.q || undefined,
        sort: filters.sort !== 'default' ? filters.sort : undefined,
        dir: filters.dir,
        grade: filters.grade ?? undefined,
        pgrade: filters.pgrade ?? undefined,
        over: filters.over ? 1 : undefined,
        page: participations.current_page,
    });
    const bulkDeleteSelected = () => {
        if (selectedCount === 0) return;
        if (!confirm(`Удалить выбранные результаты (${selectedCount})? Действие необратимо.`)) return;
        const payload = {
            ...currentView(),
            ...(selectAllFiltered ? { mode: 'filtered' } : { mode: 'selected', ids: [...selected] }),
        };
        router.post(route('school.results.bulk-destroy', olympiad.id), payload, {
            preserveScroll: true,
            onSuccess: clearSelection,
        });
    };
    const wipeAll = () => {
        if (wipeConfirmText.trim() !== String(participations.total)) return;
        router.post(route('school.results.bulk-destroy', olympiad.id), { ...currentView(), mode: 'all' }, {
            preserveScroll: true,
            onSuccess: () => { setShowWipeModal(false); setWipeConfirmText(''); clearSelection(); },
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Результаты · {olympiad.subject}
                </h2>
            }
        >
            <Head title={`Результаты · ${olympiad.subject}`} />

            <div className="py-8">
                <div className="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between">
                        <Link href={route('school.results.index')} className="text-sm text-gray-500 hover:underline">
                            ← К списку олимпиад
                        </Link>
                        <a href={route('school.results.protocol', olympiad.id)}
                            className="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                            ↓ Скачать протокол (XLSX)
                        </a>
                    </div>

                    {errors?.file && <div className="rounded bg-amber-50 p-3 text-sm text-amber-700">{errors.file}</div>}
                    {errors?.auto_status && <div className="rounded bg-amber-50 p-3 text-sm text-amber-700">{errors.auto_status}</div>}

                    <div className="rounded-lg bg-white p-4 text-sm text-gray-600 shadow">
                        <b>{olympiad.subject}</b> · {LEVEL_LABELS[olympiad.level] ?? olympiad.level} уровень ·
                        классы {olympiad.grades.join(', ')}
                    </div>

                    <Countdown
                        open={open}
                        deadline={olympiad.entry_deadline}
                        size="lg"
                        closedLabel="Ввод результатов закрыт"
                    />

                    <div className="rounded-lg bg-white p-4 shadow">
                        <h3 className="mb-2 text-sm font-semibold text-gray-800">Максимальные баллы по классам</h3>
                        {olympiad.grades.some((g) => olympiad.max_scores?.[g] != null) ? (
                            <div className="flex flex-wrap gap-2">
                                {olympiad.grades.map((g) =>
                                    olympiad.max_scores?.[g] != null ? (
                                        <span key={g} className="rounded bg-indigo-50 px-3 py-1 text-sm text-indigo-700">
                                            {g} кл. — <b>{fmt(olympiad.max_scores[g])}</b>
                                        </span>
                                    ) : null,
                                )}
                            </div>
                        ) : (
                            <p className="text-sm text-gray-400">Пока не заданы администратором (вносятся после проведения).</p>
                        )}
                    </div>

                    {open && (
                        <div className="grid gap-4 lg:grid-cols-2">
                            <div className="space-y-3 rounded-lg bg-white p-6 shadow">
                                <h3 className="font-semibold text-gray-800">Шаблон со списком учащихся</h3>
                                <div className="flex flex-wrap items-end gap-3">
                                    <div>
                                        <label className="block text-xs text-gray-500">Класс</label>
                                        <select value={tplGrade} onChange={(e) => setTplGrade(e.target.value)} className="rounded border-gray-300 text-sm">
                                            <option value="">Все</option>
                                            {Array.from({ length: 11 }, (_, i) => i + 1).map((g) => (
                                                <option key={g} value={g}>{g}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs text-gray-500">Литера</label>
                                        <select value={tplLetter} onChange={(e) => setTplLetter(e.target.value)} className="rounded border-gray-300 text-sm">
                                            <option value="">Все</option>
                                            {letters.map((l) => <option key={l} value={l}>{l}</option>)}
                                        </select>
                                    </div>
                                    <a href={templateUrl} className="rounded border border-indigo-300 px-3 py-2 text-sm text-indigo-700 hover:bg-indigo-50">
                                        ↓ Скачать шаблон (XLSX)
                                    </a>
                                </div>
                                <p className="text-xs text-gray-400">
                                    Колонки: балл, статус, призёр МЭ прошлого года, учитель, место работы. Макс. балл
                                    задаёт администратор. Для участия за несколько классов добавьте строки с разными
                                    классами участия.
                                </p>
                                {is_technology && (
                                    <p className="text-xs text-gray-500">
                                        Технология: в столбце «Код вида практики» укажите код из справочника (напр. 1.1).{' '}
                                        <button type="button" onClick={() => setShowTechRef(true)}
                                            className="font-medium text-indigo-600 hover:underline">
                                            Справочник кодов
                                        </button>
                                    </p>
                                )}
                            </div>

                            <div className="space-y-3 rounded-lg bg-white p-6 shadow">
                                <h3 className="font-semibold text-gray-800">Массовый импорт</h3>
                                {!chunked.progress && (
                                    <form onSubmit={submitImport} className="space-y-3">
                                        <input key={importKey} type="file" accept=".xlsx,.ods,.csv,.txt"
                                            onChange={(e) => setImportFile(e.target.files[0] ?? null)} className="text-sm" />
                                        <button type="submit" disabled={!importFile || chunked.running}
                                            className="block rounded bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50">
                                            Загрузить файл
                                        </button>
                                    </form>
                                )}
                                <ImportProgress progress={chunked.progress} error={chunked.error} errorsHref={chunked.errorsHref} onReset={resetImport} />
                            </div>
                        </div>
                    )}

                    {over_max_count > 0 && (
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                            <span>
                                ⚠ Баллов выше максимального: <b>{over_max_count}</b>. Это могло произойти, если баллы внесли
                                до того, как администратор задал максимальные баллы. Исправьте такие результаты.
                            </span>
                            <button
                                onClick={() => go({ over: filters.over ? undefined : 1, page: undefined })}
                                className={`rounded border px-3 py-2 font-medium ${
                                    filters.over ? 'border-red-400 bg-red-100' : 'border-red-300 hover:bg-red-100'
                                }`}
                            >
                                {filters.over ? 'Показать все' : 'Показать только превышения'}
                            </button>
                        </div>
                    )}

                    <div className="overflow-x-auto rounded-lg bg-white shadow">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-3">
                            <h3 className="font-semibold text-gray-800">Внесённые результаты ({participations.total})</h3>
                            <div className="flex flex-wrap items-center gap-3">
                                <select value={filters.grade ?? ''}
                                    onChange={(e) => go({ grade: e.target.value || undefined, page: undefined })}
                                    className="rounded border-gray-300 text-sm">
                                    <option value="">Класс обуч.: все</option>
                                    {grade_options.map((g) => <option key={g} value={g}>{g} класс</option>)}
                                </select>
                                <select value={filters.pgrade ?? ''}
                                    onChange={(e) => go({ pgrade: e.target.value || undefined, page: undefined })}
                                    className="rounded border-gray-300 text-sm">
                                    <option value="">Класс участия: все</option>
                                    {pgrade_options.map((g) => <option key={g} value={g}>{g} класс</option>)}
                                </select>
                                <form onSubmit={submitSearch} className="flex gap-2">
                                    <input type="text" value={search} onChange={(e) => setSearch(e.target.value)}
                                        placeholder="Поиск по ФИО участника"
                                        className="w-56 rounded border-gray-300 text-sm" />
                                    <button type="submit" className="rounded bg-gray-200 px-3 py-2 text-sm hover:bg-gray-300">Найти</button>
                                    {filters.q && (
                                        <button type="button" onClick={() => { setSearch(''); go({ q: undefined, page: undefined }); }}
                                            className="rounded px-2 py-2 text-sm text-gray-500 hover:underline">Сброс</button>
                                    )}
                                </form>
                                <ColumnsMenu options={colOptions} cols={cols} onToggle={toggleCol} />
                                {open && canAutoStatus && (
                                    <button onClick={() => setShowAutoStatus(true)}
                                        className="rounded border border-amber-300 px-3 py-2 text-sm font-medium text-amber-700 hover:bg-amber-50">
                                        Авто-статусы
                                    </button>
                                )}
                                {open && (
                                    <button onClick={openAdd}
                                        className="rounded bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                        + Добавить участие
                                    </button>
                                )}
                                {open && participations.total > 0 && (
                                    <button onClick={() => setShowWipeModal(true)}
                                        className="rounded border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                                        Удалить все результаты
                                    </button>
                                )}
                            </div>
                        </div>
                        {open && selectedCount > 0 && (
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b bg-indigo-50 px-6 py-3 text-sm">
                                <span className="text-indigo-800">
                                    {selectAllFiltered
                                        ? `Выбраны все результаты по текущему фильтру: ${selectedCount}.`
                                        : (
                                            <>
                                                Выбрано: {selectedCount}.{' '}
                                                {allOnPageSelected && participations.total > pageIds.length && (
                                                    <button type="button" onClick={() => setSelectAllFiltered(true)}
                                                        className="font-medium text-indigo-700 hover:underline">
                                                        Выбрать все {participations.total} по текущему фильтру
                                                    </button>
                                                )}
                                            </>
                                        )}
                                </span>
                                <div className="flex items-center gap-3">
                                    <button type="button" onClick={clearSelection} className="text-gray-500 hover:underline">
                                        Отменить выбор
                                    </button>
                                    <button type="button" onClick={bulkDeleteSelected}
                                        className="rounded bg-red-600 px-3 py-2 font-medium text-white hover:bg-red-700">
                                        Удалить выбранные ({selectedCount})
                                    </button>
                                </div>
                            </div>
                        )}
                        {participations.data.length === 0 ? (
                            filters.over && over_max_count === 0 ? (
                                <div className="px-6 py-8 text-center">
                                    <p className="text-sm font-medium text-green-700">
                                        ✓ Все результаты с превышением максимального балла исправлены.
                                    </p>
                                    <button onClick={() => go({ over: undefined, page: undefined })}
                                        className="mt-3 rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                        Показать все результаты
                                    </button>
                                </div>
                            ) : (
                                <p className="px-6 py-8 text-center text-sm text-gray-400">
                                    {filters.q || filters.grade || filters.pgrade || filters.over ? 'Ничего не найдено по фильтрам.' : 'Результатов пока нет.'}
                                </p>
                            )
                        ) : (
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <tr>
                                        {open && (
                                            <th className="px-3 py-3">
                                                <input type="checkbox" checked={selectAllFiltered || allOnPageSelected}
                                                    onChange={toggleAllOnPage} />
                                            </th>
                                        )}
                                        <SortHeader col="fio" filters={filters} onSort={sortBy}>Ученик</SortHeader>
                                        <SortHeader col="real_grade" filters={filters} onSort={sortBy}>Кл.</SortHeader>
                                        <SortHeader col="participation_grade" filters={filters} onSort={sortBy}>Кл. уч.</SortHeader>
                                        <SortHeader col="score" filters={filters} onSort={sortBy}>Балл</SortHeader>
                                        <th className="px-3 py-3">Макс.</th>
                                        <SortHeader col="result_status" filters={filters} onSort={sortBy}>Статус</SortHeader>
                                        {is_technology && cols.profile && <th className="px-3 py-3">Направление</th>}
                                        {is_technology && cols.practice && <th className="px-3 py-3">Вид практики</th>}
                                        <th className="px-3 py-3">Учитель</th>
                                        {open && <th className="px-3 py-3"></th>}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {participations.data.map((p) => (
                                        <tr key={p.id} className={p.over_max ? 'bg-red-50 hover:bg-red-100' : 'hover:bg-gray-50'}>
                                            {open && (
                                                <td className="px-3 py-2">
                                                    <input type="checkbox" checked={selectAllFiltered || selected.has(p.id)}
                                                        onChange={() => toggleRow(p.id)} />
                                                </td>
                                            )}
                                            <td className="px-3 py-2 font-medium text-gray-800">{p.fio}</td>
                                            <td className="px-3 py-2 text-gray-600">{p.real_grade}{p.class_letter ? `-${p.class_letter}` : ''}</td>
                                            <td className="px-3 py-2 text-gray-600">{p.participation_grade}</td>
                                            <td className={`px-3 py-2 font-medium ${p.over_max ? 'text-red-700' : ''}`}>
                                                <ScoreCell value={p.score} editable={open}
                                                    url={route('school.results.score', p.id)} />
                                                {p.over_max && (
                                                    <span className="ml-1 text-xs font-normal text-red-600">{`> макс. ${fmt(p.max_score)}`}</span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-gray-400">{p.max_score != null ? fmt(p.max_score) : '—'}</td>
                                            <td className="px-3 py-2 text-gray-500">
                                                {STATUS_LABELS[p.result_status] ?? p.result_status}
                                                {p.prev_municipal_winner ? ' · призёр МЭ' : ''}
                                            </td>
                                            {is_technology && cols.profile && <td className="px-3 py-2 text-gray-500">{p.profile || '—'}</td>}
                                            {is_technology && cols.practice && <td className="px-3 py-2 text-gray-500">{p.practice_types || '—'}</td>}
                                            <td className="px-3 py-2 text-gray-500">{p.teacher_name ?? '—'}</td>
                                            {open && (
                                                <td className="px-3 py-2 whitespace-nowrap text-right">
                                                    <button onClick={() => openEdit(p)} className="mr-3 text-indigo-600 hover:underline">Изменить</button>
                                                    <button onClick={() => removeRow(p)} className="text-red-600 hover:underline">Удалить</button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        {participations.links.length > 3 && (
                            <div className="flex flex-wrap gap-1 border-t px-6 py-3">
                                {participations.links.map((link, i) => (
                                    <button key={i} disabled={!link.url}
                                        onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })}
                                        className={`rounded px-3 py-1 text-sm ${
                                            link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'
                                        } ${!link.url ? 'opacity-40' : ''}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }} />
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <Modal show={showModal} onClose={() => setShowModal(false)} maxWidth="2xl">
                <form onSubmit={submit} className="space-y-4 p-6">
                    <h3 className="font-semibold text-gray-800">
                        {editing ? 'Редактирование участия' : 'Добавить участие'}
                    </h3>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label className="block text-xs text-gray-500">Класс</label>
                            <select value={pickClass} disabled={editing}
                                onChange={(e) => {
                                    setPickClass(e.target.value);
                                    form.setData({ ...form.data, student_id: '', participation_grade: '' });
                                }}
                                className="w-full rounded border-gray-300 text-sm disabled:bg-gray-100">
                                <option value="">— выберите —</option>
                                {classList.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">Ученик</label>
                            <select value={form.data.student_id} disabled={editing || !pickClass}
                                onChange={(e) => form.setData({ ...form.data, student_id: e.target.value, participation_grade: '' })}
                                className="w-full rounded border-gray-300 text-sm disabled:bg-gray-100">
                                <option value="">{pickClass ? '— выберите —' : 'сначала класс'}</option>
                                {studentsToShow.map((s) => <option key={s.id} value={s.id}>{s.fio}</option>)}
                            </select>
                            {form.errors.student_id && <p className="text-xs text-red-600">{form.errors.student_id}</p>}
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">Класс участия</label>
                            <select value={form.data.participation_grade} disabled={editing}
                                onChange={(e) => form.setData('participation_grade', e.target.value)}
                                className="w-full rounded border-gray-300 text-sm disabled:bg-gray-100">
                                <option value="">—</option>
                                {gradeOptions.map((g) => <option key={g} value={g}>{g}</option>)}
                            </select>
                            {form.errors.participation_grade && <p className="text-xs text-red-600">{form.errors.participation_grade}</p>}
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">
                                Балл{currentMax != null && currentMax !== '' ? ` (макс. ${fmt(currentMax)})` : ''}
                            </label>
                            <input type="text" inputMode="decimal" value={form.data.score}
                                onChange={(e) => form.setData('score', e.target.value.replace(/[^\d.,]/g, ''))}
                                placeholder="напр. 12,5"
                                className="w-full rounded border-gray-300 text-sm" />
                            {currentMax != null && currentMax !== '' ? (
                                <p className="text-xs text-gray-400">Максимальный балл за {form.data.participation_grade} класс: {fmt(currentMax)}</p>
                            ) : (
                                form.data.participation_grade && <p className="text-xs text-gray-400">Макс. балл ещё не задан администратором.</p>
                            )}
                            {form.errors.score && <p className="text-xs text-red-600">{form.errors.score}</p>}
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">Статус</label>
                            <select value={form.data.result_status} onChange={(e) => form.setData('result_status', e.target.value)}
                                className="w-full rounded border-gray-300 text-sm">
                                <option value="participant">Участник</option>
                                <option value="prize_winner">Призёр</option>
                                <option value="winner">Победитель</option>
                            </select>
                        </div>
                        <label className="flex items-end gap-2 text-sm text-gray-700">
                            <input type="checkbox" checked={form.data.prev_municipal_winner}
                                onChange={(e) => form.setData('prev_municipal_winner', e.target.checked)} className="mb-2" />
                            Призёр МЭ прошлого года
                        </label>
                        <div>
                            <label className="block text-xs text-gray-500">Учитель (тренер)</label>
                            <input list="teacher-names" value={form.data.teacher_name}
                                onChange={(e) => onTeacherName(e.target.value)}
                                className="w-full rounded border-gray-300 text-sm" />
                            <datalist id="teacher-names">
                                {teachers.map((t) => <option key={t.name} value={t.name} />)}
                            </datalist>
                        </div>
                        <div>
                            <label className="mb-1 flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" checked={sameSchool}
                                    onChange={(e) => toggleSameSchool(e.target.checked)} />
                                Тренер работает в этой школе
                            </label>
                            {sameSchool ? (
                                <p className="text-xs text-gray-400">Место работы: {school_name || '—'}</p>
                            ) : (
                                <>
                                    <input list="teacher-workplaces" value={form.data.teacher_workplace}
                                        onChange={(e) => form.setData('teacher_workplace', e.target.value)}
                                        placeholder="Место работы учителя"
                                        className="w-full rounded border-gray-300 text-sm" />
                                    <datalist id="teacher-workplaces">
                                        {workplaceOptions.map((w) => <option key={w} value={w} />)}
                                    </datalist>
                                </>
                            )}
                        </div>
                        {is_technology && (
                            <>
                                <div>
                                    <label className="block text-xs text-gray-500">Направление (технология)</label>
                                    <select value={form.data.profile}
                                        onChange={(e) => form.setData({ ...form.data, profile: e.target.value, practice_types: '' })}
                                        className="w-full rounded border-gray-300 text-sm">
                                        <option value="">— выберите —</option>
                                        {tech_profiles.map((p) => <option key={p.id} value={p.name}>{p.name}</option>)}
                                        {form.data.profile && !tech_profiles.some((p) => p.name === form.data.profile) && (
                                            <option value={form.data.profile}>{form.data.profile}</option>
                                        )}
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-xs text-gray-500">Вид практики</label>
                                    <select value={form.data.practice_types} disabled={!techProfile}
                                        onChange={(e) => form.setData('practice_types', e.target.value)}
                                        className="w-full rounded border-gray-300 text-sm disabled:bg-gray-100">
                                        <option value="">— выберите —</option>
                                        {techProfile?.practices.map((pr) => <option key={pr.id} value={pr.label}>{pr.label}</option>)}
                                        {form.data.practice_types && !techProfile?.practices.some((pr) => pr.label === form.data.practice_types) && (
                                            <option value={form.data.practice_types}>{form.data.practice_types}</option>
                                        )}
                                    </select>
                                </div>
                            </>
                        )}
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setShowModal(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button type="submit" disabled={form.processing || (!editing && (!form.data.student_id || !form.data.participation_grade))}
                            className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                            {editing ? 'Сохранить' : 'Добавить'}
                        </button>
                    </div>
                </form>
            </Modal>

            <Modal show={showAutoStatus} onClose={() => setShowAutoStatus(false)} maxWidth="lg">
                <div className="space-y-4 p-6">
                    <h3 className="font-semibold text-gray-800">Авто-расстановка статусов</h3>
                    <p className="text-sm text-gray-600">
                        Порог призёра задан администратором. Призёр — балл ≥ порога, иначе участник.
                        Участия без балла пропускаются. Победителей, выставленных вручную, расчёт не трогает.
                    </p>
                    <table className="min-w-full text-sm">
                        <thead className="text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th className="py-1 pr-4">Класс уч.</th>
                                <th className="py-1">Призёр от</th>
                            </tr>
                        </thead>
                        <tbody>
                            {thresholdGrades.map((g) => {
                                const t = auto_status.thresholds[g] ?? {};
                                const max = Number(olympiad.max_scores?.[g]) || 0;
                                const pct = (v) => (max && v != null ? ` (${Math.round((Number(v) / max) * 100)}%)` : '');
                                return (
                                    <tr key={g} className="border-t border-gray-100">
                                        <td className="py-1 pr-4 text-gray-700">{g}</td>
                                        <td className="py-1 text-gray-700">{t.prize_from != null ? `${fmt(t.prize_from)}${pct(t.prize_from)}` : '—'}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setShowAutoStatus(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button type="button" onClick={applyAutoStatus}
                            className="rounded bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                            Применить
                        </button>
                    </div>
                </div>
            </Modal>

            <Modal show={showWipeModal} onClose={() => { setShowWipeModal(false); setWipeConfirmText(''); }} maxWidth="md">
                <div className="space-y-4 p-6">
                    <h3 className="font-semibold text-red-700">Удалить все результаты по этой олимпиаде</h3>
                    <p className="text-sm text-gray-600">
                        Будут удалены ВСЕ внесённые результаты вашей школы по этой олимпиаде — {participations.total}.
                        Действие необратимо и не зависит от текущих фильтров. Чтобы подтвердить, введите число{' '}
                        <b>{participations.total}</b>:
                    </p>
                    <input type="text" value={wipeConfirmText} onChange={(e) => setWipeConfirmText(e.target.value)}
                        placeholder={String(participations.total)}
                        className="w-full rounded border-gray-300 text-sm" />
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => { setShowWipeModal(false); setWipeConfirmText(''); }}
                            className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button type="button" disabled={wipeConfirmText.trim() !== String(participations.total)}
                            onClick={wipeAll}
                            className="rounded bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50">
                            Удалить всё ({participations.total})
                        </button>
                    </div>
                </div>
            </Modal>

            <Modal show={showTechRef} onClose={() => setShowTechRef(false)} maxWidth="2xl">
                <div className="space-y-4 p-6">
                    <h3 className="font-semibold text-gray-800">Справочник кодов — Технология</h3>
                    <p className="text-sm text-gray-600">
                        В шаблоне в столбце «Код вида практики» укажите код. Названия профиля и практики
                        подставятся автоматически при импорте.
                    </p>
                    <div className="max-h-[60vh] space-y-4 overflow-y-auto">
                        {tech_profiles.map((p) => (
                            <div key={p.id}>
                                <h4 className="mb-1 font-medium text-gray-800">{p.name}</h4>
                                <table className="min-w-full text-sm">
                                    <tbody className="divide-y divide-gray-100">
                                        {p.practices.map((pr) => (
                                            <tr key={pr.id}>
                                                <td className="w-16 py-1 pr-3 align-top font-medium text-indigo-600">
                                                    {pr.label.split(' ')[0]}
                                                </td>
                                                <td className="py-1 text-gray-700">
                                                    {pr.label.replace(/^\S+\s/, '')}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ))}
                    </div>
                    <div className="flex justify-end">
                        <button type="button" onClick={() => setShowTechRef(false)}
                            className="rounded bg-gray-200 px-4 py-2 text-sm hover:bg-gray-300">Закрыть</button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}

function SortHeader({ col, filters, onSort, children }) {
    const active = filters.sort === col;
    const arrow = active ? (filters.dir === 'asc' ? ' ▲' : ' ▼') : '';
    return (
        <th className="px-3 py-3">
            <button type="button" onClick={() => onSort(col)}
                className={`inline-flex items-center hover:text-gray-700 ${active ? 'text-indigo-600' : ''}`}>
                {children}{arrow}
            </button>
        </th>
    );
}
