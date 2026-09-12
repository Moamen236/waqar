import { Head, Link } from '@inertiajs/react';
import AccountNav from '../../Components/AccountNav';
import Breadcrumb from '../../Components/Breadcrumb';
import ProductCard from '../../Components/ProductCard';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import type { ProductCardData } from '../../types';

/**
 * Wishlist — Anvogue's wishlist.html, rendered inside the account shell
 * so it sits alongside the other tabs the spec lists under Customer
 * Account (Section 13) rather than as an orphan page.
 */
export default function WishlistIndex({ products }: { products: ProductCardData[] }) {
    return (
        <StorefrontLayout>
            <Head title="Wishlist" />
            <Breadcrumb title="Wishlist" />

            <div className="my-account-block md:py-20 py-10">
                <div className="container">
                    <div className="content-main lg:px-[60px] md:px-4 flex gap-y-8 max-md:flex-col w-full">
                        <AccountNav active="wishlist" />
                        <div className="right list-filter md:w-2/3 w-full pl-2.5">
                            <div className="text-content w-full p-7 border border-line rounded-xl">
                                <h6 className="heading6">Your wishlist</h6>
                                {products.length === 0 && (
                                    <div className="caption1 text-secondary mt-4">
                                        Nothing saved yet.{' '}
                                        <Link href={route('shop.index')} className="text-black underline">
                                            Browse the shop
                                        </Link>
                                    </div>
                                )}
                                <div className="list-product hide-product-sold grid sm:grid-cols-3 grid-cols-2 gap-5 mt-5">
                                    {products.map((product) => (
                                        <ProductCard key={product.id} product={product} />
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
