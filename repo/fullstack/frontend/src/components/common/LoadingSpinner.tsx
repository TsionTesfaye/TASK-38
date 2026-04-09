import React from 'react';

interface LoadingSpinnerProps {
  size?: number;
  message?: string;
}

const spinnerStyle = (size: number): React.CSSProperties => ({
  width: size,
  height: size,
  border: `${Math.max(2, size / 10)}px solid #e5e7eb`,
  borderTopColor: '#3b82f6',
  borderRadius: '50%',
  animation: 'spin 0.8s linear infinite',
});

export const LoadingSpinner: React.FC<LoadingSpinnerProps> = ({
  size = 32,
  message,
}) => (
  <div
    style={{
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
      padding: '2rem',
      gap: '0.75rem',
    }}
  >
    <style>{`@keyframes spin { to { transform: rotate(360deg); } }`}</style>
    <div style={spinnerStyle(size)} />
    {message && (
      <p style={{ color: '#6b7280', fontSize: '0.875rem' }}>{message}</p>
    )}
  </div>
);
