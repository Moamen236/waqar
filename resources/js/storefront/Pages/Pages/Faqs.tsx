import { Head } from '@inertiajs/react';
import { useState } from 'react';
import Breadcrumb from '../../Components/Breadcrumb';
import StorefrontLayout from '../../Layouts/StorefrontLayout';

/**
 * Anvogue's faqs.html accordion, with answers written from this system's
 * actual rules rather than the template's lorem copy — COD only, the
 * five-stage order timeline, area-level shipping rates, and the two
 * return moments (refuse at the door, or return after delivery).
 */
const groups = [
    {
        title: 'Ordering',
        items: [
            {
                q: 'How do I pay?',
                a: 'Cash on delivery, always. We never ask for card details and no payment is taken before your order reaches you.',
            },
            {
                q: 'Can I order without an account?',
                a: 'Yes. Guest checkout asks only for your name, email, phone and delivery address. You can look the order up later on the Order Tracking page with your order number and that email address.',
            },
            {
                q: 'Can I change or cancel an order?',
                a: 'You can cancel from your account while the order is still being processed. Once it has been confirmed for delivery, contact Customer Service and they will handle it.',
            },
        ],
    },
    {
        title: 'Delivery',
        items: [
            {
                q: 'How is shipping calculated?',
                a: 'By where you are. Pick your governorate, city, district and area at checkout and the store returns the rate configured for the most specific level that has one — you never type or adjust a shipping price yourself.',
            },
            {
                q: 'How do I follow my order?',
                a: 'Every order moves through five stages: Order Received, Processing, Shipping, Out for Delivery, Delivered. You can watch it from your account, or from Order Tracking with your order number and email.',
            },
        ],
    },
    {
        title: 'Returns',
        items: [
            {
                q: 'Can I refuse a delivery?',
                a: 'Yes — you can refuse the whole order or part of it at the door, and only pay for what you keep.',
            },
            {
                q: 'What if I want to return something after delivery?',
                a: 'Contact Customer Service with your order number. A return goes through approval, collection and inspection before the refund is issued, and any return-shipping fee is agreed with you first.',
            },
        ],
    },
];

export default function Faqs() {
    const [open, setOpen] = useState<string | null>('Ordering-0');

    return (
        <StorefrontLayout>
            <Head title="FAQs" />
            <Breadcrumb title="FAQs" />

            <div className="faqs-block md:py-20 py-10">
                <div className="container">
                    <div className="flex justify-between max-xl:flex-col gap-y-8">
                        <div className="left xl:w-1/4 w-full">
                            <div className="menu-tab flex flex-col gap-5">
                                {groups.map((group) => (
                                    <div key={group.title} className="heading6">
                                        {group.title}
                                    </div>
                                ))}
                            </div>
                        </div>
                        <div className="right xl:w-3/4 xl:ps-20">
                            {groups.map((group) => (
                                <div key={group.title} className="tab-question mb-10">
                                    <div className="heading5">{group.title}</div>
                                    {group.items.map((item, index) => {
                                        const key = `${group.title}-${index}`;

                                        return (
                                            <div
                                                key={key}
                                                className={`question-item px-7 rounded-[20px] overflow-hidden border border-line cursor-pointer mt-5 ${
                                                    open === key ? 'open' : ''
                                                }`}
                                                onClick={() => setOpen(open === key ? null : key)}
                                            >
                                                <div className="heading flex items-center justify-between gap-6 py-4">
                                                    <div className="heading6">{item.q}</div>
                                                    <i
                                                        className={`ph-bold ph-caret-down text-xl duration-300 ${
                                                            open === key ? 'rotate-180' : ''
                                                        }`}
                                                    ></i>
                                                </div>
                                                {open === key && (
                                                    <div className="content body1 text-secondary pb-4">{item.a}</div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
