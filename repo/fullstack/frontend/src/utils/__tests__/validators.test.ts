import { describe, it, expect } from 'vitest';
import { required, minLength, isValidCurrency, isPositiveNumber } from '../validators';

describe('validators', () => {
  describe('required', () => {
    it('returns error for null/undefined/empty', () => {
      expect(required(null)).toBe('This field is required');
      expect(required(undefined)).toBe('This field is required');
      expect(required('')).toBe('This field is required');
    });

    it('returns null for non-empty values', () => {
      expect(required('x')).toBeNull();
      expect(required(0)).toBeNull();
      expect(required(false)).toBeNull();
    });
  });

  describe('minLength', () => {
    it('returns error if too short', () => {
      expect(minLength('ab', 3)).toBe('Must be at least 3 characters');
    });

    it('returns null if long enough', () => {
      expect(minLength('abcd', 3)).toBeNull();
      expect(minLength('abc', 3)).toBeNull();
    });
  });

  describe('isValidCurrency', () => {
    it('accepts 3-letter uppercase codes', () => {
      expect(isValidCurrency('USD')).toBeNull();
      expect(isValidCurrency('EUR')).toBeNull();
    });

    it('rejects lowercase / wrong length', () => {
      expect(isValidCurrency('usd')).toBe('Must be a valid 3-letter currency code');
      expect(isValidCurrency('US')).toBe('Must be a valid 3-letter currency code');
      expect(isValidCurrency('USDD')).toBe('Must be a valid 3-letter currency code');
    });
  });

  describe('isPositiveNumber', () => {
    it('rejects non-positive', () => {
      expect(isPositiveNumber(0)).toBe('Must be a positive number');
      expect(isPositiveNumber(-1)).toBe('Must be a positive number');
      expect(isPositiveNumber('not a number')).toBe('Must be a positive number');
    });

    it('accepts positive values', () => {
      expect(isPositiveNumber(1)).toBeNull();
      expect(isPositiveNumber('10.5')).toBeNull();
    });
  });
});
