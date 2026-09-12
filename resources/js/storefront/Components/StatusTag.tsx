/**
 * my-account.html's `tag px-4 py-1.5 rounded-full …` pill, mapped onto
 * the real customer-facing statuses (Section 03) rather than the
 * template's Pending/Delivery/Completed/Canceled demo set. Written as
 * `bg-<color>/10` rather than the template's `bg-opacity-10` — that
 * utility was removed in Tailwind v4.
 */
const colors: Record<string, string> = {
    'Order Received': 'bg-yellow/10 text-yellow',
    Processing: 'bg-yellow/10 text-yellow',
    Shipping: 'bg-purple/10 text-purple',
    'Out for Delivery': 'bg-purple/10 text-purple',
    Delivered: 'bg-success/10 text-success',
    Cancelled: 'bg-red/10 text-red',
    Returned: 'bg-red/10 text-red',
    'Partially Returned': 'bg-red/10 text-red',
    Postponed: 'bg-secondary/10 text-secondary',
    Backordered: 'bg-secondary/10 text-secondary',
};

export default function StatusTag({ status }: { status: string }) {
    return (
        <span
            className={`tag px-4 py-1.5 rounded-full caption1 font-semibold ${
                colors[status] ?? 'bg-secondary/10 text-secondary'
            }`}
        >
            {status}
        </span>
    );
}
