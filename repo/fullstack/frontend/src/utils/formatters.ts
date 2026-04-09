import dayjs from 'dayjs';

export function formatCurrency(amount: string, currency = 'USD'): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(parseFloat(amount));
}

export function formatDate(isoString: string): string {
  return dayjs(isoString).format('MMM D, YYYY');
}

export function formatDateTime(isoString: string): string {
  return dayjs(isoString).format('MMM D, YYYY h:mm A');
}

export function maskId(id: string): string {
  if (id.length <= 4) return id;
  return '****' + id.slice(-4);
}

export function statusLabel(status: string): string {
  return status
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Strip UUIDs and long hex identifiers from error messages before display.
 * Prevents backend internal IDs from leaking to users.
 */
export function sanitizeErrorMessage(message: string): string {
  return message.replace(
    /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/gi,
    '****',
  );
}
