import { Head, useForm } from '@inertiajs/react';
import Breadcrumb from '../../Components/Breadcrumb';
import StorefrontLayout from '../../Layouts/StorefrontLayout';

/**
 * Anvogue's contact.html. The template's embedded Google Map is dropped
 * — this is a single online store with no retail location to point at
 * (Q4 dropped the store locator for the same reason).
 *
 * The form delivers to the business's own support inbox over SMTP rather
 * than into a database table: Section 24 defines no contact-message
 * model, and Customer Service (Section 15) works from the inbox. The
 * hidden `website` field is a honeypot — the server rejects any request
 * that fills it in.
 */
export default function Contact() {
    const form = useForm({ name: '', email: '', order_number: '', message: '', website: '' });

    return (
        <StorefrontLayout>
            <Head title="Contact" />
            <Breadcrumb title="Contact us" />

            <div className="contact-us md:py-20 py-10">
                <div className="container">
                    <div className="flex justify-between max-lg:flex-col gap-y-10">
                        <div className="left lg:w-2/3 lg:pe-4">
                            <div className="heading3">Get in touch</div>
                            <div className="body1 text-secondary2 mt-3">
                                Our Customer Service team handles orders, deliveries and returns.
                            </div>
                            <form
                                className="md:mt-6 mt-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(route('pages.contact.send'), {
                                        preserveScroll: true,
                                        onSuccess: () => form.reset(),
                                    });
                                }}
                            >
                                <div className="grid sm:grid-cols-2 grid-cols-1 gap-4 gap-y-5">
                                    <div className="name">
                                        <input
                                            className="border-line px-4 py-3 w-full rounded-lg"
                                            type="text"
                                            placeholder="Your Name *"
                                            value={form.data.name}
                                            onChange={(event) => form.setData('name', event.target.value)}
                                            required
                                        />
                                        {form.errors.name && (
                                            <div className="caption1 text-red mt-1">{form.errors.name}</div>
                                        )}
                                    </div>
                                    <div className="email">
                                        <input
                                            className="border-line px-4 pt-3 pb-3 w-full rounded-lg"
                                            type="email"
                                            placeholder="Your Email *"
                                            value={form.data.email}
                                            onChange={(event) => form.setData('email', event.target.value)}
                                            required
                                        />
                                        {form.errors.email && (
                                            <div className="caption1 text-red mt-1">{form.errors.email}</div>
                                        )}
                                    </div>
                                    <div className="sm:col-span-2">
                                        <input
                                            className="border-line px-4 py-3 w-full rounded-lg"
                                            type="text"
                                            placeholder="Order number (optional)"
                                            value={form.data.order_number}
                                            onChange={(event) => form.setData('order_number', event.target.value)}
                                        />
                                    </div>
                                    <div className="message sm:col-span-2">
                                        <textarea
                                            className="border-line px-4 pt-3 pb-3 w-full rounded-lg"
                                            rows={4}
                                            placeholder="Your Message *"
                                            value={form.data.message}
                                            onChange={(event) => form.setData('message', event.target.value)}
                                            required
                                        />
                                        {form.errors.message && (
                                            <div className="caption1 text-red mt-1">{form.errors.message}</div>
                                        )}
                                    </div>
                                </div>
                                {/* Honeypot — hidden from people, irresistible to bots. */}
                                <input
                                    type="text"
                                    name="website"
                                    tabIndex={-1}
                                    autoComplete="off"
                                    aria-hidden="true"
                                    className="hidden"
                                    value={form.data.website}
                                    onChange={(event) => form.setData('website', event.target.value)}
                                />
                                <div className="block-button md:mt-6 mt-4">
                                    <button type="submit" className="button-main" disabled={form.processing}>
                                        {form.processing ? 'Sending…' : 'Send message'}
                                    </button>
                                </div>
                            </form>
                        </div>
                        <div className="right lg:w-1/4 lg:ps-4">
                            <div className="item">
                                <div className="heading4">Contact</div>
                                <p className="mt-3">
                                    Phone: <span className="whitespace-nowrap">+20 100 000 0000</span>
                                </p>
                                <p className="mt-1">
                                    Email: <span className="whitespace-nowrap">support@waqar.test</span>
                                </p>
                            </div>
                            <div className="item mt-10">
                                <div className="heading4">Open Hours</div>
                                <p className="mt-3">
                                    Sat - Thu: <span className="whitespace-nowrap">9:00am - 6:00pm</span>
                                </p>
                                <p className="mt-3">
                                    Friday: <span className="whitespace-nowrap">Closed</span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
