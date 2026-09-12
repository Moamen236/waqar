import { Head, Link, useForm } from '@inertiajs/react';
import Breadcrumb from '../../Components/Breadcrumb';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import { useTranslation } from '../../lib/useTranslation';

/** Anvogue's login.html — email + password, no social login (Section 13). */
export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });
    const { t } = useTranslation();

    return (
        <StorefrontLayout>
            <Head title={t('auth.login')} />
            <Breadcrumb title={t('auth.login')} />

            <div className="login-block md:py-20 py-10">
                <div className="container">
                    <div className="content-main flex gap-y-8 max-md:flex-col">
                        <div className="left md:w-1/2 w-full lg:pe-[60px] md:pe-[40px] md:border-r border-line">
                            <div className="heading4">{t('auth.login')}</div>
                            <form
                                className="md:mt-7 mt-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(route('login.store'));
                                }}
                            >
                                <div className="email">
                                    <input
                                        className="border-line px-4 pt-3 pb-3 w-full rounded-lg"
                                        id="email"
                                        type="email"
                                        placeholder={t('auth.emailPlaceholder')}
                                        value={form.data.email}
                                        onChange={(event) => form.setData('email', event.target.value)}
                                        required
                                    />
                                    {form.errors.email && (
                                        <div className="caption1 text-red mt-1">{form.errors.email}</div>
                                    )}
                                </div>
                                <div className="pass mt-5">
                                    <input
                                        className="border-line px-4 pt-3 pb-3 w-full rounded-lg"
                                        id="password"
                                        type="password"
                                        placeholder={t('auth.passwordPlaceholder')}
                                        value={form.data.password}
                                        onChange={(event) => form.setData('password', event.target.value)}
                                        required
                                    />
                                </div>
                                <div className="flex items-center justify-between mt-5">
                                    <div className="flex items-center">
                                        <div className="block-input">
                                            <input
                                                type="checkbox"
                                                id="remember"
                                                checked={form.data.remember}
                                                onChange={(event) => form.setData('remember', event.target.checked)}
                                            />
                                            <i className="ph-fill ph-check-square icon-checkbox text-2xl"></i>
                                        </div>
                                        <label htmlFor="remember" className="ps-2 cursor-pointer">
                                            {t('auth.rememberMe')}
                                        </label>
                                    </div>
                                    <Link href={route('password.request')} className="font-semibold hover:underline">
                                        {t('auth.forgotPassword')}
                                    </Link>
                                </div>
                                <div className="block-button md:mt-7 mt-4">
                                    <button type="submit" className="button-main" disabled={form.processing}>
                                        {t('auth.login')}
                                    </button>
                                </div>
                            </form>
                        </div>
                        <div className="right md:w-1/2 w-full lg:ps-[60px] md:ps-[40px] flex items-center">
                            <div className="text-content">
                                <div className="heading4">{t('auth.newCustomer')}</div>
                                <div className="mt-2 text-secondary">{t('auth.newCustomerBody')}</div>
                                <div className="block-button md:mt-7 mt-4">
                                    <Link href={route('register')} className="button-main">
                                        {t('auth.register')}
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
