import { Head } from '@inertiajs/react';
import AccountNav from '../../Components/AccountNav';
import Breadcrumb from '../../Components/Breadcrumb';
import ProductCard from '../../Components/ProductCard';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import type { ProductCardData } from '../../types';

/**
 * The Recently Viewed tab — required by the spec, absent from the
 * template (Section 13, Section 20 #16). Backed by a cookie rather than
 * a table (Section 24 defines none), so it also works for browsing done
 * before signing in.
 */
export default function AccountRecentlyViewed({ products }: { products: ProductCardData[] }) {
    return (
        <StorefrontLayout>
            <Head title="Recently Viewed" />
            <Breadcrumb title="Recently Viewed" />

            <div className="my-account-block md:py-20 py-10">
                <div className="container">
                    <div className="content-main lg:px-[60px] md:px-4 flex gap-y-8 max-md:flex-col w-full">
                        <AccountNav active="recently-viewed" />
                        <div className="right list-filter md:w-2/3 w-full ps-2.5">
                            <div className="text-content w-full p-7 border border-line rounded-xl">
                                <h6 className="heading6">Recently viewed</h6>
                                {products.length === 0 && (
                                    <div className="caption1 text-secondary mt-4">
                                        Nothing yet — products you open will show up here.
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
