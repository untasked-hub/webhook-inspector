import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './tests/Browser',
  timeout: 30_000,
  use: {
    baseURL: process.env.APP_URL || 'http://127.0.0.1:8080',
    trace: 'on-first-retry',
  },
})
