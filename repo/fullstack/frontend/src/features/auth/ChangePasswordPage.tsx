import React, { useState } from 'react';
import * as authApi from '../../api/auth';
import { ErrorMessage } from '../../components/common/ErrorMessage';

export const ChangePasswordPage: React.FC = () => {
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!currentPassword || !newPassword) return;
    if (newPassword.length < 8) { setError('New password must be at least 8 characters'); return; }
    setError(null);
    setLoading(true);
    try {
      await authApi.changePassword(currentPassword, newPassword);
      setSuccess(true);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Password change failed');
    } finally {
      setLoading(false);
    }
  };

  if (success) return (
    <div style={{ padding: '40px', textAlign: 'center' }}>
      <h2 style={{ color: '#2e7d32' }}>Password Changed</h2>
      <p>All sessions have been revoked. Please log in again.</p>
      <a href="/login" style={{ color: '#1976d2' }}>Go to Login</a>
    </div>
  );

  return (
    <div style={{ maxWidth: '400px' }}>
      <h1>Change Password</h1>
      {error && <ErrorMessage message={error} />}
      <form onSubmit={handleSubmit}>
        <div style={{ marginBottom: '12px' }}>
          <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>Current Password</label>
          <input type="password" value={currentPassword} onChange={e => setCurrentPassword(e.target.value)} required
            style={{ width: '100%', padding: '10px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
        </div>
        <div style={{ marginBottom: '16px' }}>
          <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>New Password</label>
          <input type="password" value={newPassword} onChange={e => setNewPassword(e.target.value)} required minLength={8}
            style={{ width: '100%', padding: '10px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
        </div>
        <button type="submit" disabled={loading}
          style={{ width: '100%', padding: '12px', backgroundColor: '#1976d2', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer', fontSize: '16px' }}>
          {loading ? 'Changing...' : 'Change Password'}
        </button>
      </form>
    </div>
  );
};
