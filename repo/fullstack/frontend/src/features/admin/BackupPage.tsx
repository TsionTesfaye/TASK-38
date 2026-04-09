import React, { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { EmptyState } from '../../components/common/EmptyState';
import { formatDateTime } from '../../utils/formatters';

type BackupEntry = { filename: string; size_bytes: number; modified_at: string };

export const BackupPage: React.FC = () => {
  const [backups, setBackups] = useState<BackupEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const load = () => {
    setLoading(true);
    adminApi.listBackups()
      .then((res) => setBackups(res))
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed to load backups'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const handleCreate = async () => {
    setCreating(true); setError(null); setSuccess(null);
    try {
      const res = await adminApi.createBackup();
      setSuccess(`Backup created: ${res.filename}`);
      load();
    } catch (err: any) { setError(err?.response?.data?.message || 'Backup failed'); }
    finally { setCreating(false); }
  };

  if (loading) return <LoadingSpinner />;

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
        <h1>Backups</h1>
        <button onClick={handleCreate} disabled={creating} style={{ padding: '8px 16px', backgroundColor: '#2e7d32', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
          {creating ? 'Creating...' : 'Create Backup'}
        </button>
      </div>
      {error && <ErrorMessage message={error} />}
      {success && <div style={{ padding: '8px', backgroundColor: '#e8f5e9', borderRadius: '4px', marginBottom: '12px' }}>{success}</div>}
      {backups.length === 0 ? <EmptyState message="No backups found" /> : (
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>
            {['Filename', 'Size', 'Created'].map(h => (
              <th key={h} style={{ textAlign: 'left', padding: '8px', borderBottom: '2px solid #e0e0e0' }}>{h}</th>
            ))}
          </tr></thead>
          <tbody>
            {backups.map((b) => (
              <tr key={b.filename}>
                <td style={{ padding: '8px', borderBottom: '1px solid #eee', fontFamily: 'monospace', fontSize: '13px' }}>{b.filename}</td>
                <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{(b.size_bytes / 1024).toFixed(1)} KB</td>
                <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{formatDateTime(b.modified_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
};
