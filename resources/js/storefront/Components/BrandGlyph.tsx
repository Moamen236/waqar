/** The brand mark and the single و, cut from the logo artwork. */
export const BRAND_MARK = '/storefront/images/about/mark.png';
export const BRAND_WAW = '/storefront/images/about/waw.png';

/**
 * A piece of the brand mark, recoloured by CSS: the PNGs are navy on
 * transparent, so they're used as a mask over `currentColor` and can sit
 * in cream on navy, steel on paper or navy on white without separate files.
 * Colour and size come from `className` (text-* and w-/h-/aspect-*).
 */
export default function BrandGlyph({ src, className }: { src: string; className: string }) {
    const mask = `url(${src}) center / contain no-repeat`;

    return <span aria-hidden="true" className={`block bg-current ${className}`} style={{ mask, WebkitMask: mask }} />;
}
