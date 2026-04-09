import React from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../../state/authStore';
import { useNotificationStore } from '../../state/notificationStore';
import * as authApi from '../../api/auth';

interface HeaderProps {
  onMenuToggle: () => void;
}

export const Header: React.FC<HeaderProps> = ({ onMenuToggle }) => {
  const { user, clearAuth } = useAuthStore();
  const { unreadCount } = useNotificationStore();
  const navigate = useNavigate();

  const handleLogout = async () => {
    try {
      await authApi.logout();
    } catch {
      // logout even if API fails
    }
    clearAuth();
    navigate('/login');
  };

  return (
    <header style={{
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      padding: '12px 20px',
      borderBottom: '1px solid #e0e0e0',
      backgroundColor: '#fff',
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
        <button onClick={onMenuToggle} style={{ background: 'none', border: 'none', fontSize: '20px', cursor: 'pointer' }}>
          &#9776;
        </button>
        <span style={{ fontWeight: 600 }}>RentOps</span>
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: '16px' }}>
        <button
          onClick={() => navigate('/notifications')}
          style={{ background: 'none', border: 'none', cursor: 'pointer', position: 'relative', fontSize: '18px' }}
        >
          &#128276;
          {unreadCount > 0 && (
            <span style={{
              position: 'absolute', top: '-4px', right: '-8px',
              backgroundColor: '#d32f2f', color: '#fff', borderRadius: '50%',
              width: '18px', height: '18px', fontSize: '11px',
              display: 'flex', alignItems: 'center', justifyContent: 'center',
            }}>
              {unreadCount > 9 ? '9+' : unreadCount}
            </span>
          )}
        </button>
        <span style={{ fontSize: '14px', color: '#666' }}>{user?.display_name}</span>
        <button
          onClick={handleLogout}
          style={{ padding: '6px 12px', border: '1px solid #ccc', borderRadius: '4px', cursor: 'pointer', background: '#fff' }}
        >
          Logout
        </button>
      </div>
    </header>
  );
};
