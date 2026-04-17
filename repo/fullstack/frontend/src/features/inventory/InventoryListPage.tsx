import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import * as inventoryApi from '../../api/inventory';
import type { InventoryItem } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { EmptyState } from '../../components/common/EmptyState';
import { Pagination } from '../../components/common/Pagination';
import { usePagination } from '../../hooks/usePagination';

export const InventoryListPage: React.FC = () => {
  const [items, setItems] = useState<InventoryItem[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const { page, perPage, setPage } = usePagination();
  const navigate = useNavigate();

  useEffect(() => {
    setLoading(true);
    inventoryApi.listItems({ page, per_page: perPage })
      .then((res) => { setItems(res.data); setTotal(res.meta.total); })
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed to load'))
      .finally(() => setLoading(false));
  }, [page, perPage]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorMessage message={error} />;

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
        <h1 style={{ margin: 0 }}>Inventory</h1>
        <button onClick={() => navigate('new')} style={{ padding: '8px 16px', backgroundColor: '#1976d2', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
          Create Item
        </button>
      </div>
      {items.length === 0 ? <EmptyState message="No inventory items found" /> : (
        <>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead><tr>
              {['Code', 'Name', 'Type', 'Location', 'Capacity', 'Active'].map(h => (
                <th key={h} style={{ textAlign: 'left', padding: '8px', borderBottom: '2px solid #e0e0e0' }}>{h}</th>
              ))}
            </tr></thead>
            <tbody>
              {items.map(item => (
                <tr key={item.id} onClick={() => navigate(item.id)} style={{ cursor: 'pointer' }}>
                  <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{item.asset_code}</td>
                  <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{item.name}</td>
                  <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{item.asset_type}</td>
                  <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{item.location_name}</td>
                  <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{item.total_capacity}</td>
                  <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{item.is_active ? 'Yes' : 'No'}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <Pagination page={page} perPage={perPage} total={total} onPageChange={setPage} />
        </>
      )}
    </div>
  );
};
