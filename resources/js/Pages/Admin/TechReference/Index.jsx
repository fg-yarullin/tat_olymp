import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const BLANK_PROFILE = { name: '', position: 0, is_active: true };
const BLANK_PRACTICE = { code: '', name: '', position: 0, is_active: true };

export default function TechReferenceIndex({ profiles }) {
    const { errors } = usePage().props;

    const [profileModal, setProfileModal] = useState(false);
    const [editProfileId, setEditProfileId] = useState(null);
    const profileForm = useForm({ ...BLANK_PROFILE });

    const [practiceModal, setPracticeModal] = useState(false);
    const [practiceProfileId, setPracticeProfileId] = useState(null);
    const [editPracticeId, setEditPracticeId] = useState(null);
    const practiceForm = useForm({ ...BLANK_PRACTICE });

    const openCreateProfile = () => {
        setEditProfileId(null);
        profileForm.setData({ ...BLANK_PROFILE, position: profiles.length + 1 });
        profileForm.clearErrors();
        setProfileModal(true);
    };
    const openEditProfile = (p) => {
        setEditProfileId(p.id);
        profileForm.setData({ name: p.name, position: p.position, is_active: p.is_active });
        profileForm.clearErrors();
        setProfileModal(true);
    };
    const submitProfile = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setProfileModal(false) };
        if (editProfileId) {
            profileForm.put(route('admin.tech.profiles.update', editProfileId), opts);
        } else {
            profileForm.post(route('admin.tech.profiles.store'), opts);
        }
    };
    const removeProfile = (p) => {
        if (confirm(`Удалить направление «${p.name}» со всеми видами практик?`)) {
            router.delete(route('admin.tech.profiles.destroy', p.id), { preserveScroll: true });
        }
    };

    const openCreatePractice = (profileId) => {
        setPracticeProfileId(profileId);
        setEditPracticeId(null);
        const profile = profiles.find((p) => p.id === profileId);
        practiceForm.setData({ ...BLANK_PRACTICE, position: (profile?.practices.length ?? 0) + 1 });
        practiceForm.clearErrors();
        setPracticeModal(true);
    };
    const openEditPractice = (profileId, pr) => {
        setPracticeProfileId(profileId);
        setEditPracticeId(pr.id);
        practiceForm.setData({ code: pr.code ?? '', name: pr.name, position: pr.position, is_active: pr.is_active });
        practiceForm.clearErrors();
        setPracticeModal(true);
    };
    const submitPractice = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setPracticeModal(false) };
        if (editPracticeId) {
            practiceForm.put(route('admin.tech.practices.update', editPracticeId), opts);
        } else {
            practiceForm.post(route('admin.tech.practices.store', practiceProfileId), opts);
        }
    };
    const removePractice = (pr) => {
        if (confirm(`Удалить вид практики «${pr.name}»?`)) {
            router.delete(route('admin.tech.practices.destroy', pr.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Технология: справочник</h2>}
        >
            <Head title="Технология: справочник" />

            <div className="py-8">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {errors?.profile && <div className="rounded bg-red-50 p-3 text-sm text-red-700">{errors.profile}</div>}

                    <div className="flex items-center justify-between">
                        <p className="text-sm text-gray-500">Направления и виды практик для выбора при вводе результатов по технологии.</p>
                        <button onClick={openCreateProfile}
                            className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            + Новое направление
                        </button>
                    </div>

                    {profiles.length === 0 ? (
                        <div className="rounded-lg bg-white p-10 text-center text-gray-400 shadow">Справочник пуст.</div>
                    ) : (
                        profiles.map((p) => (
                            <div key={p.id} className="overflow-hidden rounded-lg bg-white shadow">
                                <div className="flex items-center justify-between border-b px-5 py-3">
                                    <h3 className="font-semibold text-gray-800">
                                        {p.name}
                                        {!p.is_active && <span className="ml-2 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-500">скрыто</span>}
                                    </h3>
                                    <div className="whitespace-nowrap text-sm">
                                        <button onClick={() => openCreatePractice(p.id)} className="mr-3 text-indigo-600 hover:underline">+ Вид практики</button>
                                        <button onClick={() => openEditProfile(p)} className="mr-3 text-gray-600 hover:underline">Изменить</button>
                                        <button onClick={() => removeProfile(p)} className="text-red-600 hover:underline">Удалить</button>
                                    </div>
                                </div>
                                {p.practices.length === 0 ? (
                                    <p className="px-5 py-4 text-sm text-gray-400">Виды практик не заданы.</p>
                                ) : (
                                    <table className="min-w-full text-sm">
                                        <tbody className="divide-y divide-gray-100">
                                            {p.practices.map((pr) => (
                                                <tr key={pr.id} className="hover:bg-gray-50">
                                                    <td className="w-16 px-5 py-2 text-gray-500">{pr.code}</td>
                                                    <td className="px-3 py-2 text-gray-800">
                                                        {pr.name}
                                                        {!pr.is_active && <span className="ml-2 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-500">скрыто</span>}
                                                    </td>
                                                    <td className="px-5 py-2 whitespace-nowrap text-right">
                                                        <button onClick={() => openEditPractice(p.id, pr)} className="mr-3 text-indigo-600 hover:underline">Изменить</button>
                                                        <button onClick={() => removePractice(pr)} className="text-red-600 hover:underline">Удалить</button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </div>
                        ))
                    )}
                </div>
            </div>

            {/* Модалка направления */}
            <Modal show={profileModal} onClose={() => setProfileModal(false)} maxWidth="lg">
                <form onSubmit={submitProfile} className="space-y-4 p-6">
                    <h3 className="font-semibold text-gray-800">{editProfileId ? 'Редактирование направления' : 'Новое направление'}</h3>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600">Название</label>
                        <input value={profileForm.data.name} onChange={(e) => profileForm.setData('name', e.target.value)}
                            className="w-full rounded border-gray-300 text-sm" />
                        {profileForm.errors.name && <p className="mt-1 text-xs text-red-600">{profileForm.errors.name}</p>}
                    </div>
                    <div className="flex items-center gap-4">
                        <div className="w-28">
                            <label className="mb-1 block text-xs font-medium text-gray-600">Порядок</label>
                            <input type="number" min="0" value={profileForm.data.position}
                                onChange={(e) => profileForm.setData('position', e.target.value)}
                                className="w-full rounded border-gray-300 text-sm" />
                        </div>
                        <label className="mt-5 flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" checked={profileForm.data.is_active}
                                onChange={(e) => profileForm.setData('is_active', e.target.checked)} />
                            Активно
                        </label>
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setProfileModal(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button type="submit" disabled={profileForm.processing}
                            className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </Modal>

            {/* Модалка вида практики */}
            <Modal show={practiceModal} onClose={() => setPracticeModal(false)} maxWidth="lg">
                <form onSubmit={submitPractice} className="space-y-4 p-6">
                    <h3 className="font-semibold text-gray-800">{editPracticeId ? 'Редактирование вида практики' : 'Новый вид практики'}</h3>
                    <div className="flex gap-3">
                        <div className="w-24">
                            <label className="mb-1 block text-xs font-medium text-gray-600">Код</label>
                            <input value={practiceForm.data.code} onChange={(e) => practiceForm.setData('code', e.target.value)}
                                placeholder="1.1" className="w-full rounded border-gray-300 text-sm" />
                            {practiceForm.errors.code && <p className="mt-1 text-xs text-red-600">{practiceForm.errors.code}</p>}
                        </div>
                        <div className="w-24">
                            <label className="mb-1 block text-xs font-medium text-gray-600">Порядок</label>
                            <input type="number" min="0" value={practiceForm.data.position}
                                onChange={(e) => practiceForm.setData('position', e.target.value)}
                                className="w-full rounded border-gray-300 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600">Название</label>
                        <input value={practiceForm.data.name} onChange={(e) => practiceForm.setData('name', e.target.value)}
                            className="w-full rounded border-gray-300 text-sm" />
                        {practiceForm.errors.name && <p className="mt-1 text-xs text-red-600">{practiceForm.errors.name}</p>}
                    </div>
                    <label className="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" checked={practiceForm.data.is_active}
                            onChange={(e) => practiceForm.setData('is_active', e.target.checked)} />
                        Активно
                    </label>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setPracticeModal(false)} className="rounded bg-gray-200 px-4 py-2 text-sm">Отмена</button>
                        <button type="submit" disabled={practiceForm.processing}
                            className="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
