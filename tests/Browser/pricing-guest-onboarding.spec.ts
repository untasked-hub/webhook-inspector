import { randomUUID } from 'node:crypto'
import { expect, test } from '@playwright/test'

test.use({ launchOptions: { executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome' } })

test('pricing page discloses billing-disabled state cleanly in self-host/default preview', async ({ page }) => {
  await page.goto('/pricing')
  await expect(page.getByRole('heading', { name: 'Plans for every stage', exact: true })).toBeVisible()
  if ((await page.getByText('Hosted Sandbox — Stripe Test Mode').count()) > 0) {
    test.skip(true, 'Preview server is running in Hosted Sandbox Stripe Test Mode.')
    return
  }
  await expect(page.getByRole('status').filter({ hasText: 'Paid checkout is currently unavailable on this deployment' })).toHaveText('Paid checkout is currently unavailable on this deployment: billing is not configured. No payment will be taken.')
  await expect(page.getByRole('button', { name: 'Checkout unavailable' })).toHaveCount(3)
  await expect(page.getByRole('link', { name: 'Start free', exact: true }).last()).toHaveAttribute('href', '/register')

  // Self-host docs clearly distinguish self-hosted billing-disabled default from hosted Sandbox
  await page.goto('/self-hosting')
  await expect(page.getByText('MANAGED_HOSTING=false').first()).toBeVisible()
  await expect(page.getByText('Stripe Test Mode only').first()).toBeVisible()
})

test('plan CTAs go directly to /billing/upgrade with ToS consent required before checkout', async ({ page }) => {
  test.setTimeout(90_000)

  // Only runs full test-mode assertions when the preview server has Stripe Test Mode enabled.
  await page.goto('/pricing')
  await expect(page.getByRole('heading', { name: 'Plans for every stage', exact: true })).toBeVisible()
  const testModeBanner = page.getByText('Hosted Sandbox — Stripe Test Mode')
  if ((await testModeBanner.count()) === 0) {
    test.skip(true, 'Preview server is running with billing disabled (self-host mode).')
    return
  }

  const email = `sandbox-guest-${randomUUID()}@example.invalid`
  const password = `Test-only-${randomUUID()}`

  // 1. Each paid plan button on /pricing links directly to /billing/upgrade?plan=<plan>
  const devLink = page.getByRole('link', { name: 'Test checkout (Developer)', exact: true })
  const proLink = page.getByRole('link', { name: 'Test checkout (Pro)', exact: true })
  const teamLink = page.getByRole('link', { name: 'Test checkout (Team)', exact: true })
  await expect(devLink).toHaveAttribute('href', '/billing/upgrade?plan=developer')
  await expect(proLink).toHaveAttribute('href', '/billing/upgrade?plan=pro')
  await expect(teamLink).toHaveAttribute('href', '/billing/upgrade?plan=team')

  // Clicking Pro as a guest sends to /billing/upgrade?plan=pro -> /login?plan=pro
  await proLink.click()
  await expect(page).toHaveURL(/\/login\?plan=pro$/, { timeout: 15_000 })
  await expect(page.getByText('Pro plan selected.')).toBeVisible()

  // 2. Switch to Sign up -> /register?plan=pro preserves the chosen plan
  const switchToRegister = page.getByRole('link', { name: 'New here? Sign up', exact: true })
  await expect(switchToRegister).toHaveAttribute('href', '/register?plan=pro')
  await switchToRegister.click()
  await expect(page).toHaveURL(/\/register\?plan=pro$/, { timeout: 15_000 })
  await expect(page.getByText('Pro plan selected.')).toBeVisible()

  // 3. Register -> lands directly on /billing/upgrade?plan=pro
  await page.locator('#name').fill('Sandbox Guest Flow')
  await page.locator('#email').fill(email)
  await page.locator('#password').fill(password)
  await page.locator('#password-confirmation').fill(password)
  await page.getByRole('button', { name: 'Sign up' }).click()
  await expect(page).toHaveURL(/\/billing\/upgrade\?plan=pro$/, { timeout: 15_000 })
  await expect(page.getByRole('heading', { name: 'Upgrade your plan', exact: true })).toBeVisible()

  // 4. Terms of Service checkbox must be checked before Stripe Checkout can be initiated
  const checkoutButton = page.getByTestId('start-checkout-button')
  await expect(checkoutButton).toContainText('Continue to Pro Stripe Test Checkout')
  await expect(checkoutButton).toBeDisabled()
  const tosCheckbox = page.locator('#tos-consent')
  await expect(tosCheckbox).not.toBeChecked()
  await expect(page.getByRole('link', { name: 'Terms of Service', exact: true }).first()).toHaveAttribute('href', '/terms')

  // Intercept POST /api/billing/checkout to verify both plan and tos_accepted=true are sent
  let capturedBody: Record<string, unknown> | null = null
  await page.route('**/api/billing/checkout', async (route) => {
    capturedBody = route.request().postDataJSON() as Record<string, unknown>
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ url: 'https://checkout.stripe.com/c/pay/cs_test_synthetic_000' }),
    })
  })
  await page.route('https://checkout.stripe.com/**', (route) => route.abort())

  await tosCheckbox.check()
  await expect(checkoutButton).toBeEnabled()
  await checkoutButton.click()
  await expect.poll(() => capturedBody?.plan).toBe('pro')
  await expect.poll(() => capturedBody?.tos_accepted).toBe(true)

  // 5. Signed-in user visiting /pricing clicks Team plan CTA -> lands directly on /billing/upgrade?plan=team
  await page.unroute('**/api/billing/checkout')
  await page.unroute('https://checkout.stripe.com/**')
  await page.goto('/pricing')
  await page.getByRole('link', { name: 'Test checkout (Team)', exact: true }).click()
  await expect(page).toHaveURL(/\/billing\/upgrade\?plan=team$/, { timeout: 15_000 })
  await expect(page.getByTestId('start-checkout-button')).toContainText('Continue to Team Stripe Test Checkout')
  await expect(page.getByTestId('start-checkout-button')).toBeDisabled()

  // 6. Sign out and log back in via Developer plan CTA -> lands directly on /billing/upgrade?plan=developer
  await page.goto('/dashboard')
  await page.getByRole('navigation', { name: 'Account navigation' }).getByRole('button', { name: 'Log out', exact: true }).click()
  await expect(page).toHaveURL(/\/$/, { timeout: 15_000 })

  await page.goto('/pricing')
  await page.getByRole('link', { name: 'Test checkout (Developer)', exact: true }).click()
  await expect(page).toHaveURL(/\/login\?plan=developer$/, { timeout: 15_000 })
  await expect(page.getByText('Developer plan selected.')).toBeVisible()
  await page.locator('#email').fill(email)
  await page.locator('#password').fill(password)
  await page.getByRole('button', { name: 'Log in' }).click()
  await expect(page).toHaveURL(/\/billing\/upgrade\?plan=developer$/, { timeout: 15_000 })
  await expect(page.getByTestId('start-checkout-button')).toContainText('Continue to Developer Stripe Test Checkout')
  await expect(page.getByTestId('start-checkout-button')).toBeDisabled()
})
