import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import * as authApi from '../../api/auth';
import { ErrorMessage } from '../../components/common/ErrorMessage';

export const BootstrapPage: React.FC = () => {
  const [form, setForm] = useState({ organization_name: '', organization_code: '', admin_username: '', admin_password: '', admin_display_name: '', default_currency: 'USD' });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);
  const navigate = useNavigate();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      await authApi.bootstrap(form);
      setSuccess(true);
      setTimeout(() => navigate('/login'), 2000);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Bootstrap failed');
    } finally {
      setLoading(false);
    }
  };

  const update = (field: string) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [field]: e.target.value });

  if (success) return <div style={{ padding: '40px', textAlign: 'center' }}><h2>System initialized. Redirecting to login...</h2></div>;

  return (
    <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '100vh', padding: '20px' }}>
      <form onSubmit={handleSubmit} style={{ width: '100%', maxWidth: '500px' }}>
        <h1 style={{ textAlign: 'center', marginBottom: '24px' }}>System Bootstrap</h1>
        <p style={{ textAlign: 'center', color: '#666', marginBottom: '24px' }}>Create the first organization and administrator</p>
        {error && <ErrorMessage message={error} />}
        {['organization_name', 'organization_code', 'admin_username', 'admin_password', 'admin_display_name', 'default_currency'].map(field => (
          <div key={field} style={{ marginBottom: '12px' }}>
            <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>{field.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}</label>
            <input type={field.includes('password') ? 'password' : 'text'} value={(form as any)[field]} onChange={update(field)} required minLength={field.includes('password') ? 8 : 1}
              style={{ width: '100%', padding: '10px', border: '1px solid #ccc', borderRadius: '4px', boxSizing: 'border-box' }} />
          </div>
        ))}
        <button type="submit" disabled={loading}
          style={{ width: '100%', padding: '12px', backgroundColor: '#2e7d32', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer', fontSize: '16px', marginTop: '8px' }}>
          {loading ? 'Initializing...' : 'Initialize System'}
        </button>
      </form>
    </div>
  );
};
