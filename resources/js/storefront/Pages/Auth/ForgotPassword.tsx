import { Head, Link, useForm } from '@inertiajs/react';
import Breadcrumb from '../../Components/Breadcrumb';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import { useTranslation } from '../../lib/useTranslation';

/** Anvogue's forgot-password.html. */
export default function ForgotPassword() {
    const form = useForm({ email: '' });
    const { t } = useTranslation();

    return (
        <StorefrontLayout>
            <Head title={t('auth.forgotPasswordTitle')} />
            <Breadcrumb title={t('auth.forgotPasswordTitle')} />

            <div className="forgot-pass md:py-20 py-10">
                <div className="container">
                    <div className="content-main flex gap-y-8 max-md:flex-col">
                        <div className="left md:w-1/2 w-full lg:pe-[60px] md:pe-[40px] md:border-r border-line">
                            <div className="heading4">{t('auth.resetTitle')}</div>
                            <div className="body1 mt-2">{t('auth.resetBody')}</div>
                            <form
                                className="md:mt-7 mt-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(route('password.email'));
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
                                <div className="block-button md:mt-7 mt-4">
                                    <button type="submit" className="button-main" disabled={form.processing}>
                                        {t('common.submit')}
                                    </button>
                                </div>
                            </form>
                        </div>
                        <div className="right md:w-1/2 w-full lg:ps-[60px] md:ps-[40px] flex items-center">
                            <div className="text-content">
                                <div className="heading4">{t('auth.rememberedIt')}</div>
                                <div className="mt-2 text-secondary">{t('auth.rememberedItBody')}</div>
                                <div className="block-button md:mt-7 mt-4">
                                    <Link href={route('login')} className="button-main">
                                        {t('auth.login')}
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
