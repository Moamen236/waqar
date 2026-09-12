import { Head, useForm } from '@inertiajs/react';
import Breadcrumb from '../../Components/Breadcrumb';
import StorefrontLayout from '../../Layouts/StorefrontLayout';

/**
 * The reset form the template has no page for — same `forgot-pass` shell
 * as forgot-password.html so the pair reads as one flow.
 */
export default function ResetPassword({ token, email }: { token: string; email: string | null }) {
    const form = useForm({
        token,
        email: email ?? '',
        password: '',
        password_confirmation: '',
    });

    return (
        <StorefrontLayout>
            <Head title="Reset Password" />
            <Breadcrumb title="Reset Password" />

            <div className="forgot-pass md:py-20 py-10">
                <div className="container">
                    <div className="content-main flex gap-y-8 max-md:flex-col">
                        <div className="left md:w-1/2 w-full lg:pr-[60px] md:pr-[40px]">
                            <div className="heading4">Choose a new password</div>
                            <form
                                className="md:mt-7 mt-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(route('password.update'));
                                }}
                            >
                                <input
                                    className="border-line px-4 pt-3 pb-3 w-full rounded-lg"
                                    type="email"
                                    placeholder="Email address *"
                                    value={form.data.email}
                                    onChange={(event) => form.setData('email', event.target.value)}
                                    required
                                />
                                {form.errors.email && <div className="caption1 text-red mt-1">{form.errors.email}</div>}
                                <input
                                    className="border-line px-4 pt-3 pb-3 w-full rounded-lg mt-5"
                                    type="password"
                                    placeholder="New password *"
                                    value={form.data.password}
                                    onChange={(event) => form.setData('password', event.target.value)}
                                    required
                                />
                                {form.errors.password && (
                                    <div className="caption1 text-red mt-1">{form.errors.password}</div>
                                )}
                                <input
                                    className="border-line px-4 pt-3 pb-3 w-full rounded-lg mt-5"
                                    type="password"
                                    placeholder="Confirm new password *"
                                    value={form.data.password_confirmation}
                                    onChange={(event) => form.setData('password_confirmation', event.target.value)}
                                    required
                                />
                                <div className="block-button md:mt-7 mt-4">
                                    <button type="submit" className="button-main" disabled={form.processing}>
                                        Reset password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
