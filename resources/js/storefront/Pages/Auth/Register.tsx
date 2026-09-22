import { Head, Link, useForm } from '@inertiajs/react';
import Breadcrumb from '../../Components/Breadcrumb';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import { useTranslation } from '../../lib/useTranslation';

/**
 * Anvogue's register.html, with the Name and Phone fields the template
 * omits and the spec requires — "Name, Email, Phone, Password, Password
 * Confirmation" (Section 13).
 */
export default function Register() {
    const form = useForm({ name: '', email: '', phone: '', password: '', password_confirmation: '' });
    const { t } = useTranslation();

    return (
        <StorefrontLayout>
            <Head title={t('auth.register')} />
            <Breadcrumb title={t('auth.register')} />

            <div className="register-block md:py-20 py-10">
                <div className="container">
                    <div className="content-main flex gap-y-8 max-md:flex-col">
                        <div className="left md:w-1/2 w-full lg:pe-[60px] md:pe-[40px] md:border-r border-line">
                            <div className="heading4">{t('auth.register')}</div>
                            <form
                                className="md:mt-7 mt-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(route('register.store'));
                                }}
                            >
                                {(
                                    [
                                        { key: 'name', type: 'text', placeholder: t('auth.namePlaceholder') },
                                        { key: 'email', type: 'email', placeholder: t('auth.emailPlaceholder') },
                                        {
                                            key: 'phone',
                                            type: 'text',
                                            placeholder: t('auth.phonePlaceholder'),
                                            numeric: true,
                                        },
                                        {
                                            key: 'password',
                                            type: 'password',
                                            placeholder: t('auth.passwordPlaceholder'),
                                        },
                                        {
                                            key: 'password_confirmation',
                                            type: 'password',
                                            placeholder: t('auth.confirmPasswordPlaceholder'),
                                        },
                                    ] as const
                                ).map((field, index) => (
                                    <div key={field.key} className={index === 0 ? '' : 'mt-5'}>
                                        <input
                                            className="border-line px-4 pt-3 pb-3 w-full rounded-lg"
                                            id={field.key}
                                            type={field.type}
                                            inputMode={'numeric' in field ? 'numeric' : undefined}
                                            maxLength={'numeric' in field ? 11 : undefined}
                                            placeholder={field.placeholder}
                                            value={form.data[field.key]}
                                            onChange={(event) => form.setData(field.key, event.target.value)}
                                            required
                                        />
                                        {form.errors[field.key] && (
                                            <div className="caption1 text-red mt-1">{form.errors[field.key]}</div>
                                        )}
                                    </div>
                                ))}
                                <div className="block-button md:mt-7 mt-4">
                                    <button type="submit" className="button-main" disabled={form.processing}>
                                        {t('auth.register')}
                                    </button>
                                </div>
                            </form>
                        </div>
                        <div className="right md:w-1/2 w-full lg:ps-[60px] md:ps-[40px] flex items-center">
                            <div className="text-content">
                                <div className="heading4">{t('auth.haveAccount')}</div>
                                <div className="mt-2 text-secondary">{t('auth.haveAccountBody')}</div>
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
