import { Link } from '@inertiajs/react';

/**
 * Anvogue's `breadcrumb-block style-shared` header strip, shared by every
 * inner page (cart, login, my-account, order-tracking, …).
 */
export default function Breadcrumb({ title, parent }: { title: string; parent?: { label: string; href: string } }) {
    return (
        <div className="breadcrumb-block style-shared">
            <div className="breadcrumb-main bg-linear overflow-hidden">
                <div className="container lg:pt-[134px] pt-24 pb-10 relative">
                    <div className="main-content w-full h-full flex flex-col items-center justify-center relative z-[1]">
                        <div className="text-content">
                            <div className="heading2 text-center">{title}</div>
                            <div className="link flex items-center justify-center gap-1 caption1 mt-3">
                                <Link href={route('home')}>Homepage</Link>
                                <i className="ph ph-caret-right text-sm text-secondary2"></i>
                                {parent && (
                                    <>
                                        <Link href={parent.href}>{parent.label}</Link>
                                        <i className="ph ph-caret-right text-sm text-secondary2"></i>
                                    </>
                                )}
                                <div className="text-secondary2 capitalize">{title}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
