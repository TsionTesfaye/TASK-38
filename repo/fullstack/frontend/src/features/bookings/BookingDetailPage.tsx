import React, { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import * as bookingsApi from '../../api/bookings';
import type { Booking } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { StatusBadge } from '../../components/common/StatusBadge';
import { ConfirmDialog } from '../../components/common/ConfirmDialog';
import { useAuthStore } from '../../state/authStore';
import { UserRole, BookingStatus } from '../../types/enums';
import { formatDateTime, formatCurrency, maskId} from '../../utils/formatters';

export const BookingDetailPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const [booking, setBooking] = useState<Booking | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [confirmAction, setConfirmAction] = useState<string | null>(null);
  const { user } = useAuthStore();
  const navigate = useNavigate();

  const load = () => {
    if (!id) return;
    setLoading(true);
    bookingsApi.getBooking(id)
      .then((res) => setBooking(res))
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed to load'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, [id]);

  const handleAction = async (action: string) => {
    if (!id) return;
    try {
      if (action === 'cancel') await bookingsApi.cancel(id);
      else if (action === 'check-in') await bookingsApi.checkIn(id);
      else if (action === 'complete') await bookingsApi.complete(id);
      else if (action === 'no-show') await bookingsApi.markNoShow(id);
      setConfirmAction(null);
      load();
    } catch (err: any) {
      setError(err?.response?.data?.message || `Failed to ${action}`);
      setConfirmAction(null);
    }
  };

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorMessage message={error} />;
  if (!booking) return <ErrorMessage message="Booking not found" />;

  const isManager = user?.role === UserRole.PROPERTY_MANAGER || user?.role === UserRole.ADMINISTRATOR;
  const isTenant = user?.role === UserRole.TENANT;
  const canCancel = [BookingStatus.CONFIRMED, BookingStatus.ACTIVE].includes(booking.status as any);
  const canCheckIn = isManager && booking.status === BookingStatus.CONFIRMED;
  const canComplete = isManager && booking.status === BookingStatus.ACTIVE;
  const canMarkNoShow = isManager && booking.status === BookingStatus.ACTIVE && !booking.checked_in_at;
  const canReschedule = isTenant && booking.status === BookingStatus.CONFIRMED;

  return (
    <div>
      <h1>Booking Details</h1>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', maxWidth: '600px', marginBottom: '24px' }}>
        <div><strong>ID:</strong> {maskId(booking.id)}</div>
        <div><strong>Status:</strong> <StatusBadge status={booking.status} /></div>
        <div><strong>Start:</strong> {formatDateTime(booking.start_at)}</div>
        <div><strong>End:</strong> {formatDateTime(booking.end_at)}</div>
        <div><strong>Units:</strong> {booking.booked_units}</div>
        <div><strong>Amount:</strong> {formatCurrency(booking.final_amount, booking.currency)}</div>
        {booking.cancellation_fee_amount !== '0.00' && <div><strong>Cancel Fee:</strong> {formatCurrency(booking.cancellation_fee_amount, booking.currency)}</div>}
        {booking.no_show_penalty_amount !== '0.00' && <div><strong>No-Show Penalty:</strong> {formatCurrency(booking.no_show_penalty_amount, booking.currency)}</div>}
        {booking.checked_in_at && <div><strong>Checked In:</strong> {formatDateTime(booking.checked_in_at)}</div>}
      </div>
      <div style={{ display: 'flex', gap: '8px' }}>
        {canCancel && (isTenant || isManager) && <button onClick={() => setConfirmAction('cancel')} style={{ padding: '8px 16px', backgroundColor: '#d32f2f', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>Cancel Booking</button>}
        {canCheckIn && <button onClick={() => setConfirmAction('check-in')} style={{ padding: '8px 16px', backgroundColor: '#2e7d32', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>Check In</button>}
        {canComplete && <button onClick={() => setConfirmAction('complete')} style={{ padding: '8px 16px', backgroundColor: '#1976d2', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>Complete</button>}
        {canMarkNoShow && <button onClick={() => setConfirmAction('no-show')} style={{ padding: '8px 16px', backgroundColor: '#f57c00', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>Mark No-Show</button>}
        {canReschedule && <button onClick={() => navigate(`/tenant/bookings/new?reschedule=${booking.id}`)} style={{ padding: '8px 16px', backgroundColor: '#7b1fa2', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>Reschedule</button>}
      </div>
      <ConfirmDialog
        open={confirmAction !== null}
        title={`Confirm ${confirmAction ?? ''}`}
        message={
          confirmAction === 'cancel'
            ? `Are you sure you want to cancel this booking? If cancellation is less than 24 hours before the start time, a cancellation fee will apply.`
            : confirmAction === 'no-show'
            ? `Mark this booking as no-show? A penalty fee will be charged to the tenant.`
            : `Are you sure you want to ${confirmAction ?? ''} this booking?`
        }
        variant={confirmAction === 'cancel' || confirmAction === 'no-show' ? 'danger' : 'default'}
        onConfirm={() => { if (confirmAction) handleAction(confirmAction); }}
        onCancel={() => setConfirmAction(null)}
      />
    </div>
  );
};
