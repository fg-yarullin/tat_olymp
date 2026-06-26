import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';

export default function GuestLogin() {
    const { data, setData, post, processing, errors } = useForm({
        fio: '',
        birth_date: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('guest.login.attempt'));
    };

    return (
        <GuestLayout>
            <Head title="Онлайн-показ работ" />

            <h1 className="mb-2 text-lg font-semibold text-gray-800">
                Просмотр олимпиадной работы
            </h1>
            <p className="mb-4 text-sm text-gray-600">
                Введите ФИО и дату рождения участника.
            </p>

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="fio" value="ФИО участника" />
                    <TextInput
                        id="fio"
                        name="fio"
                        value={data.fio}
                        className="mt-1 block w-full"
                        isFocused={true}
                        onChange={(e) => setData('fio', e.target.value)}
                    />
                    <InputError message={errors.fio} className="mt-2" />
                </div>

                <div className="mt-4">
                    <InputLabel htmlFor="birth_date" value="Дата рождения" />
                    <TextInput
                        id="birth_date"
                        type="date"
                        name="birth_date"
                        value={data.birth_date}
                        className="mt-1 block w-full"
                        onChange={(e) => setData('birth_date', e.target.value)}
                    />
                    <InputError message={errors.birth_date} className="mt-2" />
                </div>

                <div className="mt-6 flex items-center justify-end">
                    <PrimaryButton disabled={processing}>
                        Войти
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
