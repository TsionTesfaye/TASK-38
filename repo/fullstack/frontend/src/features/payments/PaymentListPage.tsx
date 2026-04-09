import React, { useEffect, useState } from 'react';
import * as paymentsApi from '../../api/payments';
import type { Payment } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { EmptyState } from '../../components/common/EmptyState';
import { StatusBadge } from '../../components/common/StatusBadge';
import { Pagination } from '../../components/common/Pagination';
import { usePagination } from '../../hooks/usePagination';
import { formatCurrency, formatDateTime, maskId} from '../../utils/formatters';

export const PaymentListPage: React.FC = () => {
  const [payments, setPayments] = useState<Payment[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const { page, perPage, setPage } = usePagination();

  useEffect(() => {
    setLoading(true);
    paymentsApi.listPayments({ page, per_page: perPage })
      .then((res) => { setPayments(res.data); setTotal(res.meta.total); })
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed'))
      .finally(() => setLoading(false));
  }, [page, perPage]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorMessage message={error} />;
  if (payments.length === 0) return <EmptyState message="No payments found" />;

  return (
    <div>
      <h1>Payments</h1>
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead><tr>
          {['ID', 'Amount', 'Status', 'Date'].map(h => <th key={h} style={{ textAlign: 'left', padding: '8px', borderBottom: '2px solid #e0e0e0' }}>{h}</th>)}
        </tr></thead>
        <tbody>
          {payments.map(p => (
            <tr key={p.id}><td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{maskId(p.id)}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{formatCurrency(p.amount, p.currency)}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}><StatusBadge status={p.status} /></td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{formatDateTime(p.created_at)}</td></tr>
          ))}
        </tbody>
      </table>
      <Pagination page={page} perPage={perPage} total={total} onPageChange={setPage} />
    </div>
  );
};
