// Larkon's own status-pill convention (orders-list.html, customer-list.html):
// `badge bg-{color}-subtle text-{color} px-2 py-1`, not react-bootstrap's
// solid <Badge bg="...">.
const VARIANTS: Record<string, string> = {
    New: 'secondary',
    Checking: 'info',
    Confirmed: 'primary',
    Postponed: 'warning',
    Backorder: 'warning',
    Cancelled: 'danger',
    Assigned: 'primary',
    'Out for Delivery': 'info',
    Delivered: 'success',
    'Partially Returned': 'warning',
    Returned: 'danger',
    pending: 'secondary',
    collected: 'success',
    not_collected: 'danger',
    refunded: 'warning',
    active: 'success',
    inactive: 'secondary',
    open: 'warning',
    settled: 'success',
    requested: 'secondary',
    approved: 'info',
    rejected: 'danger',
    received: 'primary',
    inspected: 'primary',
    completed: 'success',
};

export default function StatusBadge({ status }: { status: string }) {
    const color = VARIANTS[status] ?? 'secondary';

    return <span className={`badge bg-${color}-subtle text-${color} px-2 py-1`}>{status}</span>;
}
