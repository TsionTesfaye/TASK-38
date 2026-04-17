import React, { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import type { User } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { EmptyState } from '../../components/common/EmptyState';
import { StatusBadge } from '../../components/common/StatusBadge';
import { Pagination } from '../../components/common/Pagination';
import { usePagination } from '../../hooks/usePagination';

export const UserManagementPage: React.FC = () => {
  const [users, setUsers] = useState<User[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const { page, perPage, setPage } = usePagination();
  const [showCreate, setShowCreate] = useState(false);
  const [newUser, setNewUser] = useState({ username: '', password: '', display_name: '', role: 'tenant' });
  const [creating, setCreating] = useState(false);

  const load = () => {
    setLoading(true);
    adminApi.listUsers({ page, per_page: perPage })
      .then((res) => { setUsers(res.data); setTotal(res.meta.total); })
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, [page, perPage]);

  const handleFreeze = async (userId: string) => { try { await adminApi.freezeUser(userId); load(); } catch { setError('Failed'); } };
  const handleUnfreeze = async (userId: string) => { try { await adminApi.unfreezeUser(userId); load(); } catch { setError('Failed'); } };

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorMessage message={error} />;

  const handleCreateUser = async () => {
    if (!newUser.username || !newUser.password || !newUser.display_name) { setError('All fields required'); return; }
    setCreating(true); setError(null);
    try {
      await adminApi.createUser(newUser);
      setShowCreate(false);
      setNewUser({ username: '', password: '', display_name: '', role: 'tenant' });
      load();
    } catch (err: any) { setError(err?.response?.data?.message || 'Create failed'); }
    finally { setCreating(false); }
  };

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
        <h1>User Management</h1>
        <button onClick={() => setShowCreate(v => !v)} style={{ padding: '8px 16px', backgroundColor: '#1976d2', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
          {showCreate ? 'Cancel' : 'Create User'}
        </button>
      </div>
      {showCreate && (
        <div style={{ marginBottom: '16px', padding: '16px', border: '1px solid #e0e0e0', borderRadius: '8px' }}>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px', marginBottom: '8px' }}>
            <input placeholder="Username" value={newUser.username} onChange={e => setNewUser({ ...newUser, username: e.target.value })} style={{ padding: '8px', border: '1px solid #ccc', borderRadius: '4px' }} />
            <input placeholder="Password" type="password" value={newUser.password} onChange={e => setNewUser({ ...newUser, password: e.target.value })} style={{ padding: '8px', border: '1px solid #ccc', borderRadius: '4px' }} />
            <input placeholder="Display Name" value={newUser.display_name} onChange={e => setNewUser({ ...newUser, display_name: e.target.value })} style={{ padding: '8px', border: '1px solid #ccc', borderRadius: '4px' }} />
            <select value={newUser.role} onChange={e => setNewUser({ ...newUser, role: e.target.value })} style={{ padding: '8px', border: '1px solid #ccc', borderRadius: '4px' }}>
              <option value="tenant">Tenant</option>
              <option value="property_manager">Property Manager</option>
              <option value="finance_clerk">Finance Clerk</option>
              <option value="administrator">Administrator</option>
            </select>
          </div>
          <button onClick={handleCreateUser} disabled={creating} style={{ padding: '8px 16px', backgroundColor: '#2e7d32', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
            {creating ? 'Creating...' : 'Create'}
          </button>
        </div>
      )}
      {users.length === 0 ? <EmptyState message="No users" /> : (
        <>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead><tr>
              {['Username', 'Name', 'Role', 'Active', 'Frozen', 'Actions'].map(h => <th key={h} style={{ textAlign: 'left', padding: '8px', borderBottom: '2px solid #e0e0e0' }}>{h}</th>)}
            </tr></thead>
            <tbody>
              {users.map(u => (
                <tr key={u.id}><td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{u.username}</td>
                  <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{u.display_name}</td>
                  <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{u.role}</td>
                  <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{u.is_active ? 'Yes' : 'No'}</td>
                  <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{u.is_frozen ? <StatusBadge status="frozen" /> : 'No'}</td>
                  <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>
                    {u.is_frozen ? <button onClick={() => handleUnfreeze(u.id)} style={{ padding: '4px 8px', cursor: 'pointer' }}>Unfreeze</button>
                      : <button onClick={() => handleFreeze(u.id)} style={{ padding: '4px 8px', cursor: 'pointer' }}>Freeze</button>}
                  </td></tr>
              ))}
            </tbody>
          </table>
          <Pagination page={page} perPage={perPage} total={total} onPageChange={setPage} />
        </>
      )}
    </div>
  );
};
