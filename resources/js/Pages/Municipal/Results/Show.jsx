import CipherCell from '@/Components/CipherCell';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

const LEVEL_LABELS = { regional: 'Региональный', republican: 'Республиканский' };
const BASIS_LABELS = {
    school_stage: 'Призёр/победитель ШЭ',
    prev_municipal: 'Прошлогодний призёр МЭ',
    petition: 'По ходатайству',
};
const BLANK_EXT = {
    school_id: '', fio: '', birth_date: '', gender: '', snils: '', real_grade: '', origin_region: '', participation_grade: '',
};

export default function MunicipalResultsShow({ olympiad, participants, filters = {}, grade_options = [], pgrade_options = [], school_options = [], invitation_thresholds = {}, she_max_scores = {}, she_total_by_grade = {}, she_qualifying_scores_by_grade = {}, she_counts_by_school_grade = {}, students, schools = [] }) {
    const { errors, flash = {} } = usePage().props;
    const open = olympiad.compose_open;

    // Счётчики ШЭ по группе классов. total — все участники (для top-N); qual — баллы призёров (для порога).
    const groupTotal = (classes) => classes.reduce((s, g) => s + (she_total_by_grade[g] ?? 0), 0);
    const groupQualScores = (classes) => classes.flatMap((g) => she_qualifying_scores_by_grade[g] ?? []);
    const countAtLeast = (scores, min) => {
        const t = parseFloat(String(min ?? '').replace(',', '.'));
        return Number.isNaN(t) ? scores.length : scores.filter((v) => v >= t).length;
    };
    // «Из каждой школы по N»: выбрано = сумма по школам min(N, число в группе у школы); всего — все в группе.
    const perSchoolSelected = (classes, n) => {
        const N = parseInt(n, 10) || 0;
        let selected = 0;
        let total = 0;
        Object.values(she_counts_by_school_grade).forEach((byGrade) => {
            const c = classes.reduce((s, g) => s + (byGrade[g] ?? 0), 0);
            total += c;
            selected += Math.min(N, c);
        });
        return { selected, total };
    };

    // Поиск / фильтры / пагинация состава (состояние в URL).
    const [search, setSearch] = useState(filters.q ?? '');
    const go = (params) =>
        router.get(
            route('municipal.results.show', olympiad.id),
            {
                q: search || undefined,
                grade: filters.grade ?? undefined,
                pgrade: filters.pgrade ?? undefined,
                school: filters.school ?? undefined,
                ...params,
            },
            { preserveState: true, preserveScroll: true },
        );
    const submitSearch = (e) => {
        e.preventDefault();
        go({ page: undefined });
    };

    const [showModal, setShowModal] = useState(false);
    const form = useForm({ student_id: '', participation_grade: '' });

    // Внешний участник (из другого региона) — отдельная модалка.
    const [showExt, setShowExt] = useState(false);
    const extForm = useForm({ ...BLANK_EXT });
    const openExt = () => {
        extForm.setData({ ...BLANK_EXT });
        extForm.clearErrors();
        setShowExt(true);
    };
    const submitExt = (e) => {
        e.preventDefault();
        extForm.post(route('municipal.results.external', olympiad.id), {
            preserveScroll: true, onSuccess: () => setShowExt(false),
        });
    };
    const extGradeOptions = useMemo(() => {
        const rg = Number(extForm.data.real_grade);
        return rg ? olympiad.grades.filter((g) => g >= rg) : olympiad.grades;
    }, [extForm.data.real_grade, olympiad.grades]);
    const selectedStudent = students.find((s) => String(s.id) === String(form.data.student_id));
    const gradeOptions = useMemo(() => {
        if (!selectedStudent) return olympiad.grades;
        return olympiad.grades.filter((g) => g >= selectedStudent.real_grade);
    }, [selectedStudent, olympiad.grades]);

    const openAdd = () => {
        form.setData({ student_id: '', participation_grade: '' });
        form.clearErrors();
        setShowModal(true);
    };
    const submit = (e) => {
        e.preventDefault();
        form.post(route('municipal.results.store', olympiad.id), {
            preserveScroll: true, onSuccess: () => setShowModal(false),
        });
    };
    // Формирование по проходным баллам — модалка с группами классов и порогом на группу.
    const [showCompose, setShowCompose] = useState(false);
    const composeForm = useForm({ groups: [{ classes: [...olympiad.grades], min: '' }] });
    const openCompose = () => {
        composeForm.setData('groups', [{ classes: [...olympiad.grades], min: '' }]);
        composeForm.clearErrors();
        setShowCompose(true);
    };
    const setCGroups = (groups) => composeForm.setData('groups', groups);
    const toggleCClass = (i, g) => setCGroups(composeForm.data.groups.map((grp, idx) => {
        if (idx !== i) return grp;
        const has = grp.classes.includes(g);
        return { ...grp, classes: has ? grp.classes.filter((c) => c !== g) : [...grp.classes, g].sort((a, b) => a - b) };
    }));
    const setCMin = (i, min) => setCGroups(composeForm.data.groups.map((grp, idx) => (idx === i ? { ...grp, min } : grp)));
    const submitCompose = (e) => {
        e.preventDefault();
        // Группы разворачиваем в пороги по классам {класс: мин-балл}.
        const thresholds = {};
        composeForm.data.groups.forEach((grp) => {
            if (grp.min !== '' && grp.min != null) {
                grp.classes.forEach((g) => { thresholds[g] = grp.min; });
            }
        });
        router.post(route('municipal.results.compose', olympiad.id), { thresholds }, {
            preserveScroll: true, onSuccess: () => setShowCompose(false),
        });
    };

    // Первые N по группам классов участия (рейтинг по баллу ШЭ, по убыванию).
    const [showTopN, setShowTopN] = useState(false);
    const topNForm = useForm({ groups: [{ classes: [...olympiad.grades], n: 10 }] });
    const openTopN = () => {
        topNForm.setData('groups', [{ classes: [...olympiad.grades], n: 10 }]);
        topNForm.clearErrors();
        setShowTopN(true);
    };
    const submitTopN = (e) => {
        e.preventDefault();
        topNForm.post(route('municipal.results.compose-top-n', olympiad.id), {
            preserveScroll: true, onSuccess: () => setShowTopN(false),
        });
    };
    const setGroups = (groups) => topNForm.setData('groups', groups);
    const toggleGroupClass = (i, g) => setGroups(topNForm.data.groups.map((grp, idx) => {
        if (idx !== i) return grp;
        const has = grp.classes.includes(g);
        return { ...grp, classes: has ? grp.classes.filter((c) => c !== g) : [...grp.classes, g].sort((a, b) => a - b) };
    }));
    const setGroupN = (i, n) => setGroups(topNForm.data.groups.map((grp, idx) => (idx === i ? { ...grp, n } : grp)));

    // Из каждой школы по N лучших, по группам классов.
    const [showSchoolN, setShowSchoolN] = useState(false);
    const schoolNForm = useForm({ groups: [{ classes: [...olympiad.grades], n: 5 }] });
    const openSchoolN = () => {
        schoolNForm.setData('groups', [{ classes: [...olympiad.grades], n: 5 }]);
        schoolNForm.clearErrors();
        setShowSchoolN(true);
    };
    const submitSchoolN = (e) => {
        e.preventDefault();
        schoolNForm.post(route('municipal.results.compose-top-n-school', olympiad.id), {
            preserveScroll: true, onSuccess: () => setShowSchoolN(false),
        });
    };
    const setSGroups = (groups) => schoolNForm.setData('groups', groups);
    const toggleSClass = (i, g) => setSGroups(schoolNForm.data.groups.map((grp, idx) => {
        if (idx !== i) return grp;
        const has = grp.classes.includes(g);
        return { ...grp, classes: has ? grp.classes.filter((c) => c !== g) : [...grp.classes, g].sort((a, b) => a - b) };
    }));
    const setSN = (i, n) => setSGroups(schoolNForm.data.groups.map((grp, idx) => (idx === i ? { ...grp, n } : grp)));
    const removeRow = (p) => {
        if (confirm(`Убрать «${p.fio}» из состава МЭ?`)) {
            router.delete(route('municipal.results.destroy', p.id), { preserveScroll: true });
        }
    };

    // Массовое удаление: чекбоксы по строкам, «выбрать все по фильтру» и полная очистка состава.
    const [selected, setSelected] = useState(() => new Set());
    const [selectAllFiltered, setSelectAllFiltered] = useState(false);
    const [showWipeModal, setShowWipeModal] = useState(false);
    const [wipeConfirmText, setWipeConfirmText] = useState('');
    const clearSelection = () => { setSelected(new Set()); setSelectAllFiltered(false); };
    // Сброс выбора при смене страницы/фильтров — иначе можно случайно удалить не то, что видно на экране.
    useEffect(() => {
        clearSelection();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [participants.current_page, filters.q, filters.grade, filters.pgrade, filters.school]);

    const pageIds = participants.data.map((p) => p.id);
    const allOnPageSelected = pageIds.length > 0 && pageIds.every((id) => selected.has(id));
    const selectedCount = selectAllFiltered ? participants.total : selected.size;
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
    // Текущий вид (фильтры/страница) — чтобы сервер после удаления знал, куда вернуть
    // координатора (и подвинул страницу назад, если текущая опустела).
    const currentView = () => ({
        q: filters.q || undefined,
        grade: filters.grade ?? undefined,
        pgrade: filters.pgrade ?? undefined,
        school: filters.school ?? undefined,
        page: participants.current_page,
    });
    const bulkDeleteSelected = () => {
        if (selectedCount === 0) return;
        if (!confirm(`Убрать выбранных участников (${selectedCount}) из состава МЭ? Действие необратимо.`)) return;
        const payload = {
            ...currentView(),
            ...(selectAllFiltered ? { mode: 'filtered' } : { mode: 'selected', ids: [...selected] }),
        };
        router.post(route('municipal.results.bulk-destroy', olympiad.id), payload, {
            preserveScroll: true,
            onSuccess: clearSelection,
        });
    };
    const wipeAll = () => {
        if (wipeConfirmText.trim() !== String(participants.total)) return;
        router.post(route('municipal.results.bulk-destroy', olympiad.id), { ...currentView(), mode: 'all' }, {
            preserveScroll: true,
            onSuccess: () => { setShowWipeModal(false); setWipeConfirmText(''); clearSelection(); },
        });
    };

    // Присвоение шифра участнику (для обезличенной проверки председателем) — инлайн-ячейкой.
    const cipherEditable = olympiad.cipher_editable;

    // Массовое присвоение шифров из ключ-файла CSV «ID;шифр».
    const [importOpen, setImportOpen] = useState(false);
    const importForm = useForm({ file: null });
    const submitImport = (e) => {
        e.preventDefault();
        importForm.post(route('municipal.results.import-ciphers', olympiad.id), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => { setImportOpen(false); importForm.reset('file'); },
        });
    };

    // Загрузка сканов работ своего АТЕ ZIP-архивом (имена файлов = шифры).
    const scanInput = useRef(null);
    const onScansChosen = (e) => {
        const file = e.target.files?.[0];
        if (file) {
            router.post(route('municipal.results.scans', olympiad.id), { file }, {
                preserveScroll: true, forceFormData: true,
            });
        }
        e.target.value = '';
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Состав МЭ · {olympiad.subject}
                </h2>
            }
        >
            <Head title={`Состав МЭ · ${olympiad.subject}`} />

            <div className="py-8">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between">
                        <Link href={route('municipal.results.index')} className="text-sm text-gray-500 hover:underline">
                            ← К списку олимпиад
                        </Link>
                        {participants.total > 0 && (
                            <a href={route('municipal.results.invited', olympiad.id)}
                                className="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                                ↓ Скачать список приглашённых (XLSX)
                            </a>
                        )}
                    </div>

                    {/* Вкладки по олимпиаде */}
                    <div className="flex gap-1 border-b border-gray-200">
                        <Link href={route('municipal.results.show', olympiad.id)}
                            className="-mb-px border-b-2 border-indigo-600 px-4 py-2 text-sm font-medium text-indigo-700">
                            Состав участников
                        </Link>
                        <Link href={route('municipal.results.entry', olympiad.id)}
                            className="-mb-px border-b-2 border-transparent px-4 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">
                            Ввод результатов
                        </Link>
                    </div>

                    {errors?.compose && <div className="rounded bg-amber-50 p-3 text-sm text-amber-700">{errors.compose}</div>}
                    {errors?.file && <div className="rounded bg-amber-50 p-3 text-sm text-amber-700">{errors.file}</div>}

                    {flash.success && (
                        <div className="rounded-lg bg-green-50 p-3 text-sm text-green-800 shadow-sm">
                            {flash.success}
                            {flash.import_skipped?.length > 0 && (
                                <details className="mt-2">
                                    <summary className="cursor-pointer text-green-700">Показать пропущенные строки ({flash.import_skipped.length})</summary>
                                    <ul className="mt-1 list-disc space-y-0.5 pl-5 text-xs text-red-700">
                                        {flash.import_skipped.map((s, i) => <li key={i}>{s}</li>)}
                                    </ul>
                                </details>
                            )}
                        </div>
                    )}

                    <div className="rounded-lg bg-white p-4 text-sm text-gray-600 shadow">
                        <b>{olympiad.subject}</b> · {LEVEL_LABELS[olympiad.level] ?? olympiad.level} уровень ·
                        классы {olympiad.grades.join(', ')} ·{' '}
                        {open ? <span className="text-green-700">состав открыт</span> : <span className="text-red-600">состав закрыт</span>}
                    </div>

                    {open && (
                        <div className="space-y-4">
                            <div className="rounded-lg bg-white p-4 shadow">
                                <h3 className="mb-2 text-sm font-semibold text-gray-700">Формирование списка приглашённых</h3>
                                <div className="flex flex-wrap gap-3">
                                    <Link href={route('municipal.results.school-stage', olympiad.id)}
                                        className="rounded border border-emerald-300 px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50">
                                        Из протокола ШЭ →
                                    </Link>
                                    <button onClick={openCompose}
                                        className="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                                        По проходным баллам
                                    </button>
                                    <button onClick={openTopN}
                                        className="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                                        Первые N по группам
                                    </button>
                                    <button onClick={openSchoolN}
                                        className="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                                        Из каждой школы по N
                                    </button>
                                </div>
                                <p className="mt-2 text-xs text-gray-400">
                                    «Из протокола ШЭ» — выгрузить результаты школьного этапа и импортировать отмеченных вручную.
                                    «По проходным баллам» — добавить призёров/победителей ШЭ с баллом ≥ порога (+ прошлогодний МЭ).
                                    «Первые N по группам» — N лучших по баллу в каждой группе классов. «Из каждой школы по N» — N лучших
                                    из каждой школы в каждой группе. Уже внесённые не дублируются.
                                </p>
                            </div>

                            <div className="rounded-lg bg-white p-4 shadow">
                                <h3 className="mb-2 text-sm font-semibold text-gray-700">Добавить вручную</h3>
                                <div className="flex flex-wrap gap-3">
                                    <button onClick={openAdd}
                                        className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                        + По ходатайству (ученик АТЕ)
                                    </button>
                                    <button onClick={openExt}
                                        className="rounded border border-indigo-300 px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50">
                                        + Из другого региона
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}

                    {participants.total > 0 && (
                        <h3 className="text-sm font-semibold text-gray-700">Подготовка к проверке (шифры и сканы)</h3>
                    )}

                    {cipherEditable && participants.total > 0 && (
                        <div className="flex flex-wrap items-center gap-3 rounded-lg bg-white p-4 shadow">
                            <a href={route('municipal.results.cipher-template', olympiad.id)}
                                className="rounded border border-purple-300 px-4 py-2 text-sm font-medium text-purple-700 hover:bg-purple-50">
                                ↓ Шаблон шифров (XLSX)
                            </a>
                            <button onClick={() => { importForm.clearErrors(); setImportOpen(true); }}
                                className="rounded bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700">
                                Загрузить шифры
                            </button>
                            <p className="basis-full text-xs text-gray-400">
                                Скачайте шаблон (XLSX), проставьте шифры в колонке «Шифр» и загрузите этот же файл.
                                Шапку (название и код олимпиады) и колонку ID не меняйте. Шифры присваиваются участникам
                                вашего АТЕ; занятые и дубли — пропускаются.
                            </p>
                        </div>
                    )}

                    {participants.total > 0 && (
                        <div className="flex flex-wrap items-center gap-3 rounded-lg bg-white p-4 shadow">
                            <input type="file" accept=".zip" ref={scanInput} onChange={onScansChosen} className="hidden" />
                            <button onClick={() => scanInput.current?.click()}
                                className="rounded bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700">
                                Загрузить сканы работ (ZIP)
                            </button>
                            <a href={route('help.show', 'scans')} target="_blank" rel="noopener noreferrer"
                                className="text-sm text-indigo-600 hover:underline">
                                Как загрузить сканы?
                            </a>
                            <p className="basis-full text-xs text-gray-400">
                                Архив со сканами работ вашего АТЕ для онлайн-показа участникам. Имя каждого файла — шифр
                                участника (напр. «A-014.pdf»), форматы pdf/jpg/png. Сопоставляются по шифру; чужие и
                                несопоставленные — пропускаются.
                            </p>
                        </div>
                    )}

                    <div className="overflow-x-auto rounded-lg bg-white shadow">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-3">
                            <h3 className="font-semibold text-gray-800">Участники МЭ ({participants.total})</h3>
                            <div className="flex flex-wrap items-center gap-2">
                                <select value={filters.grade ?? ''} onChange={(e) => go({ grade: e.target.value || undefined, page: undefined })}
                                    className="rounded border-gray-300 text-sm">
                                    <option value="">Класс: все</option>
                                    {grade_options.map((g) => <option key={g} value={g}>{g} класс</option>)}
                                </select>
                                <select value={filters.pgrade ?? ''} onChange={(e) => go({ pgrade: e.target.value || undefined, page: undefined })}
                                    className="rounded border-gray-300 text-sm">
                                    <option value="">Класс уч.: все</option>
                                    {pgrade_options.map((g) => <option key={g} value={g}>{g} класс</option>)}
                                </select>
                                <select value={filters.school ?? ''} onChange={(e) => go({ school: e.target.value || undefined, page: undefined })}
                                    className="max-w-[180px] rounded border-gray-300 text-sm">
                                    <option value="">Школа: все</option>
                                    {school_options.map((s) => <option key={s.id} value={s.id}>{s.short_name}</option>)}
                                </select>
                                <form onSubmit={submitSearch} className="flex gap-2">
                                    <input type="text" value={search} onChange={(e) => setSearch(e.target.value)}
                                        placeholder="Поиск по ФИО" className="w-44 rounded border-gray-300 text-sm" />
                                    <button type="submit" className="rounded bg-gray-200 px-3 py-2 text-sm hover:bg-gray-300">Найти</button>
                                    {filters.q && (
                                        <button type="button" onClick={() => { setSearch(''); go({ q: undefined, page: undefined }); }}
                                            className="rounded px-2 py-2 text-sm text-gray-500 hover:underline">Сброс</button>
                                    )}
                                </form>
                                {open && participants.total > 0 && (
                                    <button onClick={() => setShowWipeModal(true)}
                                        className="rounded border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                                        Удалить весь состав
                                    </button>
                                )}
                            </div>
                        </div>
                        {open && selectedCount > 0 && (
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b bg-indigo-50 px-6 py-3 text-sm">
                                <span className="text-indigo-800">
                                    {selectAllFiltered
                                        ? `Выбраны все участники по текущему фильтру: ${selectedCount}.`
                                        : (
                                            <>
                                                Выбрано: {selectedCount}.{' '}
                                                {allOnPageSelected && participants.total > pageIds.length && (
                                                    <button type="button" onClick={() => setSelectAllFiltered(true)}
                                                        className="font-medium text-indigo-700 hover:underline">
                                                        Выбрать все {participants.total} по текущему фильтру
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
                                        Убрать выбранных ({selectedCount})
                                    </button>
                                </div>
                            </div>
                        )}
                        {participants.data.length === 0 ? (
                            <p className="px-6 py-8 text-center text-sm text-gray-400">
                                {filters.q || filters.grade || filters.pgrade || filters.school ? 'Ничего не найдено по фильтрам.' : 'Состав пока не сформирован.'}
                            </p>
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
                                        <th className="px-3 py-3">Ученик</th>
                                        <th className="px-3 py-3">Школа</th>
                                        <th className="px-3 py-3">Кл.</th>
                                        <th className="px-3 py-3">Кл. уч.</th>
                                        <th className="px-3 py-3">Основание</th>
                                        <th className="px-3 py-3">Шифр</th>
                                        {open && <th className="px-3 py-3"></th>}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {participants.data.map((p) => (
                                        <tr key={p.id} className="hover:bg-gray-50">
                                            {open && (
                                                <td className="px-3 py-2">
                                                    <input type="checkbox" checked={selectAllFiltered || selected.has(p.id)}
                                                        onChange={() => toggleRow(p.id)} />
                                                </td>
                                            )}
                                            <td className="px-3 py-2 font-medium text-gray-800">
                                                {p.fio}
                                                {p.from_other_region && (
                                                    <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700">
                                                        из др. региона{p.origin_region ? `: ${p.origin_region}` : ''}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-gray-500">{p.school ?? '—'}</td>
                                            <td className="px-3 py-2 text-gray-600">{p.real_grade}</td>
                                            <td className="px-3 py-2 text-gray-600">{p.participation_grade}</td>
                                            <td className="px-3 py-2 text-xs text-gray-500">{BASIS_LABELS[p.inclusion_basis] ?? '—'}</td>
                                            <td className="px-3 py-2">
                                                <CipherCell value={p.cipher} editable={cipherEditable}
                                                    url={route('municipal.results.cipher', p.id)} />
                                            </td>
                                            {open && (
                                                <td className="px-3 py-2 whitespace-nowrap text-right">
                                                    <button onClick={() => removeRow(p)} className="text-red-600 hover:underline">Убрать</button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        {participants.links?.length > 3 && (
                            <div className="flex flex-wrap gap-1 border-t px-6 py-3">
                                {participants.links.map((link, i) => (
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

            <Modal show={showWipeModal} onClose={() => { setShowWipeModal(false); setWipeConfirmText(''); }} maxWidth="md">
                <div className="space-y-4 p-6">
                    <h3 className="font-semibold text-red-700">Удалить весь состав МЭ по этой олимпиаде</h3>
                    <p className="text-sm text-gray-600">
                        Будут убраны ВСЕ участники состава МЭ вашего АТЕ по этой олимпиаде — {participants.total}.
                        Действие необратимо и не зависит от текущих фильтров. Чтобы подтвердить, введите число{' '}
                        <b>{participants.total}</b>:
                    </p>
                    <input type="text" value={wipeConfirmText} onChange={(e) => setWipeConfirmText(e.target.value)}
                        placeholder={String(participants.total)}
                        className="w-full rounded border-gray-300 text-sm" />
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => { setShowWipeModal(false); setWipeConfirmText(''); }}
                            className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button type="button" disabled={wipeConfirmText.trim() !== String(participants.total)}
                            onClick={wipeAll}
                            className="rounded bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50">
                            Удалить всё ({participants.total})
                        </button>
                    </div>
                </div>
            </Modal>

            <Modal show={importOpen} onClose={() => setImportOpen(false)} maxWidth="lg">
                <form onSubmit={submitImport} className="space-y-4 p-6">
                    <h3 className="font-semibold text-gray-800">Загрузка шифров</h3>
                    <p className="text-xs text-gray-500">
                        Файл <b>XLSX/ODS</b> (или CSV) с колонками <b>ID</b> и <b>Шифр</b> — обычно это заполненный шаблон.
                        Шифр привязывается к участнику вашего АТЕ по ID. Код олимпиады в шапке сверяется. Дубли ID/шифров
                        в файле, участия не из вашего состава и уже занятые шифры будут пропущены — список покажем после загрузки.
                    </p>
                    <div>
                        <input type="file" accept=".xlsx,.ods,.csv,.txt"
                            onChange={(e) => importForm.setData('file', e.target.files[0] ?? null)}
                            className="block w-full text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm" />
                        {importForm.errors.file && <p className="mt-1 text-xs text-red-600">{importForm.errors.file}</p>}
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setImportOpen(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button type="submit" disabled={importForm.processing || !importForm.data.file}
                            className="rounded bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 disabled:opacity-50">Загрузить</button>
                    </div>
                </form>
            </Modal>

            <Modal show={showModal} onClose={() => setShowModal(false)} maxWidth="2xl">
                <form onSubmit={submit} className="space-y-4 p-6">
                    <h3 className="font-semibold text-gray-800">Добавить участника МЭ</h3>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label className="block text-xs text-gray-500">Ученик</label>
                            <select value={form.data.student_id}
                                onChange={(e) => form.setData({ ...form.data, student_id: e.target.value, participation_grade: '' })}
                                className="w-full rounded border-gray-300 text-sm">
                                <option value="">— выберите —</option>
                                {students.map((s) => (
                                    <option key={s.id} value={s.id}>{s.fio} · {s.school} ({s.real_grade} кл.)</option>
                                ))}
                            </select>
                            {form.errors.student_id && <p className="text-xs text-red-600">{form.errors.student_id}</p>}
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">Класс участия</label>
                            <select value={form.data.participation_grade}
                                onChange={(e) => form.setData('participation_grade', e.target.value)}
                                className="w-full rounded border-gray-300 text-sm">
                                <option value="">—</option>
                                {gradeOptions.map((g) => <option key={g} value={g}>{g}</option>)}
                            </select>
                            {form.errors.participation_grade && <p className="text-xs text-red-600">{form.errors.participation_grade}</p>}
                        </div>
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setShowModal(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button type="submit" disabled={form.processing || !form.data.student_id || !form.data.participation_grade}
                            className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                            Добавить
                        </button>
                    </div>
                </form>
            </Modal>

            <Modal show={showCompose} onClose={() => setShowCompose(false)} maxWidth="2xl">
                <form onSubmit={submitCompose} className="space-y-4 p-6">
                    <h3 className="font-semibold text-gray-800">По проходным баллам (по группам)</h3>
                    <p className="text-xs text-gray-500">
                        В каждой группе классов задайте проходной балл — приглашаются <b>призёры/победители ШЭ с баллом ≥ порога</b>.
                        Рядом видно, сколько проходит из всех в группе. Прошлогодние призёры МЭ добавляются <b>всегда</b>. Уже внесённые не дублируются.
                    </p>
                    <div className="space-y-3">
                        {composeForm.data.groups.map((grp, i) => {
                            const scores = groupQualScores(grp.classes);
                            return (
                                <div key={i} className="rounded border border-gray-200 p-3">
                                    <div className="mb-2 flex items-center justify-between">
                                        <span className="text-xs font-medium text-gray-600">
                                            Группа {i + 1}
                                            <span className="ml-2 font-normal text-emerald-700">
                                                проходит {countAtLeast(scores, grp.min)} из {scores.length}
                                            </span>
                                        </span>
                                        {composeForm.data.groups.length > 1 && (
                                            <button type="button" onClick={() => setCGroups(composeForm.data.groups.filter((_, idx) => idx !== i))}
                                                className="text-xs text-red-600 hover:underline">удалить</button>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-xs text-gray-500">Классы:</span>
                                        {olympiad.grades.map((g) => (
                                            <label key={g} className={`cursor-pointer rounded border px-2 py-1 text-xs ${
                                                grp.classes.includes(g) ? 'border-emerald-400 bg-emerald-50 text-emerald-700' : 'border-gray-300 text-gray-500'
                                            }`}>
                                                <input type="checkbox" className="hidden" checked={grp.classes.includes(g)} onChange={() => toggleCClass(i, g)} />
                                                {g}
                                            </label>
                                        ))}
                                        <span className="ml-3 text-xs text-gray-500">Проходной балл:</span>
                                        <input type="number" step="0.01" min="0" value={grp.min}
                                            onChange={(e) => setCMin(i, e.target.value)}
                                            placeholder="напр. 20" className="w-24 rounded border-gray-300 text-sm" />
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                    <button type="button" onClick={() => setCGroups([...composeForm.data.groups, { classes: [], min: '' }])}
                        className="text-sm text-indigo-600 hover:underline">+ Добавить группу</button>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setShowCompose(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button type="submit" disabled={composeForm.processing}
                            className="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50">
                            Сформировать
                        </button>
                    </div>
                </form>
            </Modal>

            <Modal show={showTopN} onClose={() => setShowTopN(false)} maxWidth="2xl">
                <form onSubmit={submitTopN} className="space-y-4 p-6">
                    <h3 className="font-semibold text-gray-800">Первые N по группам классов</h3>
                    <p className="text-xs text-gray-500">
                        В каждой группе классов участия приглашаются <b>N участников с наибольшим баллом ШЭ</b> (рейтинг по убыванию).
                        Задания часто общие для группы классов (напр. 7–8, 9–11) — объедините такие классы в одну группу. Уже
                        внесённые не дублируются.
                    </p>
                    {topNForm.errors.groups && <p className="text-xs text-red-600">{topNForm.errors.groups}</p>}
                    <div className="space-y-3">
                        {topNForm.data.groups.map((grp, i) => (
                            <div key={i} className="rounded border border-gray-200 p-3">
                                <div className="mb-2 flex items-center justify-between">
                                    <span className="text-xs font-medium text-gray-600">
                                        Группа {i + 1}
                                        <span className="ml-2 font-normal text-gray-400">всего в группе: {groupTotal(grp.classes)}</span>
                                    </span>
                                    {topNForm.data.groups.length > 1 && (
                                        <button type="button" onClick={() => setGroups(topNForm.data.groups.filter((_, idx) => idx !== i))}
                                            className="text-xs text-red-600 hover:underline">удалить</button>
                                    )}
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-xs text-gray-500">Классы:</span>
                                    {olympiad.grades.map((g) => (
                                        <label key={g} className={`cursor-pointer rounded border px-2 py-1 text-xs ${
                                            grp.classes.includes(g) ? 'border-emerald-400 bg-emerald-50 text-emerald-700' : 'border-gray-300 text-gray-500'
                                        }`}>
                                            <input type="checkbox" className="hidden" checked={grp.classes.includes(g)} onChange={() => toggleGroupClass(i, g)} />
                                            {g}
                                        </label>
                                    ))}
                                    <span className="ml-3 text-xs text-gray-500">N:</span>
                                    <input type="number" min="1" value={grp.n}
                                        onChange={(e) => setGroupN(i, e.target.value)}
                                        className="w-20 rounded border-gray-300 text-sm" />
                                </div>
                            </div>
                        ))}
                    </div>
                    <button type="button" onClick={() => setGroups([...topNForm.data.groups, { classes: [], n: 10 }])}
                        className="text-sm text-indigo-600 hover:underline">+ Добавить группу</button>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setShowTopN(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button type="submit" disabled={topNForm.processing}
                            className="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50">
                            Пригласить
                        </button>
                    </div>
                </form>
            </Modal>

            <Modal show={showSchoolN} onClose={() => setShowSchoolN(false)} maxWidth="2xl">
                <form onSubmit={submitSchoolN} className="space-y-4 p-6">
                    <h3 className="font-semibold text-gray-800">Из каждой школы по N (по группам)</h3>
                    <p className="text-xs text-gray-500">
                        В каждой группе классов из <b>каждой школы</b> вашего АТЕ приглашаются N участников с наибольшим баллом ШЭ.
                        Рядом видно, сколько всего будет приглашено из общего числа в группе. Уже внесённые не дублируются.
                    </p>
                    {schoolNForm.errors.groups && <p className="text-xs text-red-600">{schoolNForm.errors.groups}</p>}
                    <div className="space-y-3">
                        {schoolNForm.data.groups.map((grp, i) => {
                            const { selected, total } = perSchoolSelected(grp.classes, grp.n);
                            return (
                                <div key={i} className="rounded border border-gray-200 p-3">
                                    <div className="mb-2 flex items-center justify-between">
                                        <span className="text-xs font-medium text-gray-600">
                                            Группа {i + 1}
                                            <span className="ml-2 font-normal text-emerald-700">будет приглашено {selected} из {total}</span>
                                        </span>
                                        {schoolNForm.data.groups.length > 1 && (
                                            <button type="button" onClick={() => setSGroups(schoolNForm.data.groups.filter((_, idx) => idx !== i))}
                                                className="text-xs text-red-600 hover:underline">удалить</button>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-xs text-gray-500">Классы:</span>
                                        {olympiad.grades.map((g) => (
                                            <label key={g} className={`cursor-pointer rounded border px-2 py-1 text-xs ${
                                                grp.classes.includes(g) ? 'border-emerald-400 bg-emerald-50 text-emerald-700' : 'border-gray-300 text-gray-500'
                                            }`}>
                                                <input type="checkbox" className="hidden" checked={grp.classes.includes(g)} onChange={() => toggleSClass(i, g)} />
                                                {g}
                                            </label>
                                        ))}
                                        <span className="ml-3 text-xs text-gray-500">N из школы:</span>
                                        <input type="number" min="1" value={grp.n}
                                            onChange={(e) => setSN(i, e.target.value)}
                                            className="w-20 rounded border-gray-300 text-sm" />
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                    <button type="button" onClick={() => setSGroups([...schoolNForm.data.groups, { classes: [], n: 5 }])}
                        className="text-sm text-indigo-600 hover:underline">+ Добавить группу</button>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setShowSchoolN(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button type="submit" disabled={schoolNForm.processing}
                            className="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50">
                            Пригласить
                        </button>
                    </div>
                </form>
            </Modal>

            <Modal show={showExt} onClose={() => setShowExt(false)} maxWidth="2xl">
                <form onSubmit={submitExt} className="space-y-4 p-6">
                    <h3 className="font-semibold text-gray-800">Участник из другого региона</h3>
                    <p className="text-xs text-gray-500">
                        Создаётся карточка участника с пометкой «из другого региона», привязанная к школе-ходатаю вашего АТЕ.
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label className="block text-xs text-gray-500">Школа-ходатай</label>
                            <select value={extForm.data.school_id} onChange={(e) => extForm.setData('school_id', e.target.value)}
                                className="w-full rounded border-gray-300 text-sm">
                                <option value="">— выберите школу —</option>
                                {schools.map((s) => <option key={s.id} value={s.id}>{s.short_name}</option>)}
                            </select>
                            {extForm.errors.school_id && <p className="text-xs text-red-600">{extForm.errors.school_id}</p>}
                        </div>
                        <div className="sm:col-span-2">
                            <label className="block text-xs text-gray-500">ФИО</label>
                            <input value={extForm.data.fio} onChange={(e) => extForm.setData('fio', e.target.value)}
                                className="w-full rounded border-gray-300 text-sm" />
                            {extForm.errors.fio && <p className="text-xs text-red-600">{extForm.errors.fio}</p>}
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">Дата рождения</label>
                            <input type="date" value={extForm.data.birth_date} onChange={(e) => extForm.setData('birth_date', e.target.value)}
                                className="w-full rounded border-gray-300 text-sm" />
                            {extForm.errors.birth_date && <p className="text-xs text-red-600">{extForm.errors.birth_date}</p>}
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">Пол</label>
                            <select value={extForm.data.gender} onChange={(e) => extForm.setData('gender', e.target.value)}
                                className="w-full rounded border-gray-300 text-sm">
                                <option value="">—</option>
                                <option value="male">мужской</option>
                                <option value="female">женский</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">СНИЛС (необязательно)</label>
                            <input value={extForm.data.snils} onChange={(e) => extForm.setData('snils', e.target.value)}
                                className="w-full rounded border-gray-300 text-sm" />
                            {extForm.errors.snils && <p className="text-xs text-red-600">{extForm.errors.snils}</p>}
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">Регион/откуда</label>
                            <input value={extForm.data.origin_region} onChange={(e) => extForm.setData('origin_region', e.target.value)}
                                placeholder="напр. Республика Башкортостан" className="w-full rounded border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">Класс обучения</label>
                            <select value={extForm.data.real_grade}
                                onChange={(e) => extForm.setData({ ...extForm.data, real_grade: e.target.value, participation_grade: '' })}
                                className="w-full rounded border-gray-300 text-sm">
                                <option value="">—</option>
                                {Array.from({ length: 11 }, (_, i) => i + 1).map((g) => <option key={g} value={g}>{g}</option>)}
                            </select>
                            {extForm.errors.real_grade && <p className="text-xs text-red-600">{extForm.errors.real_grade}</p>}
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500">Класс участия</label>
                            <select value={extForm.data.participation_grade} onChange={(e) => extForm.setData('participation_grade', e.target.value)}
                                className="w-full rounded border-gray-300 text-sm">
                                <option value="">—</option>
                                {extGradeOptions.map((g) => <option key={g} value={g}>{g}</option>)}
                            </select>
                            {extForm.errors.participation_grade && <p className="text-xs text-red-600">{extForm.errors.participation_grade}</p>}
                        </div>
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setShowExt(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button type="submit" disabled={extForm.processing}
                            className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                            Добавить
                        </button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
