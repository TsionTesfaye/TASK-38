import React from 'react';
import { sanitizeErrorMessage } from '../../utils/formatters';

interface ErrorMessageProps {
  message: string | null;
  onRetry?: () => void;
}

export const ErrorMessage: React.FC<ErrorMessageProps> = ({ message, onRetry }) => {
  if (!message) return null;

  const safeMessage = sanitizeErrorMessage(message);

  return (
    <div
      style={{
        padding: '1rem',
        background: '#fef2f2',
        border: '1px solid #fecaca',
        borderRadius: '0.5rem',
        color: '#991b1b',
      }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
        <span style={{ fontWeight: 600 }}>Error</span>
      </div>
      <p style={{ marginTop: '0.25rem', fontSize: '0.875rem' }}>{safeMessage}</p>
      {onRetry && (
        <button
          onClick={onRetry}
          style={{
            marginTop: '0.75rem',
            padding: '0.375rem 0.75rem',
            background: '#dc2626',
            color: '#fff',
            border: 'none',
            borderRadius: '0.375rem',
            cursor: 'pointer',
            fontSize: '0.875rem',
          }}
        >
          Retry
        </button>
      )}
    </div>
  );
};
