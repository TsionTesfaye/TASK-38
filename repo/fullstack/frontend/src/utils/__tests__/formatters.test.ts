import { describe, it, expect } from 'vitest';
import {
  formatCurrency,
  formatDate,
  formatDateTime,
  maskId,
  statusLabel,
  sanitizeErrorMessage,
} from '../formatters';

describe('formatters', () => {
  describe('formatCurrency', () => {
    it('formats USD with 2 decimals', () => {
      expect(formatCurrency('100.5', 'USD')).toBe('$100.50');
      expect(formatCurrency('1234.56', 'USD')).toBe('$1,234.56');
    });

    it('formats EUR', () => {
      const result = formatCurrency('50.00', 'EUR');
      expect(result).toContain('50.00');
    });

    it('handles zero', () => {
      expect(formatCurrency('0', 'USD')).toBe('$0.00');
    });

    it('uses USD by default', () => {
      expect(formatCurrency('10')).toBe('$10.00');
    });
  });

  describe('formatDate', () => {
    it('formats ISO date', () => {
      const result = formatDate('2026-04-09T10:00:00Z');
      expect(result).toMatch(/Apr \d+, 2026/);
    });
  });

  describe('formatDateTime', () => {
    it('formats ISO datetime with time', () => {
      const result = formatDateTime('2026-04-09T15:30:00Z');
      expect(result).toMatch(/Apr \d+, 2026/);
      expect(result).toMatch(/\d+:\d{2} [AP]M/);
    });
  });

  describe('maskId', () => {
    it('masks UUIDs showing only last 4 chars', () => {
      expect(maskId('abcd1234-ef56-7890-1234-567890abcdef')).toBe('****cdef');
    });

    it('returns short IDs unchanged', () => {
      expect(maskId('abc')).toBe('abc');
      expect(maskId('abcd')).toBe('abcd');
    });

    it('masks any longer string', () => {
      expect(maskId('12345')).toBe('****2345');
    });
  });

  describe('statusLabel', () => {
    it('converts snake_case to Title Case', () => {
      expect(statusLabel('partially_paid')).toBe('Partially Paid');
      expect(statusLabel('no_show')).toBe('No Show');
    });

    it('handles single word', () => {
      expect(statusLabel('active')).toBe('Active');
    });
  });

  describe('sanitizeErrorMessage', () => {
    it('strips UUIDs from messages', () => {
      const msg = 'Booking abc12345-e29b-41d4-a716-446655440000 not found';
      const sanitized = sanitizeErrorMessage(msg);
      expect(sanitized).not.toContain('abc12345-e29b-41d4-a716-446655440000');
      expect(sanitized).toContain('****');
    });

    it('leaves non-UUID text untouched', () => {
      expect(sanitizeErrorMessage('Invalid credentials')).toBe('Invalid credentials');
    });

    it('strips multiple UUIDs', () => {
      const msg = 'One 11111111-2222-3333-4444-555555555555 and two 66666666-7777-8888-9999-aaaaaaaaaaaa';
      const result = sanitizeErrorMessage(msg);
      expect(result).not.toContain('11111111-2222');
      expect(result).not.toContain('66666666-7777');
    });
  });
});
