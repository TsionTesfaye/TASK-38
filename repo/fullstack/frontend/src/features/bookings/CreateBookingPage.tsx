import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import * as inventoryApi from '../../api/inventory';
import type { AvailabilityResult } from '../../api/inventory';
import * as bookingsApi from '../../api/bookings';
import { useHoldTimer } from '../../hooks/useHoldTimer';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { maskId } from '../../utils/formatters';

type Step = 'search' | 'hold' | 'confirmed';

export const CreateBookingPage: React.FC = () => {
  const [searchParams] = useSearchParams();
  const rescheduleBookingId = searchParams.get('reschedule') || '';
  const isReschedule = rescheduleBookingId !== '';

  const [step, setStep] = useState<Step>('search');
  const [itemId, setItemId] = useState('');
  const [units, setUnits] = useState(1);
  const [startAt, setStartAt] = useState('');
  const [endAt, setEndAt] = useState('');
  const [availResult, setAvailResult] = useState<AvailabilityResult | null>(null);
  const [holdId, setHoldId] = useState('');
  const [holdExpiresAt, setHoldExpiresAt] = useState('');
  const [holdRequestKey] = useState(() => crypto.randomUUID());
  const [confirmRequestKey] = useState(() => crypto.randomUUID());
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const navigate = useNavigate();
  const { isExpired, formatted } = useHoldTimer(holdExpiresAt);

  const checkAvail = useCallback(async () => {
    if (!itemId || !startAt || !endAt) return;
    setLoading(true);
    setError(null);
    try {
      const res = await inventoryApi.checkAvailability(itemId, { start_at: startAt, end_at: endAt, units });
      setAvailResult(res);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Availability check failed');
    } finally {
      setLoading(false);
    }
  }, [itemId, startAt, endAt, units]);

  // Auto-check availability with 500ms debounce when inputs change
  const debounceRef = useRef<ReturnType<typeof setTimeout>>(null);
  useEffect(() => {
    if (step !== 'search' || !itemId || !startAt || !endAt) return;
    setAvailResult(null); // Clear stale result while debouncing
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => { checkAvail(); }, 500);
    return () => { if (debounceRef.current) clearTimeout(debounceRef.current); };
  }, [itemId, startAt, endAt, units, step, checkAvail]);

  const createHold = async () => {
    setLoading(true);
    setError(null);
    try {
      const hold = await bookingsApi.createHold({ inventory_item_id: itemId, held_units: units, start_at: startAt, end_at: endAt, request_key: holdRequestKey });
      setHoldId(hold.id);
      setHoldExpiresAt(hold.expires_at);
      setStep('hold');
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to create hold');
    } finally {
      setLoading(false);
    }
  };

  const confirmOrReschedule = async () => {
    setLoading(true);
    setError(null);
    try {
      if (isReschedule) {
        await bookingsApi.reschedule(rescheduleBookingId, { new_hold_id: holdId });
      } else {
        await bookingsApi.confirmHold(holdId, { request_key: confirmRequestKey });
      }
      setStep('confirmed');
      setTimeout(() => navigate('/tenant/bookings'), 2000);
    } catch (err: any) {
      setError(err?.response?.data?.message || (isReschedule ? 'Reschedule failed' : 'Confirmation failed'));
    } finally {
      setLoading(false);
    }
  };

  if (step === 'confirmed') return (
    <div style={{ padding: '40px', textAlign: 'center' }}>
      <h2>{isReschedule ? 'Booking rescheduled!' : 'Booking confirmed!'} Redirecting...</h2>
    </div>
  );

  return (
    <div style={{ maxWidth: '500px' }}>
      <h1>{isReschedule ? 'Reschedule Booking' : 'New Booking'}</h1>
      {isReschedule && (
        <div style={{ marginBottom: '16px', padding: '12px', backgroundColor: '#fff3e0', borderRadius: '8px', fontSize: '14px' }}>
          Rescheduling booking {maskId(rescheduleBookingId)}. Select new dates and reserve.
        </div>
      )}
      {error && <ErrorMessage message={error} />}

      {step === 'search' && (
        <>
          <div style={{ marginBottom: '12px' }}>
            <label style={{ display: 'block', marginBottom: '4px' }}>Inventory Item ID</label>
            <input value={itemId} onChange={e => setItemId(e.target.value)} style={{ width: '100%', padding: '8px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
          </div>
          <div style={{ marginBottom: '12px' }}>
            <label style={{ display: 'block', marginBottom: '4px' }}>Units</label>
            <input type="number" min={1} value={units} onChange={e => setUnits(parseInt(e.target.value) || 1)} style={{ width: '100%', padding: '8px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px', marginBottom: '12px' }}>
            <div>
              <label style={{ display: 'block', marginBottom: '4px' }}>Start</label>
              <input type="datetime-local" value={startAt} onChange={e => setStartAt(e.target.value)} style={{ width: '100%', padding: '8px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
            </div>
            <div>
              <label style={{ display: 'block', marginBottom: '4px' }}>End</label>
              <input type="datetime-local" value={endAt} onChange={e => setEndAt(e.target.value)} style={{ width: '100%', padding: '8px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
            </div>
          </div>
          <button onClick={checkAvail} disabled={loading} style={{ marginRight: '8px', padding: '8px 16px', border: '1px solid #ccc', borderRadius: '4px', cursor: 'pointer' }}>
            {loading ? 'Checking...' : 'Refresh Availability'}
          </button>
          {availResult !== null && (
            <span style={{ color: availResult.can_reserve ? '#2e7d32' : '#d32f2f' }}>
              {availResult.available_units} of {availResult.total_capacity} units available
              {!availResult.can_reserve && availResult.available_units > 0 && (
                <> (need {availResult.requested_units}, only {availResult.available_units} free)</>
              )}
              {availResult.available_units === 0 && ' (fully booked)'}
            </span>
          )}
          {availResult === null && itemId && startAt && endAt && <span style={{ color: '#999', fontSize: '13px' }}>Checking availability...</span>}
          {availResult !== null && availResult.can_reserve && (
            <button onClick={createHold} disabled={loading} style={{ marginTop: '12px', display: 'block', width: '100%', padding: '12px', backgroundColor: '#1976d2', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
              {loading ? <LoadingSpinner /> : `Reserve ${units} unit${units > 1 ? 's' : ''} (10 min hold)`}
            </button>
          )}
        </>
      )}

      {step === 'hold' && (
        <div>
          <div style={{ textAlign: 'center', padding: '20px', marginBottom: '16px', backgroundColor: isExpired ? '#ffebee' : '#e8f5e9', borderRadius: '8px' }}>
            <div style={{ fontSize: '14px', color: '#666', marginBottom: '8px' }}>Hold Timer (informational only)</div>
            <div style={{ fontSize: '36px', fontWeight: 'bold', color: isExpired ? '#d32f2f' : '#2e7d32' }}>{formatted}</div>
            {isExpired && <div style={{ color: '#d32f2f', marginTop: '8px' }}>Hold may have expired. Confirmation is server-validated.</div>}
          </div>
          <div style={{ marginBottom: '12px', padding: '12px', backgroundColor: '#fff8e1', borderRadius: '8px', fontSize: '13px', lineHeight: '1.6' }}>
            <strong>Booking Policy:</strong>
            <ul style={{ margin: '4px 0 0 16px', padding: 0 }}>
              <li>Free cancellation up to 24 hours before start time</li>
              <li>Late cancellation: 20% fee applies</li>
              <li>No-show penalty: 50% + first day rent</li>
            </ul>
            <div style={{ marginTop: '4px', fontSize: '12px', color: '#666' }}>By confirming, you acknowledge these terms.</div>
          </div>
          <button onClick={confirmOrReschedule} disabled={loading || isExpired} style={{ width: '100%', padding: '12px', backgroundColor: isExpired ? '#9e9e9e' : '#2e7d32', color: '#fff', border: 'none', borderRadius: '4px', cursor: loading || isExpired ? 'not-allowed' : 'pointer', fontSize: '16px' }}>
            {loading ? 'Processing...' : isExpired ? 'Hold Expired' : (isReschedule ? 'Confirm Reschedule' : 'Confirm Booking')}
          </button>
        </div>
      )}
    </div>
  );
};
