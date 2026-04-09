import React, { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import * as billingApi from '../../api/billing';
import type { Bill } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { StatusBadge } from '../../components/common/StatusBadge';
import { useAuthStore } from '../../state/authStore';
import { UserRole } from '../../types/enums';
import { formatCurrency, formatDateTime, maskId} from '../../utils/formatters';

export const BillDetailPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const [bill, setBill] = useState<Bill | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const navigate = useNavigate();
  const { user } = useAuthStore();

  useEffect(() => {
    if (!id) return;
    billingApi.getBill(id)
      .then((res) => setBill(res))
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed'))
      .finally(() => setLoading(false));
  }, [id]);

  const handleDownloadPdf = async () => {
    if (!id) return;
    try {
      const blob = await billingApi.downloadPdf(id);
      const url = URL.createObjectURL(blob as Blob);
      const a = document.createElement('a'); a.href = url; a.download = `bill_${maskId(id || '')}.pdf`; a.click();
    } catch { setError('PDF download failed'); }
  };

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorMessage message={error} />;
  if (!bill) return <ErrorMessage message="Bill not found" />;

  return (
    <div>
      <h1>Bill Details</h1>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', maxWidth: '600px', marginBottom: '24px' }}>
        <div><strong>ID:</strong> {maskId(bill.id)}</div>
        <div><strong>Status:</strong> <StatusBadge status={bill.status} /></div>
        <div><strong>Type:</strong> {bill.bill_type}</div>
        <div><strong>Original:</strong> {formatCurrency(bill.original_amount, bill.currency)}</div>
        <div><strong>Outstanding:</strong> {formatCurrency(bill.outstanding_amount, bill.currency)}</div>
        <div><strong>Issued:</strong> {formatDateTime(bill.issued_at)}</div>
        {bill.paid_at && <div><strong>Paid:</strong> {formatDateTime(bill.paid_at)}</div>}
        {bill.voided_at && <div><strong>Voided:</strong> {formatDateTime(bill.voided_at)}</div>}
      </div>
      <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
        <button onClick={handleDownloadPdf} style={{ padding: '8px 16px', backgroundColor: '#1976d2', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
          Download PDF
        </button>
        {(bill.status === 'open' || bill.status === 'partially_paid') && user?.role === UserRole.TENANT && (
          <button onClick={() => navigate(`/tenant/bills/${id}/pay`)} style={{ padding: '8px 16px', backgroundColor: '#2e7d32', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
            Pay Now
          </button>
        )}
        {(bill.status === 'paid' || bill.status === 'partially_paid') && (user?.role === UserRole.FINANCE_CLERK || user?.role === UserRole.PROPERTY_MANAGER || user?.role === UserRole.ADMINISTRATOR) && (
          <button onClick={() => navigate(`/finance/refunds/new?bill_id=${id}`)} style={{ padding: '8px 16px', backgroundColor: '#f57c00', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
            Issue Refund
          </button>
        )}
        {bill.booking_id && (user?.role === UserRole.PROPERTY_MANAGER || user?.role === UserRole.ADMINISTRATOR) && (
          <button onClick={() => navigate(`/finance/bills/new?booking_id=${bill.booking_id}`)}
            style={{ padding: '8px 16px', backgroundColor: '#7b1fa2', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
            Add Supplemental Charge
          </button>
        )}
        {bill.status !== 'voided' && (user?.role === UserRole.PROPERTY_MANAGER || user?.role === UserRole.ADMINISTRATOR) && (
          <button onClick={async () => {
            if (!id || !confirm('Void this bill? This cannot be undone.')) return;
            try {
              const updated = await billingApi.voidBill(id);
              setBill(updated);
            } catch (err: any) { setError(err?.response?.data?.message || 'Void failed'); }
          }} style={{ padding: '8px 16px', backgroundColor: '#d32f2f', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
            Void Bill
          </button>
        )}
      </div>
    </div>
  );
};
