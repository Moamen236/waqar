import { useTranslation } from '../lib/useTranslation';

export interface Instalment {
    id: number;
    amount: string;
    description: string | null;
    created_at: string;
    treasury: { id: number; name: string } | null;
    created_by: { id: number; full_name: string } | null;
}

/**
 * How an order actually got paid: one row per collection banked against
 * its payment. A COD order usually settles in a single row; one the
 * courier came back short on shows the balance arriving afterwards.
 *
 * Read from the treasury ledger, not from the payment's own running
 * total — the ledger is what the money moved through, so a row here is a
 * row in a treasury.
 */
export default function PaymentInstalments({ instalments }: { instalments: Instalment[] }) {
    const { t, price, dateTime } = useTranslation();

    if (instalments.length === 0) {
        return <p className="text-muted fs-13 mb-0">{t('admin.noCollectionsRecorded')}</p>;
    }

    return (
        <div className="table-responsive">
            <table className="table table-sm align-middle mb-0">
                <thead className="bg-light-subtle">
                    <tr>
                        <th>{t('admin.date')}</th>
                        <th>{t('admin.amount')}</th>
                        <th>{t('admin.treasury')}</th>
                        <th>{t('admin.by')}</th>
                    </tr>
                </thead>
                <tbody>
                    {instalments.map((instalment) => (
                        <tr key={instalment.id}>
                            <td className="text-muted fs-13">
                                <span dir="ltr" className="text-nowrap">
                                    {dateTime(instalment.created_at)}
                                </span>
                            </td>
                            <td className="fw-medium">
                                <span dir="ltr" className="text-nowrap">
                                    {price(Number(instalment.amount))}
                                </span>
                            </td>
                            <td className="text-muted">{instalment.treasury?.name ?? '—'}</td>
                            <td className="text-muted fs-13">{instalment.created_by?.full_name ?? '—'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
