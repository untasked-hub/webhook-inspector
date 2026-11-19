import { test, expect } from '@playwright/test'

test.use({ launchOptions: { executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome' } })

test('project tokens, payload/header diff and filtered batch replay', async ({ page }) => {
  const errors: string[] = []
  page.on('pageerror', e => errors.push(e.message))
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()) })
  test.setTimeout(60_000)
  await page.goto('/register')
  await page.locator('#name').fill('API regression workspace')
  await page.locator('#email').fill(`api-${Date.now()}@example.invalid`)
  await page.locator('#password').fill('Synthetic-only-password-94832!')
  await page.locator('#password-confirmation').fill('Synthetic-only-password-94832!')
  await page.locator('form').evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })))
  await page.waitForURL('**/dashboard')
  const rows = [1, 2].map(id => ({ id, method: 'POST', received_at: '2026-11-16T12:00:00Z', size: 20, content_type: 'application/json' }))
  let revoked = false, batch: any = null, forwarding: any = null
  let target = { enabled: true, url: 'https://example.com/[hidden-path]', health: 'healthy', filter: { method: 'POST' } }
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname
    let data: any = null
    if (path === '/api/projects') data = { projects: [{ id: 101, name: 'API workspace', role: 'owner' }, { id: 102, name: 'Viewer', role: 'viewer' }], endpoints: [{ id: 201, project_id: 101, name: 'API endpoint', token: 'synthetic-only', retention_days: 7 }] }
    else if (path.endsWith('/tokens') && route.request().method() === 'POST') data = { token: 'wi_synthetic_only_not_a_credential' }
    else if (path.endsWith('/tokens')) data = { tokens: revoked ? [] : [{ id: 9, name: 'CI suite', prefix: 'wi_synthetic', scope: 'read', last_used_at: null }] }
    else if (path.endsWith('/tokens/9')) { revoked = true; data = {} }
    else if (path.endsWith('/requests')) data = { requests: rows, next_before: null }
    else if (/\/requests\/[12]$/.test(path)) { const id = Number(path.split('/').pop()); data = { request: rows[id - 1], payload: { body_base64: btoa(JSON.stringify({ event: id === 1 ? 'created' : 'changed' })), headers: { 'content-type': ['application/json'], 'x-event': [String(id)] }, query: {} } } }
    else if (path.endsWith('/bulk-replay')) { batch = route.request().postDataJSON(); data = { ids: [11, 12], count: 2 } }
    else if (path.endsWith('/receiver-settings')) data = { response_status: 202, response_content_type: 'application/json', response_body: '{}', signature_provider: 'auto' }
    else if (path.endsWith('/forward') && route.request().method() === 'PUT') {
      forwarding = route.request().postDataJSON()
      target = { ...target, enabled: forwarding.enabled, health: 'off', filter: forwarding.filter }
      return route.fulfill({ status: 204 })
    }
    else if (path.endsWith('/forward')) data = { target }
    else if (path.endsWith('/deliveries')) data = { deliveries: [], attempts: {} }
    await route.fulfill({ json: data })
  })
  await page.reload()
  await expect(page.getByText('Healthy', { exact: true })).toBeVisible()
  await expect(page.getByLabel('Automatic forwarding destination')).toHaveAttribute('placeholder', 'https://example.com/[hidden-path]')
  await expect(page.getByLabel('HTTP method')).toHaveValue('POST')
  await page.getByLabel('HTTP method').selectOption('PUT')
  await page.getByLabel('JSON dot path').fill('data.type')
  await page.getByLabel('JSON string value').fill('paid')
  await page.getByLabel('Enable forwarding').uncheck()
  await page.getByRole('button', { name: 'Save forwarding' }).click()
  await expect.poll(() => forwarding).toMatchObject({ url: '', enabled: false, filter: { method: 'PUT', json_path: 'data.type', json_value: 'paid' } })
  await expect(page.getByText('Forwarding off', { exact: true })).toBeVisible()
  await page.getByText('Project API tokens', { exact: true }).click()
  await page.getByLabel('Token name').fill('Local CLI')
  await page.getByRole('button', { name: 'Generate API token' }).click()
  await expect(page.getByText('wi_synthetic_only_not_a_credential', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Revoke CI suite' }).click()
  await expect(page.getByText('Clients using this token will lose access immediately.')).toBeVisible()
  expect(revoked).toBe(false)
  await page.getByRole('button', { name: 'Confirm revoke' }).click()
  await expect(page.getByText('No API tokens yet.')).toBeVisible()
  await page.getByText('Compare requests', { exact: true }).click()
  await page.getByLabel('Compare with request').selectOption('2')
  await page.getByRole('button', { name: 'Compare', exact: true }).click()
  const diff = page.locator('details').filter({ has: page.getByText('Compare requests', { exact: true }) })
  await expect(diff).toContainText('created')
  await expect(diff).toContainText('changed')
  await page.getByLabel('Diff content').selectOption('headers')
  await expect(diff).toContainText('x-event')
  await page.getByText('Bulk replay', { exact: true }).click()
  await page.getByLabel('Select request 1', { exact: true }).check()
  await page.getByLabel('Bulk replay destination').fill('https://example.com/webhook')
  await page.getByLabel('I authorize every replay').check()
  await page.getByRole('button', { name: 'Queue bulk replay' }).click()
  await expect.poll(() => batch).toMatchObject({ ids: [1], url: 'https://example.com/webhook', confirm: true })
  await page.getByLabel('Bulk replay mode').selectOption('filtered')
  await expect(page.getByText('Replays up to 20 of the newest requests matching the current filters.')).toBeVisible()
  batch = null
  await page.getByLabel('I authorize every replay').check()
  await page.getByRole('button', { name: 'Queue bulk replay' }).click()
  await expect.poll(() => batch).toMatchObject({ url: 'https://example.com/webhook', confirm: true })
  expect(batch.ids).toBeUndefined()
  await page.getByLabel('Project', { exact: true }).selectOption('102')
  await expect(page.getByText('wi_synthetic_only_not_a_credential', { exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Generate API token' })).toHaveCount(0)
  expect(errors).toEqual([])
})
