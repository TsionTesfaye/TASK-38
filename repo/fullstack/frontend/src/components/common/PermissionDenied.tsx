import React from 'react';
import { useNavigate } from 'react-router-dom';

export const PermissionDenied: React.FC = () => {
  const navigate = useNavigate();

  return (
    <div
      style={{
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        minHeight: '60vh',
        textAlign: 'center',
        padding: '2rem',
      }}
    >
      <div
        style={{
          width: 64,
          height: 64,
          borderRadius: '50%',
          background: '#fef2f2',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          marginBottom: '1rem',
          fontSize: '1.5rem',
          color: '#ef4444',
          fontWeight: 700,
        }}
      >
        !
      </div>
      <h2 style={{ color: '#111827', marginBottom: '0.5rem' }}>
        Permission Denied
      </h2>
      <p style={{ color: '#6b7280', maxWidth: 400, fontSize: '0.875rem' }}>
        You don&apos;t have permission to access this page. Please contact your
        administrator if you believe this is an error.
      </p>
      <button
        onClick={() => navigate('/')}
        style={{
          marginTop: '1.5rem',
          padding: '0.5rem 1.5rem',
          background: '#3b82f6',
          color: '#fff',
          border: 'none',
          borderRadius: '0.375rem',
          cursor: 'pointer',
        }}
      >
        Go to Home
      </button>
    </div>
  );
};
