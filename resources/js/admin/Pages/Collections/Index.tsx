import { Head, Link, router } from '@inertiajs/react';
import { confirmAction } from '../../lib/confirm';
import { PaginationFooter } from '../../Components/Pagination';
import RowActions from '../../Components/RowActions';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import type { PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface CollectionRecord {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    products_count: number;
}

export default function CollectionsIndex({ collections }: { collections: PaginatedData<CollectionRecord> }) {
    const { t } = useTranslation();
    const { can } = usePermissions();

    async function remove(id: number, name: string) {
        if (
            !(await confirmAction({
                title: t('admin.deleteConfirmQ', { name }),
                confirmText: t('admin.delete'),
                danger: true,
            }))
        ) {
            return;
        }

        router.delete(route('admin.collections.destroy', id), { preserveScroll: true });
    }

    return (
        <AdminLayout title={t('admin.collections')}>
            <Head title={t('admin.collections')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">{t('admin.allCollections')}</h4>
                            {can('collections.create') && (
                                <Link href={route('admin.collections.create')} className="btn btn-sm btn-primary">
                                    {t('admin.addCollection')}
                                </Link>
                            )}
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.name')}</th>
                                        <th>{t('admin.slug')}</th>
                                        <th>{t('admin.products')}</th>
                                        <th>{t('admin.status')}</th>
                                        <th>{t('admin.action')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {collections.data.map((collection) => (
                                        <tr key={collection.id}>
                                            <td className="fw-medium">{collection.name}</td>
                                            <td className="text-muted">{collection.slug}</td>
                                            <td>{collection.products_count}</td>
                                            <td>
                                                <StatusBadge status={collection.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td>
                                                <RowActions
                                                    editHref={
                                                        can('collections.update')
                                                            ? route('admin.collections.edit', collection.id)
                                                            : undefined
                                                    }
                                                    onDelete={
                                                        can('collections.delete')
                                                            ? () => remove(collection.id, collection.name)
                                                            : undefined
                                                    }
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                    {collections.data.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="text-center text-muted py-4">
                                                {t('admin.noCollectionsYet')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <PaginationFooter data={collections} />
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
