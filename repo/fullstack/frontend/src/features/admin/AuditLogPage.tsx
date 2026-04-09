import React, { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import type { AuditLog } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { EmptyState } from '../../components/common/EmptyState';
import { Pagination } from '../../components/common/Pagination';
import { usePagination } from '../../hooks/usePagination';
import { formatDateTime, maskId } from '../../utils/formatters';

export const AuditLogPage: React.FC = () => {
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const { page, perPage, setPage } = usePagination();

  useEffect(() => {
    setLoading(true);
    adminApi.listAuditLogs({ page, per_page: perPage })
      .then((res) => { setLogs(res.data); setTotal(res.meta.total); })
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed'))
      .finally(() => setLoading(false));
  }, [page, perPage]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorMessage message={error} />;
  if (logs.length === 0) return <EmptyState message="No audit logs" />;

  return (
    <div>
      <h1>Audit Logs</h1>
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead><tr>
          {['Time', 'Actor', 'Action', 'Object', 'Object ID'].map(h => <th key={h} style={{ textAlign: 'left', padding: '8px', borderBottom: '2px solid #e0e0e0' }}>{h}</th>)}
        </tr></thead>
        <tbody>
          {logs.map(l => (
            <tr key={l.id}><td style={{ padding: '8px', borderBottom: '1px solid #eee', fontSize: '13px' }}>{formatDateTime(l.created_at)}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{l.actor_username_snapshot}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{l.action_code}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{l.object_type}</td>
              <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{maskId(l.object_id)}</td></tr>
          ))}
        </tbody>
      </table>
      <Pagination page={page} perPage={perPage} total={total} onPageChange={setPage} />
    </div>
  );
};
