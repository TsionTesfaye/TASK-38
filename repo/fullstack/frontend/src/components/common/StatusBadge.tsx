import React from 'react';
import { STATUS_COLORS } from '@/utils/constants';
import { statusLabel } from '@/utils/formatters';

interface StatusBadgeProps {
  status: string;
  size?: 'sm' | 'md';
}

export const StatusBadge: React.FC<StatusBadgeProps> = ({
  status,
  size = 'sm',
}) => {
  const color = STATUS_COLORS[status] ?? '#6b7280';
  const padding = size === 'sm' ? '0.125rem 0.5rem' : '0.25rem 0.75rem';
  const fontSize = size === 'sm' ? '0.75rem' : '0.875rem';

  return (
    <span
      style={{
        display: 'inline-block',
        padding,
        fontSize,
        fontWeight: 600,
        borderRadius: '9999px',
        color,
        background: `${color}1a`,
        border: `1px solid ${color}40`,
        whiteSpace: 'nowrap',
      }}
    >
      {statusLabel(status)}
    </span>
  );
};
