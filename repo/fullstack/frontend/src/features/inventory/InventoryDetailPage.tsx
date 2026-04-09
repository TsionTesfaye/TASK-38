import React, { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import * as inventoryApi from '../../api/inventory';
import type { InventoryItem } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';

interface CalendarDay {
  date: string;
  available_units: number;
  total_capacity: number;
}

export const InventoryDetailPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const [item, setItem] = useState<InventoryItem | null>(null);
  const [calendar, setCalendar] = useState<CalendarDay[]>([]);
  const [loading, setLoading] = useState(true);
  const [calLoading, setCalLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!id) return;
    inventoryApi.getItem(id)
      .then((res) => setItem(res))
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed to load'))
      .finally(() => setLoading(false));
  }, [id]);

  useEffect(() => {
    if (!id) return;
    setCalLoading(true);
    const from = new Date().toISOString().split('T')[0];
    const to = new Date(Date.now() + 30 * 86400000).toISOString().split('T')[0];
    inventoryApi.getCalendar(id, { from, to })
      .then((res) => setCalendar(res))
      .catch(() => {})
      .finally(() => setCalLoading(false));
  }, [id]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorMessage message={error} />;
  if (!item) return <ErrorMessage message="Item not found" />;

  const maxCap = item.total_capacity;

  return (
    <div>
      <h1>{item.name}</h1>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', maxWidth: '600px', marginBottom: '24px' }}>
        <div><strong>Code:</strong> {item.asset_code}</div>
        <div><strong>Type:</strong> {item.asset_type}</div>
        <div><strong>Location:</strong> {item.location_name}</div>
        <div><strong>Capacity:</strong> {item.total_capacity} ({item.capacity_mode})</div>
        <div><strong>Timezone:</strong> {item.timezone}</div>
        <div><strong>Active:</strong> {item.is_active ? 'Yes' : 'No'}</div>
      </div>

      <h2>Availability Calendar (Next 30 Days)</h2>
      {calLoading ? <LoadingSpinner /> : calendar.length === 0 ? (
        <p style={{ color: '#666' }}>No calendar data available.</p>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: '4px', maxWidth: '700px' }}>
          {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(d => (
            <div key={d} style={{ textAlign: 'center', fontWeight: 600, fontSize: '12px', padding: '4px', color: '#666' }}>{d}</div>
          ))}
          {/* Pad to start on correct weekday */}
          {calendar.length > 0 && Array.from({ length: (new Date(calendar[0].date + 'T00:00:00').getDay() + 6) % 7 }).map((_, i) => (
            <div key={`pad-${i}`} />
          ))}
          {calendar.map(day => {
            const pct = maxCap > 0 ? day.available_units / maxCap : 0;
            const bg = pct >= 0.5 ? '#e8f5e9' : pct > 0 ? '#fff8e1' : '#ffebee';
            const color = pct >= 0.5 ? '#2e7d32' : pct > 0 ? '#f57c00' : '#d32f2f';
            return (
              <div key={day.date} style={{
                padding: '8px 4px', textAlign: 'center', borderRadius: '4px',
                backgroundColor: bg, border: '1px solid #e0e0e0', fontSize: '12px',
              }}>
                <div style={{ fontWeight: 500 }}>{new Date(day.date + 'T00:00:00').getDate()}</div>
                <div style={{ color, fontWeight: 600, fontSize: '13px' }}>{day.available_units}/{day.total_capacity}</div>
              </div>
            );
          })}
        </div>
      )}
      <div style={{ marginTop: '8px', fontSize: '12px', color: '#666' }}>
        <span style={{ display: 'inline-block', width: '12px', height: '12px', backgroundColor: '#e8f5e9', border: '1px solid #ccc', marginRight: '4px', verticalAlign: 'middle' }} /> Available
        <span style={{ display: 'inline-block', width: '12px', height: '12px', backgroundColor: '#fff8e1', border: '1px solid #ccc', marginLeft: '12px', marginRight: '4px', verticalAlign: 'middle' }} /> Low
        <span style={{ display: 'inline-block', width: '12px', height: '12px', backgroundColor: '#ffebee', border: '1px solid #ccc', marginLeft: '12px', marginRight: '4px', verticalAlign: 'middle' }} /> Full
      </div>
    </div>
  );
};
