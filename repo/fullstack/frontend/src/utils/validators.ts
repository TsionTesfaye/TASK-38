export function required(value: unknown): string | null {
  if (value === null || value === undefined || value === '') {
    return 'This field is required';
  }
  return null;
}

export function minLength(value: string, min: number): string | null {
  if (value.length < min) {
    return `Must be at least ${min} characters`;
  }
  return null;
}

export function isValidCurrency(value: string): string | null {
  if (!/^[A-Z]{3}$/.test(value)) {
    return 'Must be a valid 3-letter currency code';
  }
  return null;
}

export function isPositiveNumber(value: unknown): string | null {
  const num = Number(value);
  if (isNaN(num) || num <= 0) {
    return 'Must be a positive number';
  }
  return null;
}
