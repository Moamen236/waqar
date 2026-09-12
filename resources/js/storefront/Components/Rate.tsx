/** Anvogue's `.rate` star row (product-default.html), driven by a real average. */
export default function Rate({ value, size = 'text-sm' }: { value: number; size?: string }) {
    return (
        <div className="rate flex">
            {[1, 2, 3, 4, 5].map((star) => (
                <i
                    key={star}
                    className={`ph-fill ph-star ${size} ${star <= Math.round(value) ? 'text-yellow' : 'text-secondary2'}`}
                ></i>
            ))}
        </div>
    );
}
