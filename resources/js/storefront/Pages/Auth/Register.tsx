import { Head, Link, useForm } from '@inertiajs/react';
import Breadcrumb from '../../Components/Breadcrumb';
import StorefrontLayout from '../../Layouts/StorefrontLayout';

/**
 * Anvogue's register.html, with the Name and Phone fields the template
 * omits and the spec requires — "Name, Email, Phone, Password, Password
 * Confirmation" (Section 13).
 */
export default function Register() {
    const form = useForm({ name: '', email: '', phone: '', password: '', password_confirmation: '' });

    return (
        <StorefrontLayout>
            <Head title="Register" />
            <Breadcrumb title="Register" />

            <div className="register-block md:py-20 py-10">
                <div className="container">
                    <div className="content-main flex gap-y-8 max-md:flex-col">
                        <div className="left md:w-1/2 w-full lg:pr-[60px] md:pr-[40px] md:border-r border-line">
                            <div className="heading4">Register</div>
                            <form
                                className="md:mt-7 mt-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(route('register.store'));
                                }}
                            >
                                {(
                                    [
                                        { key: 'name', type: 'text', placeholder: 'Full name *' },
                                        { key: 'email', type: 'email', placeholder: 'Email address *' },
                                        { key: 'phone', type: 'text', placeholder: 'Phone number *' },
                                        { key: 'password', type: 'password', placeholder: 'Password *' },
                                        {
                                            key: 'password_confirmation',
                                            type: 'password',
                                            placeholder: 'Confirm Password *',
                                        },
                                    ] as const
                                ).map((field, index) => (
                                    <div key={field.key} className={index === 0 ? '' : 'mt-5'}>
                                        <input
                                            className="border-line px-4 pt-3 pb-3 w-full rounded-lg"
                                            id={field.key}
                                            type={field.type}
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
                                        Register
                                    </button>
                                </div>
                            </form>
                        </div>
                        <div className="right md:w-1/2 w-full lg:pl-[60px] md:pl-[40px] flex items-center">
                            <div className="text-content">
                                <div className="heading4">Already have an account?</div>
                                <div className="mt-2 text-secondary">
                                    Sign in to pick up where you left off — your cart comes with you.
                                </div>
                                <div className="block-button md:mt-7 mt-4">
                                    <Link href={route('login')} className="button-main">
                                        Login
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
