import React, { useEffect, useState } from 'react';
import * as notifApi from '../../api/notifications';
import type { Notification } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { EmptyState } from '../../components/common/EmptyState';
import { formatDateTime } from '../../utils/formatters';

const statusColor: Record<string, string> = {
  pending: '#f57c00',
  delivered: '#1976d2',
  read: '#9e9e9e',
};

export const NotificationCenterPage: React.FC = () => {
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = () => {
    setLoading(true);
    notifApi.listNotifications({ page: 1, per_page: 50 })
      .then((res) => setNotifications(res.data))
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const handleMarkRead = async (id: string) => {
    try { await notifApi.markRead(id); load(); } catch { setError('Failed to mark as read'); }
  };

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorMessage message={error} />;

  return (
    <div>
      <h1>Notifications</h1>
      {notifications.length === 0 ? <EmptyState message="No notifications" /> : notifications.map(n => (
        <div key={n.id} style={{ padding: '12px', marginBottom: '8px', borderRadius: '4px', border: '1px solid #e0e0e0', backgroundColor: n.status === 'read' ? '#f9f9f9' : '#fff' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '4px' }}>
            <strong>{n.title}</strong>
            <span style={{
              fontSize: '11px', fontWeight: 600, padding: '2px 8px', borderRadius: '10px',
              color: '#fff', backgroundColor: statusColor[n.status] || '#999',
            }}>
              {n.status.toUpperCase()}
            </span>
          </div>
          <p style={{ margin: '4px 0', color: '#555' }}>{n.body}</p>
          <div style={{ fontSize: '12px', color: '#999', marginTop: '4px' }}>
            <span>Created: {formatDateTime(n.created_at)}</span>
            {n.scheduled_for && <span style={{ marginLeft: '12px' }}>Scheduled: {formatDateTime(n.scheduled_for)}</span>}
            {n.delivered_at && <span style={{ marginLeft: '12px' }}>Delivered: {formatDateTime(n.delivered_at)}</span>}
            {n.read_at && <span style={{ marginLeft: '12px' }}>Read: {formatDateTime(n.read_at)}</span>}
          </div>
          {n.status === 'delivered' && (
            <button onClick={() => handleMarkRead(n.id)} style={{ marginTop: '8px', fontSize: '12px', padding: '4px 8px', border: '1px solid #ccc', borderRadius: '4px', cursor: 'pointer', background: '#fff' }}>
              Mark Read
            </button>
          )}
        </div>
      ))}
    </div>
  );
};
