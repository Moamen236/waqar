import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import { useTranslation } from '../../lib/useTranslation';

// Ported from Admin Template/auth-signin.html's authentication-form layout.
export default function Login() {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.login.store'));
    };

    return (
        <div className="d-flex flex-column min-vh-100 justify-content-center align-items-center bg-light-subtle">
            <Head title={t('admin.signIn')} />
            <div className="col-lg-4 col-md-6">
                <div className="card">
                    <div className="card-body p-4">
                        <div className="text-center mb-4">
                            <span className="fw-bold fs-4">WAQAR Admin</span>
                        </div>

                        <h2 className="fw-bold fs-20 text-center">{t('admin.signIn')}</h2>
                        <p className="text-muted mt-1 mb-4 text-center">
                            {t('admin.enterYourEmailAddressAndPassword')}
                        </p>

                        <form onSubmit={submit} className="authentication-form">
                            <div className="mb-3">
                                <label className="form-label" htmlFor="email">
                                    {t('admin.email')}
                                </label>
                                <input
                                    type="email"
                                    id="email"
                                    className="form-control"
                                    placeholder={t('admin.enterYourEmail')}
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    autoFocus
                                />
                                {errors.email && <div className="text-danger fs-13 mt-1">{errors.email}</div>}
                            </div>
                            <div className="mb-3">
                                <label className="form-label" htmlFor="password">
                                    {t('admin.password')}
                                </label>
                                <input
                                    type="password"
                                    id="password"
                                    className="form-control"
                                    placeholder={t('admin.enterYourPassword')}
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                />
                                {errors.password && <div className="text-danger fs-13 mt-1">{errors.password}</div>}
                            </div>
                            <div className="mb-3">
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
                            <div className="mb-1 text-center d-grid">
                                <button className="btn btn-primary" type="submit" disabled={processing}>
                                    {t('admin.signIn')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    );
}
