import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import FormField from '../../Components/Form/FormField';
import LocaleSwitcher from '../../Components/LocaleSwitcher';
import { revealErrors, useClearErrorsOnChange } from '../../lib/formErrors';
import { useTranslation } from '../../lib/useTranslation';

// Split layout: brand hero (login-bg.png) on the start side, the sign-in
// form on the end side. The hero is hidden below lg — on a phone the form
// is the whole job. Styles live in admin.css under `.auth-split`.
export default function Login() {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, clearErrors } = useForm({
        email: '',
        password: '',
        remember: false,
    });
    useClearErrorsOnChange(data, errors, clearErrors);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        // This page is outside AdminLayout, so its global error listener
        // isn't mounted — focus the first bad field directly.
        post(route('admin.login.store'), {
            onError: (formErrors) => window.setTimeout(() => revealErrors(formErrors)),
        });
    };

    return (
        <div className="auth-split d-flex min-vh-100">
            <Head title={t('admin.signIn')} />

            <aside className="auth-hero d-none d-lg-flex flex-column justify-content-between">
                <img src="/admin-theme/assets/images/logo-light.png" alt="WAQAR" className="auth-hero-logo" />
                <div className="auth-hero-copy">
                    <span className="auth-eyebrow">{t('admin.loginEyebrow')}</span>
                    <h1 className="auth-headline">{t('admin.loginHeadline')}</h1>
                    <p className="auth-tagline">{t('admin.loginTagline')}</p>
                </div>
            </aside>

            <main className="auth-panel d-flex flex-column flex-grow-1">
                {/* Before sign-in, not only after: an employee who does not
                    read Arabic cannot reach the topbar switcher without
                    first getting through this page. */}
                <div className="d-flex justify-content-end">
                    <LocaleSwitcher />
                </div>

                <div className="auth-form-wrap m-auto w-100">
                    <img src="/admin-theme/assets/images/logo-dark.png" alt="WAQAR" className="auth-form-logo mb-4" />

                    <h2 className="fw-bold fs-2 mb-1">{t('admin.welcomeBack')}</h2>
                    <p className="text-muted mb-4">{t('admin.enterYourEmailAddressAndPassword')}</p>

                    <form onSubmit={submit} className="authentication-form">
                        <FormField name="email" label={t('admin.email')} error={errors.email} required>
                            <input
                                type="email"
                                id="email"
                                className="form-control form-control-lg"
                                placeholder={t('admin.enterYourEmail')}
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                autoComplete="username"
                                autoFocus
                            />
                        </FormField>
                        <FormField name="password" label={t('admin.password')} error={errors.password} required>
                            <input
                                type="password"
                                id="password"
                                className="form-control form-control-lg"
                                placeholder={t('admin.enterYourPassword')}
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                autoComplete="current-password"
                            />
                        </FormField>
                        <div className="mb-4">
                            <div className="form-check">
                                <input
                                    type="checkbox"
                                    className="form-check-input"
                                    id="remember"
                                    checked={data.remember}
                                    onChange={(e) => setData('remember', e.target.checked)}
                                />
                                <label className="form-check-label" htmlFor="remember">
                                    {t('admin.rememberMe')}
                                </label>
                            </div>
                        </div>
                        <div className="d-grid">
                            <button className="btn btn-primary btn-lg rounded-pill" type="submit" disabled={processing}>
                                {t('admin.signIn')}
                            </button>
                        </div>
                    </form>
                </div>
            </main>
        </div>
    );
}
