import { test, expect, Page } from '@playwright/test'

test.use({ launchOptions: { executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome' } })

const projects = [
  { id: 101, name: 'Alpha owner', role: 'owner' },
  { id: 102, name: 'Empty project', role: 'owner' },
  { id: 103, name: 'Viewer project', role: 'viewer' },
]
const endpoints = [
  { id: 201, project_id: 101, name: 'Alpha endpoint', token: 'synthetic-alpha', retention_days: 7 },
  { id: 203, project_id: 103, name: 'Viewer endpoint', token: 'synthetic-viewer', retention_days: 7 },
]
async function setup(page: Page) {
  await page.goto('/register')
  await page.locator('#name').fill('Regression workspace')
  await page.locator('#email').fill(`context-${Date.now()}-${Math.random().toString(36).slice(2)}@example.invalid`)
  await page.locator('#password').fill('Synthetic-only-password-94832!')
  await page.locator('#password-confirmation').fill('Synthetic-only-password-94832!')
  await page.locator('form').evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })))
  await page.waitForURL('**/dashboard')
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname
    let data: any = null
    if (path === '/api/projects') data = { projects, endpoints }
    else if (path.endsWith('/receiver-settings')) data = { response_status: 202, response_content_type: 'application/json', response_body: '{"received":true}', signature_provider: 'auto' }
    else if (path.endsWith('/requests')) data = { requests: [], next_before: null }
    else if (path.endsWith('/forward')) data = { target: null }
    else if (path.endsWith('/deliveries')) data = { deliveries: [], attempts: {} }
    else if (path.endsWith('/team')) data = { members: [], invitations: [] }
    await route.fulfill({ json: data })
  })
  await page.reload()
  await expect(page.getByLabel('Project', { exact: true })).toHaveValue('101')
  await expect(page.getByRole('button', { name: 'Alpha endpoint', exact: true })).toHaveAttribute('aria-pressed', 'true')
}

test('project switching clears endpoint secrets and stale team responses', async ({ page }) => {
  const errors: string[] = []
  page.on('pageerror', e => errors.push(e.message))
  await setup(page)
  let release!: () => void
  const delayed = new Promise<void>(resolve => { release = resolve })
  await page.route('**/api/projects/101/team', async route => {
    await delayed
    await route.fulfill({ json: { members: [{ id: 777, role: 'member' }], invitations: [] } })
  })
  await page.getByRole('button', { name: 'Manage project team' }).click()
  await expect(page.getByText('Loading project team…')).toBeVisible()
  await page.getByLabel('Project', { exact: true }).selectOption('102')
  release()
  await expect(page.getByText('Receive URL · keep this secret')).toHaveCount(0)
  await expect(page.getByText('Receiver response & signature verification')).toHaveCount(0)
  await expect(page.getByText('Membership #777')).toHaveCount(0)
  await page.getByRole('button', { name: 'Manage project team' }).click()
  await expect(page.getByText('No members yet.')).toBeVisible()
  await expect(page.getByText('Membership #777')).toHaveCount(0)
  await page.getByLabel('Project', { exact: true }).selectOption('103')
  await expect(page.getByRole('button', { name: 'Viewer endpoint', exact: true })).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByRole('button', { name: 'Manage project team' })).toHaveCount(0)
  await page.getByText('Receiver response & signature verification').click()
  await expect(page.getByRole('button', { name: 'Save receiver settings', exact: true })).toBeDisabled()
  expect(errors).toEqual([])
})

test('late endpoint settings cannot cross project context', async ({ page }) => {
  await setup(page)
  await page.getByLabel('Project', { exact: true }).selectOption('102')
  let release!: () => void
  const delayed = new Promise<void>(resolve => { release = resolve })
  await page.route('**/api/endpoints/201/receiver-settings', async route => {
    await delayed
    await route.fulfill({ json: { response_status: 503, response_content_type: 'text/plain', response_body: 'stale-alpha-body', signature_provider: 'github', has_signing_secret: true } })
  })
  await page.getByLabel('Project', { exact: true }).selectOption('101')
  await page.getByText('Receiver response & signature verification').click()
  await expect(page.getByRole('button', { name: 'Save receiver settings', exact: true })).toBeDisabled()
  await page.getByLabel('Project', { exact: true }).selectOption('103')
  release()
  await expect(page.locator('textarea')).toHaveValue('{"received":true}')
  await expect(page.getByText('(configured; blank keeps existing)', { exact: true })).toHaveCount(0)
  await expect(page.locator('textarea')).not.toHaveValue('stale-alpha-body')
})

test('receiver and invitation failures have one global feedback message and recover', async ({ page }) => {
  await setup(page)
  await page.getByText('Receiver response & signature verification').click()
  await page.route('**/api/endpoints/201/receiver-settings', async route => {
    if (route.request().method() === 'PUT') await route.fulfill({ status: 422, json: { message: 'Synthetic validation error' } })
    else await route.fallback()
  })
  await page.getByRole('button', { name: 'Save receiver settings', exact: true }).click()
  const receiver = page.locator('details').filter({ has: page.getByText('Receiver response & signature verification') })
  await expect(page.getByRole('alert')).toHaveCount(1)
  await expect(page.getByRole('alert')).toContainText('Synthetic validation error')
  await page.route('**/api/endpoints/201/receiver-settings', async route => {
    await route.fulfill({ json: { response_status: 202, response_content_type: 'application/json', response_body: '{}', signature_provider: 'auto' } })
  })
  await page.getByRole('button', { name: 'Save receiver settings', exact: true }).click()
  await expect(page.getByLabel('Workspace feedback').getByRole('status')).toHaveCount(1)
  await expect(page.getByLabel('Workspace feedback').getByRole('status')).toContainText('Receiver settings saved.')
  await page.getByRole('button', { name: 'Manage project team' }).click()
  const team = page.getByRole('region', { name: 'Project team' })
  await page.route('**/api/projects/101/invitations', route => route.abort())
  await team.getByRole('button', { name: 'Create invitation link' }).click()
  await expect(page.getByRole('alert')).toContainText('Network error')
  await expect(team.getByRole('button', { name: 'Create invitation link' })).toBeEnabled()
  await page.route('**/api/projects/101/invitations', route => route.fulfill({ json: { url: 'https://example.invalid/dashboard#invite=synthetic-only' } }))
  await team.getByRole('button', { name: 'Create invitation link' }).click()
  await expect(team.getByLabel('Private invitation link')).toBeVisible()
  await page.getByLabel('Project', { exact: true }).selectOption('102')
  await page.getByRole('button', { name: 'Manage project team' }).click()
  await expect(page.getByLabel('Private invitation link')).toHaveCount(0)
})
