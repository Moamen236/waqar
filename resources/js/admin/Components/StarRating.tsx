import { useTranslation } from '../lib/useTranslation';

/** A 1–5 rating as stars, read out as "4 of 5" rather than five icons. */
export default function StarRating({ rating, size = 'fs-14' }: { rating: number; size?: string }) {
    const { t } = useTranslation();
    const rounded = Math.round(rating);

    return (
        <ul
            className={`d-inline-flex text-warning m-0 list-unstyled ${size}`}
            role="img"
            aria-label={t('admin.ratingOutOf5', { rating })}
        >
            {[1, 2, 3, 4, 5].map((star) => (
                <li key={star} aria-hidden="true">
                    <i className={`bx ${star <= rounded ? 'bxs-star' : 'bx-star'}`} />
                </li>
            ))}
        </ul>
    );
}
