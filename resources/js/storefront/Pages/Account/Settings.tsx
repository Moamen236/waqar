import { Head, useForm } from '@inertiajs/react';
import AccountNav from '../../Components/AccountNav';
import Breadcrumb from '../../Components/Breadcrumb';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import { useTranslation } from '../../lib/useTranslation';

/**
 * my-account.html's Setting tab. The template's Gender, Day-of-Birth and
 * avatar-upload fields are dropped: `customers` carries name, email and
 * phone (Section 24), and inventing columns to fill template decoration
 * isn't this phase's job.
 */
export default function AccountSettings({ profile }: { profile: { name: string; email: string; phone: string } }) {
    const profileForm = useForm({ ...profile });
    const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });
    const { t } = useTranslation();

    return (
        <StorefrontLayout>
            <Head title={t('account.navSettings')} />
            <Breadcrumb title={t('account.navSettings')} />

            <div className="my-account-block md:py-20 py-10">
                <div className="container">
                    <div className="content-main lg:px-[60px] md:px-4 flex gap-y-8 max-md:flex-col w-full">
                        <AccountNav active="settings" />
                        <div className="right list-filter md:w-2/3 w-full ps-2.5">
                            <div className="text-content w-full p-7 border border-line rounded-xl">
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        profileForm.put(route('account.settings.update'), { preserveScroll: true });
                                    }}
                                >
                                    <div className="heading5 pb-4">{t('account.information')}</div>
                                    <div className="grid sm:grid-cols-2 gap-4 gap-y-5">
                                        <div>
                                            <label htmlFor="name" className="caption1 capitalize">
                                                {t('account.fullName')} <span className="text-red">*</span>
                                            </label>
                                            <input
                                                id="name"
                                                className="border-line mt-2 px-4 py-3 w-full rounded-lg"
                                                type="text"
                                                value={profileForm.data.name}
                                                onChange={(event) => profileForm.setData('name', event.target.value)}
                                                required
                                            />
                                            {profileForm.errors.name && (
                                                <div className="caption1 text-red mt-1">{profileForm.errors.name}</div>
                                            )}
                                        </div>
                                        <div>
                                            <label htmlFor="phone" className="caption1 capitalize">
                                                {t('account.phoneNumber')} <span className="text-red">*</span>
                                            </label>
                                            <input
                                                id="phone"
                                                className="border-line mt-2 px-4 py-3 w-full rounded-lg"
                                                type="text"
                                                value={profileForm.data.phone}
                                                onChange={(event) => profileForm.setData('phone', event.target.value)}
                                                required
                                            />
                                        </div>
                                        <div className="col-span-full">
                                            <label htmlFor="email" className="caption1 capitalize">
                                                {t('account.emailAddress')} <span className="text-red">*</span>
                                            </label>
                                            <input
                                                id="email"
                                                className="border-line mt-2 px-4 py-3 w-full rounded-lg"
                                                type="email"
                                                value={profileForm.data.email}
                                                onChange={(event) => profileForm.setData('email', event.target.value)}
                                                required
                                            />
                                            {profileForm.errors.email && (
                                                <div className="caption1 text-red mt-1">{profileForm.errors.email}</div>
                                            )}
                                        </div>
                                    </div>
                                    <div className="block-button lg:mt-10 mt-6">
                                        <button type="submit" className="button-main" disabled={profileForm.processing}>
                                            {t('common.saveChanges')}
                                        </button>
                                    </div>
                                </form>

                                <form
                                    className="mt-12"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        passwordForm.put(route('account.password.update'), {
                                            preserveScroll: true,
                                            onSuccess: () => passwordForm.reset(),
                                        });
                                    }}
                                >
                                    <div className="heading5 pb-4">{t('account.changePassword')}</div>
                                    <div className="pass">
                                        <label htmlFor="current_password" className="caption1">
                                            {t('account.currentPassword')} <span className="text-red">*</span>
                                        </label>
                                        <input
                                            id="current_password"
                                            className="border-line mt-2 px-4 py-3 w-full rounded-lg"
                                            type="password"
                                            value={passwordForm.data.current_password}
                                            onChange={(event) =>
                                                passwordForm.setData('current_password', event.target.value)
                                            }
                                            required
                                        />
                                        {passwordForm.errors.current_password && (
                                            <div className="caption1 text-red mt-1">
                                                {passwordForm.errors.current_password}
                                            </div>
                                        )}
                                    </div>
                                    <div className="new-pass mt-5">
                                        <label htmlFor="password" className="caption1">
                                            {t('account.newPassword')} <span className="text-red">*</span>
                                        </label>
                                        <input
                                            id="password"
                                            className="border-line mt-2 px-4 py-3 w-full rounded-lg"
                                            type="password"
                                            value={passwordForm.data.password}
                                            onChange={(event) => passwordForm.setData('password', event.target.value)}
                                            required
                                        />
                                        {passwordForm.errors.password && (
                                            <div className="caption1 text-red mt-1">{passwordForm.errors.password}</div>
                                        )}
                                    </div>
                                    <div className="confirm-pass mt-5">
                                        <label htmlFor="password_confirmation" className="caption1">
                                            {t('account.confirmNewPassword')} <span className="text-red">*</span>
                                        </label>
                                        <input
                                            id="password_confirmation"
                                            className="border-line mt-2 px-4 py-3 w-full rounded-lg"
                                            type="password"
                                            value={passwordForm.data.password_confirmation}
                                            onChange={(event) =>
                                                passwordForm.setData('password_confirmation', event.target.value)
                                            }
                                            required
                                        />
                                    </div>
                                    <div className="block-button lg:mt-10 mt-6">
                                        <button
                                            type="submit"
                                            className="button-main"
                                            disabled={passwordForm.processing}
                                        >
                                            Change password
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
