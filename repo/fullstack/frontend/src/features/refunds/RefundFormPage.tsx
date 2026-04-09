import React, { useEffect, useState } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import * as billingApi from '../../api/billing';
import * as refundsApi from '../../api/refunds';
import type { Bill } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { formatCurrency, maskId} from '../../utils/formatters';

export const RefundFormPage: React.FC = () => {
  const [searchParams] = useSearchParams();
  const billId = searchParams.get('bill_id') || '';
  const [bill, setBill] = useState<Bill | null>(null);
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);
  const navigate = useNavigate();

  useEffect(() => {
    if (!billId) { setLoading(false); return; }
    billingApi.getBill(billId)
      .then((res) => setBill(res))
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed to load bill'))
      .finally(() => setLoading(false));
  }, [billId]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!billId) return;
    setError(null);
    setSubmitting(true);
    try {
      await refundsApi.issueRefund({ bill_id: billId, amount, reason });
      setSuccess(true);
      setTimeout(() => navigate('/finance/refunds'), 2000);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Refund failed');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) return <LoadingSpinner />;
  if (!billId) return <ErrorMessage message="No bill_id provided" />;

  if (success) return (
    <div style={{ padding: '40px', textAlign: 'center' }}>
      <h2 style={{ color: '#2e7d32' }}>Refund Issued</h2>
      <p>Redirecting...</p>
    </div>
  );

  return (
    <div style={{ maxWidth: '500px' }}>
      <h1>Issue Refund</h1>
      {error && <ErrorMessage message={error} />}
      {bill && (
        <div style={{ marginBottom: '20px', padding: '16px', backgroundColor: '#f5f5f5', borderRadius: '8px' }}>
          <div><strong>Bill:</strong> ...{maskId(bill.id)}</div>
          <div><strong>Original:</strong> {formatCurrency(bill.original_amount, bill.currency)}</div>
          <div><strong>Outstanding:</strong> {formatCurrency(bill.outstanding_amount, bill.currency)}</div>
          <div><strong>Status:</strong> {bill.status}</div>
        </div>
      )}
      <form onSubmit={handleSubmit}>
        <div style={{ marginBottom: '12px' }}>
          <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>Refund Amount</label>
          <input type="text" value={amount} onChange={e => setAmount(e.target.value)} required placeholder="0.00"
            style={{ width: '100%', padding: '10px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
        </div>
        <div style={{ marginBottom: '16px' }}>
          <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>Reason</label>
          <textarea value={reason} onChange={e => setReason(e.target.value)} required rows={3}
            style={{ width: '100%', padding: '10px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
        </div>
        <div style={{ display: 'flex', gap: '8px' }}>
          <button type="submit" disabled={submitting}
            style={{ padding: '10px 24px', backgroundColor: '#d32f2f', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
            {submitting ? 'Processing...' : 'Issue Refund'}
          </button>
          <button type="button" onClick={() => navigate(-1)}
            style={{ padding: '10px 24px', border: '1px solid #ccc', borderRadius: '4px', cursor: 'pointer', background: '#fff' }}>
            Cancel
          </button>
        </div>
      </form>
    </div>
  );
};
