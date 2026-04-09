import React, { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import type { Settings } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';

export const SettingsPage: React.FC = () => {
  const [settings, setSettings] = useState<Settings | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  useEffect(() => {
    adminApi.getSettings()
      .then((res) => setSettings(res))
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed'))
      .finally(() => setLoading(false));
  }, []);

  const handleSave = async () => {
    if (!settings) return;
    setSaving(true); setError(null); setSuccess(false);
    try { await adminApi.updateSettings(settings); setSuccess(true); }
    catch (err: any) { setError(err?.response?.data?.message || 'Save failed'); }
    finally { setSaving(false); }
  };

  if (loading) return <LoadingSpinner />;
  if (!settings) return <ErrorMessage message="Settings not found" />;

  const update = (field: string, value: any) => setSettings({ ...settings, [field]: value } as Settings);

  return (
    <div style={{ maxWidth: '600px' }}>
      <h1>Organization Settings</h1>
      {error && <ErrorMessage message={error} />}
      {success && <div style={{ padding: '8px', backgroundColor: '#e8f5e9', borderRadius: '4px', marginBottom: '12px' }}>Settings saved</div>}
      {[
        { label: 'Timezone', field: 'timezone', type: 'text' },
        { label: 'Hold Duration (min)', field: 'hold_duration_minutes', type: 'number' },
        { label: 'Cancellation Fee %', field: 'cancellation_fee_pct', type: 'text' },
        { label: 'No-Show Fee %', field: 'no_show_fee_pct', type: 'text' },
        { label: 'Grace Period (min)', field: 'no_show_grace_period_minutes', type: 'number' },
        { label: 'Max Devices', field: 'max_devices_per_user', type: 'number' },
        { label: 'Max Booking Days', field: 'max_booking_duration_days', type: 'number' },
        { label: 'Throttle/min/item', field: 'booking_attempts_per_item_per_minute', type: 'number' },
        { label: 'Recurring Bill Day', field: 'recurring_bill_day', type: 'number' },
        { label: 'Recurring Bill Hour', field: 'recurring_bill_hour', type: 'number' },
      ].map(({ label, field, type }) => (
        <div key={field} style={{ marginBottom: '12px' }}>
          <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>{label}</label>
          <input type={type} value={(settings as any)[field] ?? ''} onChange={e => update(field, type === 'number' ? parseInt(e.target.value) || 0 : e.target.value)}
            style={{ width: '100%', padding: '8px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
        </div>
      ))}
      <div style={{ marginBottom: '12px' }}>
        <label><input type="checkbox" checked={settings.allow_partial_payments} onChange={e => update('allow_partial_payments', e.target.checked)} /> Allow Partial Payments</label>
      </div>
      <div style={{ marginBottom: '12px' }}>
        <label><input type="checkbox" checked={settings.no_show_first_day_rent_enabled} onChange={e => update('no_show_first_day_rent_enabled', e.target.checked)} /> No-Show First Day Rent</label>
      </div>
      <div style={{ marginBottom: '12px' }}>
        <label><input type="checkbox" checked={settings.terminals_enabled} onChange={e => update('terminals_enabled', e.target.checked)} /> Terminals Enabled</label>
      </div>
      <h2 style={{ marginTop: '24px' }}>Notification Templates</h2>
      <p style={{ fontSize: '13px', color: '#666', marginBottom: '12px' }}>Customize notification text per event. Leave blank to use system default.</p>
      {['booking.confirmed', 'booking.canceled', 'booking.no_show', 'booking.checked_in', 'bill.issued', 'bill.penalty', 'payment.received', 'refund.issued'].map(code => (
        <div key={code} style={{ marginBottom: '8px' }}>
          <label style={{ display: 'block', marginBottom: '2px', fontWeight: 500, fontSize: '13px' }}>{code}</label>
          <input
            value={((settings as any).notification_templates || {})[code] || ''}
            onChange={e => {
              const templates = { ...((settings as any).notification_templates || {}), [code]: e.target.value };
              update('notification_templates', templates);
            }}
            placeholder="System default"
            style={{ width: '100%', padding: '6px 8px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box', fontSize: '13px' }}
          />
        </div>
      ))}

      <button onClick={handleSave} disabled={saving} style={{ marginTop: '16px', padding: '10px 24px', backgroundColor: '#1976d2', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
        {saving ? 'Saving...' : 'Save Settings'}
      </button>
    </div>
  );
};
