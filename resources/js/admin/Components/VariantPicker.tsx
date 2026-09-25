import { useEffect, useState } from 'react';
import AsyncSelect from 'react-select/async';
import { useTranslation } from '../lib/useTranslation';

interface VariantOptionValue {
    attribute: string;
    attribute_label: string;
    value: string;
    hex: string | null;
}

export interface PickerVariant {
    id: number;
    sku: string;
    price: number;
    /** null when the product is not inventory-tracked (advertisement items). */
    available: number | null;
    options: VariantOptionValue[];
}

interface PickerProduct {
    id: number;
    name: string;
    sku: string;
    price: number;
    tracked: boolean;
    colors: { name: string; hex: string | null }[];
    sizes: string[];
    variants: PickerVariant[];
}

/**
 * Search a product by name, then pick its colour and size — the same two
 * steps a customer takes on the storefront, which is what staff are reading
 * out over the phone. Used for an order line and for a return's replacement.
 *
 * `searchUrl` answers `?q=` with `{ products: ProductPresenter::picker()[] }`;
 * each screen passes its own endpoint so the search is gated by that
 * screen's permission, not someone else's.
 *
 * Replaces a flat dropdown of every SKU in the catalogue. Staff know the
 * product and the colour the customer asked for; they do not know
 * "WQ-4471-V".
 */
export default function VariantPicker({
    searchUrl,
    onResolve,
}: {
    searchUrl: string;
    onResolve: (variant: PickerVariant | null) => void;
}) {
    const { t, price } = useTranslation();
    const [product, setProduct] = useState<PickerProduct | null>(null);
    const [colour, setColour] = useState<string | null>(null);
    const [size, setSize] = useState<string | null>(null);

    const optionOf = (variant: PickerVariant, attribute: string) =>
        variant.options.find((option) => option.attribute === attribute)?.value ?? null;

    const hasColours = (product?.colors.length ?? 0) > 0;
    const hasSizes = (product?.sizes.length ?? 0) > 0;

    // A variant is only resolved once every axis the product actually
    // differentiates on has been chosen — a product with one variant and
    // no options resolves immediately.
    const variant =
        product === null
            ? null
            : (product.variants.find(
                  (candidate) =>
                      (!hasColours || optionOf(candidate, 'color') === colour) &&
                      (!hasSizes || optionOf(candidate, 'size') === size),
              ) ??
              (product.variants.length === 1 ? product.variants[0] : null) ??
              null);

    useEffect(() => {
        onResolve(variant ?? null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [variant?.id]);

    async function search(term: string): Promise<{ value: number; label: string; product: PickerProduct }[]> {
        if (term.trim() === '') return [];

        const response = await fetch(`${searchUrl}?q=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) return [];

        const body: { products: PickerProduct[] } = await response.json();

        return body.products.map((item) => ({
            value: item.id,
            label: `${item.name} — ${item.sku}`,
            product: item,
        }));
    }

    return (
        <div className="d-flex flex-column gap-2">
            <AsyncSelect
                cacheOptions
                defaultOptions={false}
                loadOptions={search}
                placeholder={t('admin.searchProduct')}
                noOptionsMessage={() => t('admin.typeToSearchProducts')}
                onChange={(option) => {
                    setProduct(option?.product ?? null);
                    setColour(null);
                    setSize(null);
                }}
            />

            {hasColours && (
                <div className="d-flex align-items-center gap-1 flex-wrap">
                    <span className="fs-12 text-muted me-1">{t('admin.color')}:</span>
                    {product!.colors.map((option) => (
                        <button
                            key={option.name}
                            type="button"
                            title={option.name}
                            aria-label={option.name}
                            aria-pressed={colour === option.name}
                            className={`btn btn-sm p-0 border rounded-circle ${
                                colour === option.name ? 'border-dark border-2' : ''
                            }`}
                            style={{
                                width: 26,
                                height: 26,
                                // No hex on the value (an unswatched colour):
                                // fall back to the plain name as a chip so it
                                // is still selectable.
                                backgroundColor: option.hex ?? 'transparent',
                            }}
                            onClick={() => setColour(option.name)}
                        >
                            {option.hex === null && <span className="fs-11">{option.name.slice(0, 2)}</span>}
                        </button>
                    ))}
                </div>
            )}

            {hasSizes && (
                <div className="d-flex align-items-center gap-1 flex-wrap">
                    <span className="fs-12 text-muted me-1">{t('admin.size')}:</span>
                    {product!.sizes.map((option) => (
                        <button
                            key={option}
                            type="button"
                            aria-pressed={size === option}
                            className={`btn btn-sm ${size === option ? 'btn-dark' : 'btn-soft-secondary'}`}
                            onClick={() => setSize(option)}
                        >
                            {option}
                        </button>
                    ))}
                </div>
            )}

            {product !== null && variant === null && (
                <div className="fs-12 text-warning">{t('admin.chooseEveryOption')}</div>
            )}

            {variant !== null && (
                <div className="fs-12 text-muted">
                    <span dir="ltr">{variant.sku}</span>
                    {variant.available !== null && (
                        <span className={variant.available > 0 ? ' text-success' : ' text-danger'}>
                            {' · '}
                            {variant.available > 0
                                ? t('admin.nInStock', { count: variant.available })
                                : t('admin.outOfStock')}
                        </span>
                    )}
                    {' · '}
                    <span dir="ltr">{price(variant.price)}</span>
                </div>
            )}
        </div>
    );
}
