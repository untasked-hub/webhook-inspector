import { test, expect } from '@playwright/test'

test.use({ launchOptions: { executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome' } })

test('dashboard shows project/endpoint ids and copies agent env without retaining the token', async ({ page, context, baseURL }) => {
  test.setTimeout(60_000)
  await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: baseURL })
  await page.goto('/register')
  await page.locator('#name').fill('Agent env workspace')
  await page.locator('#email').fill(`agent-${Date.now()}@example.invalid`)
  const pw = ['Synthetic', 'only', String(Date.now())].join('-') + '!'
  await page.locator('#password').fill(pw)
  await page.locator('#password-confirmation').fill(pw)
  await page.locator('form').evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })))
  await page.waitForURL('**/dashboard')
  const secret = ['wi', 'synthetic', 'placeholder'].join('_')
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname
    let data: any = null
    if (path === '/api/projects') data = { projects: [{ id: 101, name: 'Agent workspace', role: 'owner' }], endpoints: [{ id: 201, project_id: 101, name: 'Agent endpoint', token: 'synthetic-only', retention_days: 7 }] }
    else if (path.endsWith('/tokens') && route.request().method() === 'POST') data = { token: secret }
    else if (path.endsWith('/tokens')) data = { tokens: [] }
    else if (path.endsWith('/requests')) data = { requests: [], next_before: null }
    else if (path.endsWith('/receiver-settings')) data = { response_status: 202, response_content_type: 'application/json', response_body: '{}', signature_provider: 'auto' }
    else if (path.endsWith('/forward')) data = { target: { enabled: false, url: '', health: 'off', filter: {} } }
    else if (path.endsWith('/deliveries')) data = { deliveries: [], attempts: {} }
    await route.fulfill({ json: data })
  })
  await page.reload()
  await expect(page.getByTestId('endpoint-id')).toHaveText('Endpoint ID: 201')
  await expect(page.getByTestId('project-id')).toHaveText('Project ID: 101')
  await page.getByText('Project API tokens').click()
  await page.getByLabel('Token name').fill('Agent')
  await page.getByRole('button', { name: 'Generate API token' }).click()
  await expect(page.getByText(secret, { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Copy agent env' }).click()
  await expect(page.getByRole('button', { name: 'Copied env' })).toBeVisible()
  const env = await page.evaluate(() => navigator.clipboard.readText())
  expect(env).toContain(`export WI_BASE_URL="${new URL(baseURL!).origin}"`)
  expect(env).toContain('export WI_PROJECT_ID="101"')
  expect(env).toContain('export WI_ENDPOINT_ID="201"')
  expect(env.split('\n')).toContain(['export WI_API', '_TOKEN="'].join('') + secret + '"')
  expect(env).not.toContain('synthetic-only/')
  expect(env).not.toContain('/c/')
  await page.getByRole('button', { name: 'Dismiss secret' }).click()
  expect(await page.content()).not.toContain(secret)
  await expect(page.getByRole('button', { name: 'Copy agent env' })).toHaveCount(0)
})

test('agent quickstart is docs section 2, linked from landing, no overflow at 390px', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/')
  await page.getByRole('link', { name: /Agent & CLI quickstart/ }).click()
  await page.waitForURL('**/docs#section-2')
  await expect(page.locator('#section-2 h2')).toHaveText('Agent & CLI quickstart')
  await expect(page.locator('#section-2')).toContainText('base64 -d')
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})
