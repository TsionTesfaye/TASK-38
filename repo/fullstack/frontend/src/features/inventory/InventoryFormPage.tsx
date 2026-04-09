import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import * as inventoryApi from '../../api/inventory';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';

export const InventoryFormPage: React.FC = () => {
  const [form, setForm] = useState({
    asset_code: '', name: '', asset_type: '', location_name: '',
    capacity_mode: 'discrete_units', total_capacity: 1, timezone: 'UTC',
  });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const navigate = useNavigate();

  const update = (field: string, value: string | number) => setForm({ ...form, [field]: value });

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      await inventoryApi.createItem(form);
      navigate('/manager/inventory');
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to create inventory item');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div style={{ maxWidth: '600px' }}>
      <h1>Create Inventory Item</h1>
      {error && <ErrorMessage message={error} />}
      <form onSubmit={handleSubmit}>
        {[
          { label: 'Asset Code', field: 'asset_code', type: 'text' },
          { label: 'Name', field: 'name', type: 'text' },
          { label: 'Asset Type', field: 'asset_type', type: 'text' },
          { label: 'Location', field: 'location_name', type: 'text' },
        ].map(({ label, field, type }) => (
          <div key={field} style={{ marginBottom: '12px' }}>
            <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>{label}</label>
            <input type={type} value={(form as any)[field]} onChange={e => update(field, e.target.value)} required
              style={{ width: '100%', padding: '8px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
          </div>
        ))}
        <div style={{ marginBottom: '12px' }}>
          <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>Capacity Mode</label>
          <select value={form.capacity_mode} onChange={e => update('capacity_mode', e.target.value)}
            style={{ width: '100%', padding: '8px', border: '1px solid #ccc', borderRadius: '4px' }}>
            <option value="discrete_units">Discrete Units</option>
            <option value="single_slot">Single Slot</option>
          </select>
        </div>
        <div style={{ marginBottom: '12px' }}>
          <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>Total Capacity</label>
          <input type="number" min={1} value={form.total_capacity} onChange={e => update('total_capacity', parseInt(e.target.value) || 1)}
            style={{ width: '100%', padding: '8px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
        </div>
        <div style={{ marginBottom: '16px' }}>
          <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>Timezone</label>
          <input type="text" value={form.timezone} onChange={e => update('timezone', e.target.value)}
            style={{ width: '100%', padding: '8px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
        </div>
        <div style={{ display: 'flex', gap: '8px' }}>
          <button type="submit" disabled={loading}
            style={{ padding: '10px 24px', backgroundColor: '#1976d2', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
            {loading ? <LoadingSpinner /> : 'Create Item'}
          </button>
          <button type="button" onClick={() => navigate('/manager/inventory')}
            style={{ padding: '10px 24px', border: '1px solid #ccc', borderRadius: '4px', cursor: 'pointer', background: '#fff' }}>
            Cancel
          </button>
        </div>
      </form>
    </div>
  );
};
