import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import MiniCart from '../Components/MiniCart';
import SearchModal from '../Components/SearchModal';
import type { SharedProps } from '../types';

/**
 * The Anvogue global shell — top-nav, header (mega-menu, search, account
 * popup, wishlist + mini-cart icons), mobile menu, the mobile bottom
 * menu_bar, footer and scroll-to-top — ported from index.html's markup
 * with its own classes intact (`top-nav style-one`, `header-menu
 * style-one`, `menu-mobile-icon`, `footer-main`, …) so the theme CSS
 * styles it exactly as the template does.
 *
 * Section 17's required changes are applied here rather than carried
 * over:
 *  - the currency switcher (USD/EUR/GBP) is removed entirely — single
 *    currency, EGP (Q11);
 *  - the cosmetic English/Espana/France language switcher is removed;
 *    real ar/en switching is Phase 6, and adding a fake one now would
 *    just have to be torn out again;
 *  - the Demo / Features / Blog / Store-List mega-menu columns are gone
 *    — the menu is the real category tree, and there is no blog or CMS
 *    module in this system;
 *  - the Compare icon/modal is dropped (Q3).
 *
 * The template's own main.js drives these interactions by mutating the
 * DOM directly; that assumes a non-React lifecycle, so the behaviour is
 * reimplemented in component state here (same class names, same markup).
 */
