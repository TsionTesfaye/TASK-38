// Only load jest-dom matchers in DOM environments.
// API-level real HTTP tests run in the Node environment where `window` is undefined
// (the `@vitest-environment node` pragma). Top-level await in ESM is supported
// by Node 18 (v14.8+), so this loads synchronously before any test runs.
if (typeof window !== 'undefined') {
  await import('@testing-library/jest-dom');
}
