import React, { useEffect, useState } from 'react';
import * as reconApi from '../../api/reconciliation';
import type { ReconciliationRun } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { EmptyState } from '../../components/common/EmptyState';
import { StatusBadge } from '../../components/common/StatusBadge';
import { formatDateTime } from '../../utils/formatters';

export const ReconciliationPage: React.FC = () => {
  const [runs, setRuns] = useState<ReconciliationRun[]>([]);
  const [loading, setLoading] = useState(true);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = () => {
    setLoading(true);
    reconApi.listRuns({ page: 1, per_page: 25 })
      .then((res) => setRuns(res.data))
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const handleRun = async () => {
    setRunning(true); setError(null);
    try { await reconApi.runReconciliation(); load(); }
    catch (err: any) { setError(err?.response?.data?.message || 'Failed'); }
    finally { setRunning(false); }
  };

  if (loading) return <LoadingSpinner />;

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
        <h1>Reconciliation</h1>
        <button onClick={handleRun} disabled={running} style={{ padding: '8px 16px', backgroundColor: '#1976d2', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
          {running ? 'Running...' : 'Run Reconciliation'}
        </button>
      </div>
      {error && <ErrorMessage message={error} />}
      {runs.length === 0 ? <EmptyState message="No reconciliation runs" /> : (
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>
            {['Date', 'Status', 'Mismatches', 'Started', 'Completed', 'Export'].map(h => <th key={h} style={{ textAlign: 'left', padding: '8px', borderBottom: '2px solid #e0e0e0' }}>{h}</th>)}
          </tr></thead>
          <tbody>
            {runs.map(r => (
              <tr key={r.id}><td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{r.run_date}</td>
                <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}><StatusBadge status={r.status} /></td>
                <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{r.mismatch_count}</td>
                <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{formatDateTime(r.started_at)}</td>
                <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{r.completed_at ? formatDateTime(r.completed_at) : '-'}</td>
                <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>
                  {r.output_csv_path && <button onClick={async () => {
                    try {
                      const blob = await reconApi.downloadCsv(r.id);
                      const url = URL.createObjectURL(blob as Blob);
                      const a = document.createElement('a'); a.href = url; a.download = `reconciliation_${r.run_date}.csv`; a.click();
                    } catch { /* ignore */ }
                  }} style={{ padding: '4px 8px', fontSize: '12px', border: '1px solid #ccc', borderRadius: '4px', cursor: 'pointer', background: '#fff' }}>CSV</button>}
                </td></tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
};
