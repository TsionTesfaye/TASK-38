import React, { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import * as billingApi from '../../api/billing';
import * as paymentsApi from '../../api/payments';
import type { Bill } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { formatCurrency, maskId} from '../../utils/formatters';

export const PaymentInitiatePage: React.FC = () => {
  const { id: billId } = useParams<{ id: string }>();
  const [bill, setBill] = useState<Bill | null>(null);
  const [amount, setAmount] = useState('');
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);
  const navigate = useNavigate();

  useEffect(() => {
    if (!billId) return;
    billingApi.getBill(billId)
      .then((res) => {
        setBill(res);
        setAmount(res.outstanding_amount);
      })
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed to load bill'))
      .finally(() => setLoading(false));
  }, [billId]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!bill || !billId) return;
    setError(null);
    setSubmitting(true);
    try {
      await paymentsApi.initiatePayment({ bill_id: billId, amount, currency: bill.currency });
      setSuccess(true);
      setTimeout(() => navigate('/tenant/bills'), 2000);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Payment failed');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) return <LoadingSpinner />;
  if (!bill) return <ErrorMessage message="Bill not found" />;

  if (success) return (
    <div style={{ padding: '40px', textAlign: 'center' }}>
      <h2 style={{ color: '#2e7d32' }}>Payment Initiated</h2>
      <p>Your payment is being processed. Redirecting...</p>
    </div>
  );

  const canPay = bill.status === 'open' || bill.status === 'partially_paid';

  return (
    <div style={{ maxWidth: '500px' }}>
      <h1>Pay Bill</h1>
      {error && <ErrorMessage message={error} />}
      {!canPay && <ErrorMessage message="This bill cannot accept payments in its current status." />}
      <div style={{ marginBottom: '20px', padding: '16px', backgroundColor: '#f5f5f5', borderRadius: '8px' }}>
        <div><strong>Bill:</strong> ...{maskId(bill.id)}</div>
        <div><strong>Type:</strong> {bill.bill_type}</div>
        <div><strong>Original Amount:</strong> {formatCurrency(bill.original_amount, bill.currency)}</div>
        <div><strong>Outstanding:</strong> {formatCurrency(bill.outstanding_amount, bill.currency)}</div>
        <div><strong>Currency:</strong> {bill.currency}</div>
      </div>
      {canPay && (
        <form onSubmit={handleSubmit}>
          <div style={{ marginBottom: '16px' }}>
            <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>Payment Amount</label>
            <input type="text" value={amount} onChange={e => setAmount(e.target.value)} required
              style={{ width: '100%', padding: '10px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box', fontSize: '18px' }} />
          </div>
          <button type="submit" disabled={submitting}
            style={{ width: '100%', padding: '12px', backgroundColor: '#2e7d32', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer', fontSize: '16px' }}>
            {submitting ? 'Processing...' : `Pay ${amount} ${bill.currency}`}
          </button>
        </form>
      )}
    </div>
  );
};
