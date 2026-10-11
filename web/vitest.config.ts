import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

// Coverage floors from Research 11 T8; include frozen at src/** (A2, R20).
export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    include: ['src/**/*.test.{ts,tsx}'],
    passWithNoTests: false,
    coverage: {
      provider: 'v8',
      include: ['src/**'],
      reporter: ['text', 'json-summary'],
      thresholds: { lines: 80, branches: 70 },
    },
  },
});
