import React, { useEffect, useState } from 'react';
import * as refundsApi from '../../api/refunds';
import type { Refund } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { EmptyState } from '../../components/common/EmptyState';
import { StatusBadge } from '../../components/common/StatusBadge';
import { Pagination } from '../../components/common/Pagination';
import { usePagination } from '../../hooks/usePagination';
import { formatCurrency, formatDateTime, maskId} from '../../utils/formatters';

export const RefundListPage: React.FC = () => {
  const [refunds, setRefunds] = useState<Refund[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const { page, perPage, setPage } = usePagination();

  useEffect(() => {
    setLoading(true);
    refundsApi.listRefunds({ page, per_page: perPage })
      .then((res) => { setRefunds(res.data); setTotal(res.meta.total); })
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed'))
      .finally(() => setLoading(false));
  }, [page, perPage]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorMessage message={error} />;
  if (refunds.length === 0) return <EmptyState message="No refunds found" />;

  return (
    <div>
      <h1>Refunds</h1>
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead><tr>
          {['ID', 'Amount', 'Reason', 'Status', 'Date'].map(h => <th key={h} style={{ textAlign: 'left', padding: '8px', borderBottom: '2px solid #e0e0e0' }}>{h}</th>)}
        </tr></thead>
        <tbody>
          {refunds.map(r => (
            <tr key={r.id}><td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{maskId(r.id)}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{formatCurrency(r.amount, 'USD')}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{r.reason}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}><StatusBadge status={r.status} /></td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{formatDateTime(r.created_at)}</td></tr>
          ))}
        </tbody>
      </table>
      <Pagination page={page} perPage={perPage} total={total} onPageChange={setPage} />
    </div>
  );
};
