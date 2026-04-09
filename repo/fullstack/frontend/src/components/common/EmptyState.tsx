import React from 'react';

interface EmptyStateProps {
  title?: string;
  message?: string;
  action?: React.ReactNode;
}

export const EmptyState: React.FC<EmptyStateProps> = ({
  title = 'No items found',
  message = 'There are no items to display at this time.',
  action,
}) => (
  <div
    style={{
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
      padding: '3rem 1rem',
      textAlign: 'center',
      color: '#6b7280',
    }}
  >
    <div
      style={{
        width: 48,
        height: 48,
        borderRadius: '50%',
        background: '#f3f4f6',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        marginBottom: '1rem',
        fontSize: '1.5rem',
      }}
    >
      0
    </div>
    <h3 style={{ fontSize: '1.125rem', fontWeight: 600, color: '#374151' }}>
      {title}
    </h3>
    <p style={{ marginTop: '0.25rem', fontSize: '0.875rem' }}>{message}</p>
    {action && <div style={{ marginTop: '1rem' }}>{action}</div>}
  </div>
);
