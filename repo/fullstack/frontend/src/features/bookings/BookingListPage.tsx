import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import * as bookingsApi from '../../api/bookings';
import type { Booking } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { EmptyState } from '../../components/common/EmptyState';
import { StatusBadge } from '../../components/common/StatusBadge';
import { Pagination } from '../../components/common/Pagination';
import { usePagination } from '../../hooks/usePagination';
import { formatDateTime, formatCurrency, maskId} from '../../utils/formatters';

export const BookingListPage: React.FC = () => {
  const [bookings, setBookings] = useState<Booking[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const { page, perPage, setPage } = usePagination();
  const navigate = useNavigate();

  useEffect(() => {
    setLoading(true);
    bookingsApi.listBookings({ page, per_page: perPage })
      .then((res) => { setBookings(res.data); setTotal(res.meta.total); })
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed to load bookings'))
      .finally(() => setLoading(false));
  }, [page, perPage]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorMessage message={error} />;
  if (bookings.length === 0) return <EmptyState message="No bookings found" />;

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
        <h1>Bookings</h1>
      </div>
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead><tr>
          <th style={{ textAlign: 'left', padding: '8px', borderBottom: '2px solid #e0e0e0' }}>ID</th>
          <th style={{ textAlign: 'left', padding: '8px', borderBottom: '2px solid #e0e0e0' }}>Start</th>
          <th style={{ textAlign: 'left', padding: '8px', borderBottom: '2px solid #e0e0e0' }}>End</th>
          <th style={{ textAlign: 'left', padding: '8px', borderBottom: '2px solid #e0e0e0' }}>Amount</th>
          <th style={{ textAlign: 'left', padding: '8px', borderBottom: '2px solid #e0e0e0' }}>Status</th>
        </tr></thead>
        <tbody>
          {bookings.map(b => (
            <tr key={b.id} onClick={() => navigate(b.id)} style={{ cursor: 'pointer' }}>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{maskId(b.id)}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{formatDateTime(b.start_at)}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{formatDateTime(b.end_at)}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{formatCurrency(b.final_amount, b.currency)}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}><StatusBadge status={b.status} /></td>
            </tr>
          ))}
        </tbody>
      </table>
      <Pagination page={page} perPage={perPage} total={total} onPageChange={setPage} />
    </div>
  );
};