export default function StorefrontLayout({
    children,
    headerStyle = 'default',
}: {
    children: ReactNode;
    /** `transparent` is index.html's over-the-slider header. */
    headerStyle?: 'default' | 'transparent';
}) {
    const { auth, storefront, flash } = usePage<SharedProps>().props;
    const [mobileMenu, setMobileMenu] = useState(false);
    const [openGroup, setOpenGroup] = useState<string | null>(null);
    const [searchOpen, setSearchOpen] = useState(false);
    const [cartOpen, setCartOpen] = useState(false);
    const [accountOpen, setAccountOpen] = useState(false);

    const categories = storefront?.nav.categories ?? [];
    const collections = storefront?.nav.collections ?? [];

    // Any navigation closes every overlay — the template gets this for
    // free from full page loads; an Inertia visit doesn't.
    useEffect(() => {
        const stop = router.on('navigate', () => {
            setMobileMenu(false);
            setSearchOpen(false);
            setCartOpen(false);
            setAccountOpen(false);
            setOpenGroup(null);
        });

        return stop;
    }, []);

    useEffect(() => {
        document.body.classList.toggle('overflow-hidden', mobileMenu || searchOpen);
    }, [mobileMenu, searchOpen]);

    return (
        <>
            <div id="top-nav" className="top-nav style-one bg-black md:h-[44px] h-[30px]">
                <div className="container mx-auto h-full">
                    <div className="top-nav-main flex justify-between max-md:justify-center h-full">
                        <div className="left-content flex items-center gap-5 max-md:hidden">
                            <Link href={route('order-tracking.index')} className="caption2 text-white hover:underline">
                                Track your order
                            </Link>
                        </div>
                        <div className="text-center text-button-uppercase text-white flex items-center">
                            Cash on delivery on every order
                        </div>
                        <div className="right-content flex items-center gap-5 max-md:hidden">
                            <a href="https://www.facebook.com/" target="_blank" rel="noreferrer">
                                <i className="icon-facebook text-white"></i>
                            </a>
                            <a href="https://www.instagram.com/" target="_blank" rel="noreferrer">
                                <i className="icon-instagram text-white"></i>
                            </a>
                            <a href="https://www.youtube.com/" target="_blank" rel="noreferrer">
                                <i className="icon-youtube text-white"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div id="header" className="relative w-full">
                <div
                    className={`header-menu style-one w-full md:h-[74px] h-[56px] ${
                        headerStyle === 'transparent'
                            ? 'absolute top-0 left-0 right-0 bg-transparent'
                            : 'relative bg-white border-b border-line'
                    }`}
                >
                    <div className="container mx-auto h-full">
                        <div className="header-main flex justify-between h-full">
                            <div
                                className="menu-mobile-icon lg:hidden flex items-center"
                                onClick={() => setMobileMenu(true)}
                            >
                                <i className="icon-category text-2xl"></i>
                            </div>
                            <div className="left flex items-center gap-16">
                                <Link
                                    href={route('home')}
                                    className="flex items-center max-lg:absolute max-lg:left-1/2 max-lg:-translate-x-1/2"
                                >
                                    <div className="heading4">WAQAR</div>
                                </Link>
                                <div className="menu-main h-full max-lg:hidden">
                                    <ul className="flex items-center gap-8 h-full">
                                        <li className="h-full relative">
                                            <Link
                                                href={route('shop.index')}
                                                className="text-button-uppercase duration-300 h-full flex items-center justify-center"
                                            >
                                                Shop
                                            </Link>
                                        </li>
                                        {categories.map((category) => (
                                            <li key={category.slug} className="h-full relative">
                                                <Link
                                                    href={route('shop.category', category.slug)}
                                                    className="text-button-uppercase duration-300 h-full flex items-center justify-center gap-1"
                                                >
                                                    {category.name}
                                                    {category.children.length > 0 && (
                                                        <i className="ph ph-caret-down text-xs"></i>
                                                    )}
                                                </Link>
                                                {category.children.length > 0 && (
                                                    <div className="sub-menu py-3 px-5 -left-10 w-max absolute grid gap-5 bg-white rounded-b-xl">
                                                        <ul>
                                                            {category.children.map((child) => (
                                                                <li key={child.slug}>
                                                                    <Link
                                                                        href={route('shop.category', child.slug)}
                                                                        className="link text-secondary duration-300"
                                                                    >
                                                                        {child.name}
                                                                    </Link>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                )}
                                            </li>
                                        ))}
                                        {collections.length > 0 && (
                                            <li className="h-full relative">
                                                <span className="text-button-uppercase duration-300 h-full flex items-center justify-center gap-1 cursor-pointer">
                                                    Collections
                                                    <i className="ph ph-caret-down text-xs"></i>
                                                </span>
                                                <div className="sub-menu py-3 px-5 -left-10 w-max absolute grid gap-5 bg-white rounded-b-xl">
                                                    <ul>
                                                        {collections.map((collection) => (
                                                            <li key={collection.slug}>
                                                                <Link
                                                                    href={route('shop.collection', collection.slug)}
                                                                    className="link text-secondary duration-300"
                                                                >
                                                                    {collection.name}
                                                                </Link>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </div>
                                            </li>
                                        )}
                                        <li className="h-full relative">
                                            <Link
                                                href={route('pages.about')}
                                                className="text-button-uppercase duration-300 h-full flex items-center justify-center"
                                            >
                                                About
                                            </Link>
                                        </li>
                                        <li className="h-full relative">
                                            <Link
                                                href={route('pages.contact')}
                                                className="text-button-uppercase duration-300 h-full flex items-center justify-center"
                                            >
                                                Contact
                                            </Link>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <div className="right flex gap-12 z-[1]">
                                <div
                                    className="max-md:hidden search-icon flex items-center cursor-pointer relative"
                                    onClick={() => setSearchOpen(true)}
                                >
                                    <i className="ph-bold ph-magnifying-glass text-2xl"></i>
                                    <div className="line absolute bg-line w-px h-6 -right-6"></div>
                                </div>
                                <div className="list-action flex items-center gap-4">
                                    <div
                                        className="user-icon flex items-center justify-center cursor-pointer relative"
                                        onClick={() => setAccountOpen((open) => !open)}
                                    >
                                        <i className="ph-bold ph-user text-2xl"></i>
                                        {accountOpen && (
                                            <div className="login-popup absolute top-[42px] right-0 w-[320px] p-7 rounded-xl bg-white box-shadow-sm z-10 block opacity-100 visible">
                                                {auth.customer ? (
                                                    <>
                                                        <div className="text-button pb-3">Hi, {auth.customer.name}</div>
                                                        <Link
                                                            href={route('account.dashboard')}
                                                            className="button-main w-full text-center"
                                                        >
                                                            My Account
                                                        </Link>
                                                        <Link
                                                            href={route('account.orders')}
                                                            className="button-main bg-white text-black border border-black w-full text-center mt-3"
                                                        >
                                                            My Orders
                                                        </Link>
                                                        <div className="bottom mt-4 pt-4 border-t border-line"></div>
                                                        <button
                                                            type="button"
                                                            className="body1 hover:underline"
                                                            onClick={() => router.post(route('logout'))}
                                                        >
                                                            Logout
                                                        </button>
                                                    </>
                                                ) : (
                                                    <>
                                                        <Link
                                                            href={route('login')}
                                                            className="button-main w-full text-center"
                                                        >
                                                            Login
                                                        </Link>
                                                        <div className="text-secondary text-center mt-3 pb-4">
                                                            Don&apos;t have an account?
                                                            <Link
                                                                href={route('register')}
                                                                className="text-black pl-1 hover:underline"
                                                            >
                                                                Register
                                                            </Link>
                                                        </div>
                                                        <Link
                                                            href={route('order-tracking.index')}
                                                            className="button-main bg-white text-black border border-black w-full text-center"
                                                        >
                                                            Track an order
                                                        </Link>
                                                    </>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                    <Link
                                        href={auth.customer ? route('wishlist.index') : route('login')}
                                        className="max-md:hidden wishlist-icon flex items-center relative cursor-pointer"
                                    >
                                        <i className="ph-bold ph-heart text-2xl"></i>
                                        <span className="quantity wishlist-quantity absolute -right-1.5 -top-1.5 text-xs text-white bg-black w-4 h-4 flex items-center justify-center rounded-full">
                                            {storefront?.wishlistCount ?? 0}
                                        </span>
                                    </Link>
                                    <div
                                        className="max-md:hidden cart-icon flex items-center relative cursor-pointer"
                                        onClick={() => setCartOpen(true)}
                                    >
                                        <i className="ph-bold ph-handbag text-2xl"></i>
                                        <span className="quantity cart-quantity absolute -right-1.5 -top-1.5 text-xs text-white bg-black w-4 h-4 flex items-center justify-center rounded-full">
                                            {storefront?.cartCount ?? 0}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="menu-mobile" className={mobileMenu ? 'open' : ''}>
                    <div className="menu-container bg-white h-full">
                        <div className="container h-full">
                            <div className="menu-main h-full overflow-hidden">
                                <div className="heading py-2 relative flex items-center justify-center">
                                    <div
                                        className="close-menu-mobile-btn absolute left-0 top-1/2 -translate-y-1/2 w-6 h-6 rounded-full bg-surface flex items-center justify-center"
                                        onClick={() => setMobileMenu(false)}
                                    >
                                        <i className="ph ph-x text-sm"></i>
                                    </div>
                                    <Link href={route('home')} className="logo text-3xl font-semibold text-center">
                                        WAQAR
                                    </Link>
                                </div>
                                <form
                                    className="form-search relative mt-2"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        const term = new FormData(event.currentTarget).get('q');
                                        router.get(route('search.index'), { q: String(term ?? '') });
                                    }}
                                >
                                    <i className="ph ph-magnifying-glass text-xl absolute left-3 top-1/2 -translate-y-1/2 cursor-pointer"></i>
                                    <input
                                        type="text"
                                        name="q"
                                        placeholder="What are you looking for?"
                                        className="h-12 rounded-lg border border-line text-sm w-full pl-10 pr-4"
                                    />
                                </form>
                                <div className="list-nav mt-6">
                                    <ul>
                                        <li>
                                            <Link
                                                href={route('shop.index')}
                                                className="text-xl font-semibold flex items-center justify-between"
                                            >
                                                Shop
                                            </Link>
                                        </li>
                                        {categories.map((category) => (
                                            <li key={category.slug}>
                                                <button
                                                    type="button"
                                                    className="text-xl font-semibold flex items-center justify-between w-full mt-5"
                                                    onClick={() =>
                                                        setOpenGroup((current) =>
                                                            current === category.slug ? null : category.slug,
                                                        )
                                                    }
                                                >
                                                    {category.name}
                                                    <span className="text-right">
                                                        <i className="ph ph-caret-right text-xl"></i>
                                                    </span>
                                                </button>
                                                <div
                                                    className={`sub-nav-mobile ${openGroup === category.slug ? 'open' : ''}`}
                                                >
                                                    <div
                                                        className="back-btn flex items-center gap-3"
                                                        onClick={() => setOpenGroup(null)}
                                                    >
                                                        <i className="ph ph-caret-left text-xl"></i>
                                                        Back
                                                    </div>
                                                    <div className="list-nav-item w-full pt-2 pb-6">
                                                        <ul className="w-full">
                                                            <li>
                                                                <Link
                                                                    href={route('shop.category', category.slug)}
                                                                    className="link text-secondary duration-300"
                                                                >
                                                                    All {category.name}
                                                                </Link>
                                                            </li>
                                                            {category.children.map((child) => (
                                                                <li key={child.slug}>
                                                                    <Link
                                                                        href={route('shop.category', child.slug)}
                                                                        className="link text-secondary duration-300"
                                                                    >
                                                                        {child.name}
                                                                    </Link>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                </div>
                                            </li>
                                        ))}
                                        <li>
                                            <Link
                                                href={route('pages.about')}
                                                className="text-xl font-semibold flex items-center justify-between mt-5"
                                            >
                                                About Us
                                            </Link>
                                        </li>
                                        <li>
                                            <Link
                                                href={route('pages.contact')}
                                                className="text-xl font-semibold flex items-center justify-between mt-5"
                                            >
                                                Contact Us
                                            </Link>
                                        </li>
                                        <li>
                                            <Link
                                                href={route('order-tracking.index')}
                                                className="text-xl font-semibold flex items-center justify-between mt-5"
                                            >
                                                Order Tracking
                                            </Link>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="menu_bar fixed bg-white bottom-0 left-0 w-full h-[70px] sm:hidden z-[101]">
                    <div className="menu_bar-inner grid grid-cols-4 items-center h-full">
                        <Link href={route('home')} className="menu_bar-link flex flex-col items-center gap-1">
                            <span className="ph-bold ph-house text-2xl block"></span>
                            <span className="menu_bar-title caption2 font-semibold">Home</span>
                        </Link>
                        <Link href={route('shop.index')} className="menu_bar-link flex flex-col items-center gap-1">
                            <span className="ph-bold ph-list text-2xl block"></span>
                            <span className="menu_bar-title caption2 font-semibold">Shop</span>
                        </Link>
                        <Link href={route('search.index')} className="menu_bar-link flex flex-col items-center gap-1">
                            <span className="ph-bold ph-magnifying-glass text-2xl block"></span>
                            <span className="menu_bar-title caption2 font-semibold">Search</span>
                        </Link>
                        <Link href={route('cart.index')} className="menu_bar-link flex flex-col items-center gap-1">
                            <div className="cart-icon relative">
                                <span className="ph-bold ph-handbag text-2xl block"></span>
                                <span className="quantity cart-quantity absolute -right-1.5 -top-1.5 text-xs text-white bg-black w-4 h-4 flex items-center justify-center rounded-full">
                                    {storefront?.cartCount ?? 0}
                                </span>
                            </div>
                            <span className="menu_bar-title caption2 font-semibold">Cart</span>
                        </Link>
                    </div>
                </div>
            </div>

            {(flash.success || flash.error) && (
                <div className="container">
                    <div
                        className={`caption1 mt-4 px-5 py-3 rounded-lg ${
                            flash.error ? 'bg-red text-white' : 'bg-green text-black'
                        }`}
                    >
                        {flash.error ?? flash.success}
                    </div>
                </div>
            )}

            {children}

            <div id="footer" className="footer">
                <div className="footer-main bg-surface">
                    <div className="container">
                        <div className="content-footer md:py-[60px] py-10 flex justify-between flex-wrap gap-y-8">
                            <div className="company-infor basis-1/4 max-lg:basis-full pr-7">
                                <Link href={route('home')} className="logo inline-block">
                                    <div className="heading3 w-fit">WAQAR</div>
                                </Link>
                                <div className="flex gap-3 mt-3">
                                    <div className="flex flex-col">
                                        <span className="text-button">Mail:</span>
                                        <span className="text-button mt-3">Phone:</span>
                                        <span className="text-button mt-3">Address:</span>
                                    </div>
                                    <div className="flex flex-col">
                                        <span>support@waqar.test</span>
                                        <span className="mt-[14px]">+20 100 000 0000</span>
                                        <span className="mt-3 pt-1">Cairo, Egypt</span>
                                    </div>
                                </div>
                            </div>
                            <div className="right-content flex flex-wrap gap-y-8 basis-3/4 max-lg:basis-full">
                                <div className="list-nav flex justify-between basis-2/3 max-md:basis-full gap-4">
                                    <div className="item flex flex-col basis-1/3">
                                        <div className="text-button-uppercase pb-3">Information</div>
                                        <Link
                                            className="caption1 has-line-before duration-300 w-fit"
                                            href={route('pages.contact')}
                                        >
                                            Contact us
                                        </Link>
                                        <Link
                                            className="caption1 has-line-before duration-300 w-fit pt-2"
                                            href={route('account.dashboard')}
                                        >
                                            My Account
                                        </Link>
                                        <Link
                                            className="caption1 has-line-before duration-300 w-fit pt-2"
                                            href={route('order-tracking.index')}
                                        >
                                            Order Tracking
                                        </Link>
                                        <Link
                                            className="caption1 has-line-before duration-300 w-fit pt-2"
                                            href={route('pages.faqs')}
                                        >
                                            FAQs
                                        </Link>
                                    </div>
                                    <div className="item flex flex-col basis-1/3">
                                        <div className="text-button-uppercase pb-3">Quick Shop</div>
                                        {categories.slice(0, 4).map((category) => (
                                            <Link
                                                key={category.slug}
                                                className="caption1 has-line-before duration-300 w-fit pt-2"
                                                href={route('shop.category', category.slug)}
                                            >
                                                {category.name}
                                            </Link>
                                        ))}
                                        <Link
                                            className="caption1 has-line-before duration-300 w-fit pt-2"
                                            href={route('shop.index')}
                                        >
                                            All products
                                        </Link>
                                    </div>
                                    <div className="item flex flex-col basis-1/3">
                                        <div className="text-button-uppercase pb-3">Customer Services</div>
                                        <Link
                                            className="caption1 has-line-before duration-300 w-fit"
                                            href={route('pages.faqs')}
                                        >
                                            FAQs
                                        </Link>
                                        <Link
                                            className="caption1 has-line-before duration-300 w-fit pt-2"
                                            href={route('pages.faqs')}
                                        >
                                            Shipping
                                        </Link>
                                        <Link
                                            className="caption1 has-line-before duration-300 w-fit pt-2"
                                            href={route('pages.faqs')}
                                        >
                                            Returns &amp; Refunds
                                        </Link>
                                        <Link
                                            className="caption1 has-line-before duration-300 w-fit pt-2"
                                            href={route('pages.about')}
                                        >
                                            About us
                                        </Link>
                                    </div>
                                </div>
                                <div className="newsletter basis-1/3 pl-7 max-md:basis-full max-md:pl-0">
                                    <div className="text-button-uppercase">Cash on delivery</div>
                                    <div className="caption1 mt-3">
                                        Pay in cash when your order reaches your door — no card details are ever
                                        collected.
                                    </div>
                                    <div className="list-social flex items-center gap-6 mt-4">
                                        <a href="https://www.facebook.com/" target="_blank" rel="noreferrer">
                                            <div className="icon-facebook text-2xl text-black"></div>
                                        </a>
                                        <a href="https://www.instagram.com/" target="_blank" rel="noreferrer">
                                            <div className="icon-instagram text-2xl text-black"></div>
                                        </a>
                                        <a href="https://www.youtube.com/" target="_blank" rel="noreferrer">
                                            <div className="icon-youtube text-2xl text-black"></div>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="footer-bottom py-3 flex items-center justify-between gap-5 max-lg:justify-center max-lg:flex-col border-t border-line">
                            <div className="left flex items-center gap-8">
                                <div className="copyright caption1 text-secondary">
                                    ©{new Date().getFullYear()} WAQAR. All Rights Reserved.
                                </div>
                            </div>
                            <div className="right flex items-center gap-2">
                                <div className="caption1 text-secondary">Payment: Cash on Delivery only</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <a className="scroll-to-top-btn" href="#top-nav">
                <i className="ph-bold ph-caret-up"></i>
            </a>

            <SearchModal open={searchOpen} onClose={() => setSearchOpen(false)} />
            <MiniCart open={cartOpen} onClose={() => setCartOpen(false)} />
        </>
    );
}
