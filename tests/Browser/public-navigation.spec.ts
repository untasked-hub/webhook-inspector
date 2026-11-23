import { randomUUID } from 'node:crypto'
import { expect, test } from '@playwright/test'

test.use({ launchOptions: { executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome' } })

for (const viewport of [{ width: 1280, height: 800 }, { width: 390, height: 844 }]) {
  test.describe(`public navigation at ${viewport.width}px`, () => {
    test.use({ viewport })

    test('shows the right account links through registration, logout and login', async ({ page }) => {
      test.setTimeout(90_000)
      const email = `nav-${randomUUID()}@example.invalid`
      const password = `Test-only-${randomUUID()}`
      const publicNav = page.getByRole('navigation', { name: 'Main navigation' })
      const accountNav = page.getByRole('navigation', { name: 'Account navigation' })
      const openMenu = async () => {
        if (viewport.width < 768) {
          const btn = page.getByRole('button', { name: 'Menu', exact: true })
          if (await btn.isVisible()) {
            await btn.click()
          }
        }
      }
      const expectGuestLinks = async () => {
        await openMenu()
        await expect(publicNav.getByRole('link', { name: 'Log in', exact: true })).toHaveAttribute('href', '/login')
        await expect(publicNav.getByRole('link', { name: 'Start free', exact: true })).toHaveAttribute('href', '/register')
        await expect(publicNav.getByRole('link', { name: 'Dashboard', exact: true })).toHaveCount(0)
      }
      const expectSignedInLinks = async () => {
        await openMenu()
        await expect(publicNav.getByRole('link', { name: 'Dashboard', exact: true })).toHaveAttribute('href', '/dashboard')
        await expect(publicNav.getByRole('link', { name: 'Log in', exact: true })).toHaveCount(0)
        await expect(publicNav.getByRole('link', { name: 'Start free', exact: true })).toHaveCount(0)
      }

      await page.goto('/')
      await expectGuestLinks()
      await publicNav.getByRole('link', { name: 'Start free', exact: true }).click()
      await page.locator('#name').fill('Synthetic navigation test')
      await page.locator('#email').fill(email)
      await page.locator('#password').fill(password)
      await page.locator('#password-confirmation').fill(password)
      await page.getByRole('button', { name: 'Sign up' }).click()
      await page.waitForURL('**/dashboard', { timeout: 15_000 })

      // Client-side Inertia transitions from dashboard to public pages
      await accountNav.getByRole('link', { name: 'Docs', exact: true }).click()
      await expect(page).toHaveURL(/\/docs$/, { timeout: 15_000 })
      await expectSignedInLinks()
      for (const [label, path] of [['Plans', '/pricing'], ['Self-host', '/self-hosting'], ['Home', '/']]) {
        await publicNav.getByRole('link', { name: label, exact: true }).click()
        await expect(page).toHaveURL(new URL(path, page.url()).href, { timeout: 15_000 })
        await expectSignedInLinks()
      }

      // Hard loads across public routes and guest-only redirect check
      for (const path of ['/', '/pricing', '/docs']) {
        await page.goto(path)
        await expectSignedInLinks()
      }
      await page.goto('/login')
      await expect(page).toHaveURL(/\/dashboard$/, { timeout: 15_000 })

      // Log out and verify guest header on hard load and client transitions
      await accountNav.getByRole('button', { name: 'Log out', exact: true }).click()
      await expect(page).toHaveURL(new URL('/', page.url()).href, { timeout: 15_000 })
      await expectGuestLinks()
      await publicNav.getByRole('link', { name: 'Docs', exact: true }).click()
      await expect(page).toHaveURL(/\/docs$/, { timeout: 15_000 })
      await expectGuestLinks()
      await page.goto('/pricing')
      await expectGuestLinks()

      // Sign back in and confirm signed-in header returns immediately
      await publicNav.getByRole('link', { name: 'Log in', exact: true }).click()
      await expect(page).toHaveURL(/\/login$/, { timeout: 15_000 })
      await page.locator('#email').fill(email)
      await page.locator('#password').fill(password)
      await page.getByRole('button', { name: 'Log in', exact: true }).click()
      await expect(page).toHaveURL(/\/dashboard$/, { timeout: 15_000 })
      await accountNav.getByRole('link', { name: 'Plans', exact: true }).click()
      await expect(page).toHaveURL(/\/pricing$/, { timeout: 15_000 })
      await expectSignedInLinks()
    })
  })
}
