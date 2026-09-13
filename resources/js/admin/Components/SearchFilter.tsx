import { type FormEvent, type ReactNode, useState } from 'react';

/**
 * Larkon's in-card search field, ported from
 * Admin Template/apps-ecommerce-product-list.html: a `.search-bar`
 * wrapper positioning a `bx-search-alt` glyph over a plain
 * `form-control`. The template's own stylesheet mirrors the glyph's
 * offset under RTL (`app-rtl.min.css` flips `.search-bar span` to
 * `right`), so the markup is direction-agnostic as written.
 *
 * Every list page had been rolling its own bare `<input
 * class="form-control form-control-sm">` in the card header, which is
 * why search looked different on each screen. This keeps the submit
 * behaviour each page already had — the caller still owns the router
 * visit, its parameter names and its `preserveState` choice — and only
 * unifies the presentation.
 */
export default function SearchFilter({
    value,
    placeholder,
    onSubmit,
    children,
    size = 'sm',
}: {
    value: string;
    placeholder: string;
    /** Called with the current field value when the form is submitted. */
    onSubmit: (value: string) => void;
    /** Extra controls (selects, date pickers) rendered beside the field. */
    children?: ReactNode;
    size?: 'sm' | 'md';
}) {
    const [term, setTerm] = useState(value);
    const [lastValue, setLastValue] = useState(value);

    // The server is the source of truth for what is actually filtered, so
    // the field re-syncs when a visit lands with a different term (a
    // back/forward navigation, or a sibling filter select re-issuing the
    // query). Adjusting state *during* render is React's documented way to
    // do that — an effect would set state after paint, rendering the stale
    // term for a frame and tripping react-hooks/set-state-in-effect.
    if (value !== lastValue) {
        setLastValue(value);
        setTerm(value);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        onSubmit(term);
    }

    return (
        <div className="d-flex flex-wrap align-items-center gap-2">
            {children}
            <form onSubmit={submit} role="search">
                <div className="search-bar">
                    <span>
                        <i className="bx bx-search-alt" />
                    </span>
                    <input
                        type="search"
                        className={`form-control${size === 'sm' ? ' form-control-sm' : ''}`}
                        placeholder={placeholder}
                        aria-label={placeholder}
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                    />
                </div>
            </form>
        </div>
    );
}
