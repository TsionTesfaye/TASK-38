import React, { useEffect, useState } from 'react';
import * as notificationsApi from '../../api/notifications';
import type { NotificationPreference } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { EmptyState } from '../../components/common/EmptyState';

const DEFAULT_EVENTS = [
  'booking.confirmed', 'booking.canceled', 'booking.no_show', 'booking.checked_in',
  'bill.issued', 'bill.penalty', 'payment.received', 'refund.issued',
];

export const NotificationPreferencesPage: React.FC = () => {
  const [prefs, setPrefs] = useState<NotificationPreference[]>([]);
  const [dndStart, setDndStart] = useState('21:00');
  const [dndEnd, setDndEnd] = useState('08:00');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const load = () => {
    setLoading(true);
    notificationsApi.getPreferences()
      .then((res) => {
        setPrefs(res);
        if (res.length > 0) {
          setDndStart(res[0].dnd_start_local || '21:00');
          setDndEnd(res[0].dnd_end_local || '08:00');
        }
      })
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const isEnabled = (eventCode: string): boolean => {
    const pref = prefs.find(p => p.event_code === eventCode);
    return pref ? pref.is_enabled : true; // default enabled
  };

  const handleToggle = async (eventCode: string, enabled: boolean) => {
    setSaved(false);
    try {
      await notificationsApi.updatePreference(eventCode, {
        enabled: enabled,
        dnd_start: dndStart,
        dnd_end: dndEnd,
      });
      load();
      setSaved(true);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to update');
    }
  };

  const handleDndSave = async () => {
    setSaved(false);
    try {
      for (const eventCode of DEFAULT_EVENTS) {
        await notificationsApi.updatePreference(eventCode, {
          enabled: isEnabled(eventCode),
          dnd_start: dndStart,
          dnd_end: dndEnd,
        });
      }
      setSaved(true);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to save DND');
    }
  };

  if (loading) return <LoadingSpinner />;

  return (
    <div style={{ maxWidth: '600px' }}>
      <h1>Notification Preferences</h1>
      {error && <ErrorMessage message={error} />}
      {saved && <div style={{ padding: '8px', backgroundColor: '#e8f5e9', borderRadius: '4px', marginBottom: '12px' }}>Preferences saved</div>}

      <h2 style={{ fontSize: '18px', marginTop: '24px' }}>Do Not Disturb</h2>
      <div style={{ display: 'flex', gap: '16px', marginBottom: '16px', alignItems: 'center' }}>
        <div>
          <label style={{ display: 'block', fontSize: '13px', marginBottom: '2px' }}>Start</label>
          <input type="time" value={dndStart} onChange={e => setDndStart(e.target.value)}
            style={{ padding: '6px', border: '1px solid #ccc', borderRadius: '4px' }} />
        </div>
        <div>
          <label style={{ display: 'block', fontSize: '13px', marginBottom: '2px' }}>End</label>
          <input type="time" value={dndEnd} onChange={e => setDndEnd(e.target.value)}
            style={{ padding: '6px', border: '1px solid #ccc', borderRadius: '4px' }} />
        </div>
        <button onClick={handleDndSave} style={{ padding: '6px 16px', border: '1px solid #ccc', borderRadius: '4px', cursor: 'pointer', background: '#fff', marginTop: '16px' }}>
          Save DND
        </button>
      </div>

      <h2 style={{ fontSize: '18px', marginTop: '24px' }}>Event Subscriptions</h2>
      {DEFAULT_EVENTS.map(eventCode => (
        <div key={eventCode} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 0', borderBottom: '1px solid #eee' }}>
          <span style={{ fontFamily: 'monospace' }}>{eventCode}</span>
          <label style={{ cursor: 'pointer' }}>
            <input type="checkbox" checked={isEnabled(eventCode)} onChange={e => handleToggle(eventCode, e.target.checked)} />
            {' '}{isEnabled(eventCode) ? 'Enabled' : 'Disabled'}
          </label>
        </div>
      ))}
    </div>
  );
};
