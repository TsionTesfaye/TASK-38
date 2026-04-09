import React, { useState } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import * as billingApi from '../../api/billing';
import { ErrorMessage } from '../../components/common/ErrorMessage';

export const SupplementalBillPage: React.FC = () => {
  const [searchParams] = useSearchParams();
  const bookingId = searchParams.get('booking_id') || '';
  const [form, setForm] = useState({ booking_id: bookingId, amount: '', reason: '' });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);
  const navigate = useNavigate();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.booking_id || !form.amount || !form.reason) {
      setError('All fields are required');
      return;
    }
    setError(null);
    setLoading(true);
    try {
      await billingApi.createSupplementalBill({
        booking_id: form.booking_id,
        amount: form.amount,
        reason: form.reason,
      });
      setSuccess(true);
      setTimeout(() => navigate('/finance/bills'), 2000);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to create bill');
    } finally {
      setLoading(false);
    }
  };

  if (success) return (
    <div style={{ padding: '40px', textAlign: 'center' }}>
      <h2 style={{ color: '#2e7d32' }}>Supplemental Bill Created</h2>
      <p>Redirecting to bills...</p>
    </div>
  );

  return (
    <div style={{ maxWidth: '500px' }}>
      <h1>Create Supplemental Bill</h1>
      {error && <ErrorMessage message={error} />}
      <form onSubmit={handleSubmit}>
        <div style={{ marginBottom: '12px' }}>
          <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>Booking ID</label>
          <input value={form.booking_id} onChange={e => setForm({ ...form, booking_id: e.target.value })} required
            style={{ width: '100%', padding: '10px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
        </div>
        <div style={{ marginBottom: '12px' }}>
          <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>Amount</label>
          <input type="text" value={form.amount} onChange={e => setForm({ ...form, amount: e.target.value })} required placeholder="0.00"
            style={{ width: '100%', padding: '10px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
        </div>
        <div style={{ marginBottom: '16px' }}>
          <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>Reason</label>
          <textarea value={form.reason} onChange={e => setForm({ ...form, reason: e.target.value })} required rows={3}
            style={{ width: '100%', padding: '10px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
        </div>
        <button type="submit" disabled={loading}
          style={{ padding: '10px 24px', backgroundColor: '#1976d2', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
          {loading ? 'Creating...' : 'Create Supplemental Bill'}
        </button>
      </form>
    </div>
  );
};
