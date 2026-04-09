import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import * as billingApi from '../../api/billing';
import type { Bill } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { EmptyState } from '../../components/common/EmptyState';
import { StatusBadge } from '../../components/common/StatusBadge';
import { Pagination } from '../../components/common/Pagination';
import { usePagination } from '../../hooks/usePagination';
import { formatCurrency, maskId} from '../../utils/formatters';

export const BillListPage: React.FC = () => {
  const [bills, setBills] = useState<Bill[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const { page, perPage, setPage } = usePagination();
  const navigate = useNavigate();

  useEffect(() => {
    setLoading(true);
    billingApi.listBills({ page, per_page: perPage })
      .then((res) => { setBills(res.data); setTotal(res.meta.total); })
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed to load'))
      .finally(() => setLoading(false));
  }, [page, perPage]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorMessage message={error} />;
  if (bills.length === 0) return <EmptyState message="No bills found" />;

  return (
    <div>
      <h1>Bills</h1>
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead><tr>
          {['ID', 'Type', 'Amount', 'Outstanding', 'Status'].map(h => (
            <th key={h} style={{ textAlign: 'left', padding: '8px', borderBottom: '2px solid #e0e0e0' }}>{h}</th>
          ))}
        </tr></thead>
        <tbody>
          {bills.map(b => (
            <tr key={b.id} onClick={() => navigate(b.id)} style={{ cursor: 'pointer' }}>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{maskId(b.id)}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{b.bill_type}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{formatCurrency(b.original_amount, b.currency)}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{formatCurrency(b.outstanding_amount, b.currency)}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}><StatusBadge status={b.status} /></td>
            </tr>
          ))}
        </tbody>
      </table>
      <Pagination page={page} perPage={perPage} total={total} onPageChange={setPage} />
    </div>
  );
};
