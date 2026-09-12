import { Head, Link } from '@inertiajs/react';
import Breadcrumb from '../../Components/Breadcrumb';
import StorefrontLayout from '../../Layouts/StorefrontLayout';

/** Anvogue's about.html — a static route, not a CMS page (Section 17). */
export default function About() {
    return (
        <StorefrontLayout>
            <Head title="About us" />
            <Breadcrumb title="About us" />

            <div className="about md:pt-20 pt-10">
                <div className="about-us-block">
                    <div className="container">
                        <div className="text flex items-center justify-center">
                            <div className="content md:w-5/6 w-full">
                                <div className="heading3 text-center">WAQAR</div>
                                <div className="body1 text-center md:mt-7 mt-5">
                                    A single-store fashion label, run end to end by one team — the same people who
                                    choose the pieces check your order, arrange its delivery and settle it when it
                                    arrives. Every order is paid in cash on delivery, so nothing is charged before you
                                    have what you ordered in your hands.
                                </div>
                            </div>
                        </div>
                        <div className="list-img grid sm:grid-cols-3 gap-[30px] md:pt-20 pt-10">
                            <div className="bg-img">
                                <img
                                    src="/storefront/images/generated/about-1.svg"
                                    alt=""
                                    className="w-full rounded-3xl"
                                />
                            </div>
                            <div className="bg-img">
                                <img
                                    src="/storefront/images/generated/about-2.svg"
                                    alt=""
                                    className="w-full rounded-3xl"
                                />
                            </div>
                            <div className="bg-img">
                                <img
                                    src="/storefront/images/generated/about-3.svg"
                                    alt=""
                                    className="w-full rounded-3xl"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div className="benefit-block md:py-20 py-10">
                    <div className="container">
                        <div className="list-benefit grid items-start lg:grid-cols-3 grid-cols-1 gap-[30px]">
                            <div className="benefit-item flex flex-col items-center justify-center">
                                <i className="icon-guarantee lg:text-7xl text-5xl"></i>
                                <div className="heading6 text-center mt-5">Pay on delivery</div>
                                <div className="caption1 text-secondary text-center mt-3">
                                    Cash on delivery is the only payment method — we never collect card details.
                                </div>
                            </div>
                            <div className="benefit-item flex flex-col items-center justify-center">
                                <i className="icon-delivery-truck lg:text-7xl text-5xl"></i>
                                <div className="heading6 text-center mt-5">Delivered to your area</div>
                                <div className="caption1 text-secondary text-center mt-3">
                                    Shipping is priced for your exact area, not a flat national guess.
                                </div>
                            </div>
                            <div className="benefit-item flex flex-col items-center justify-center">
                                <i className="icon-return lg:text-7xl text-5xl"></i>
                                <div className="heading6 text-center mt-5">Returns that work</div>
                                <div className="caption1 text-secondary text-center mt-3">
                                    Refuse at the door, or start a return from your account after delivery.
                                </div>
                            </div>
                        </div>
                        <div className="text-center md:mt-14 mt-10">
                            <Link href={route('shop.index')} className="button-main">
                                Shop the collection
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
