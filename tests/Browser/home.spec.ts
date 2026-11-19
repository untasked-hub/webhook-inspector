import { test, expect } from '@playwright/test'

test.use({
  launchOptions: {
    executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome',
  },
})

test('home loads with styling and a clean console', async ({ page }) => {
  const consoleErrors: string[] = []
  const pageErrors: string[] = []

  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      consoleErrors.push(msg.text())
    }
  })
  page.on('pageerror', (error) => {
    pageErrors.push(error.message)
  })

  await page.goto(process.env.APP_URL || 'http://127.0.0.1:8080/')

  const heading = page.locator('h1')
  await expect(heading).toBeVisible()

  const styleProof = await page.evaluate(() => {
    const sheets = [...document.styleSheets]
    let ruleCount = 0
    for (const sheet of sheets) {
      try {
        ruleCount += sheet.cssRules.length
      } catch {
        // Cross-origin sheets throw; count them as present but empty.
      }
    }
    const h1 = document.querySelector('h1')
    const fontSize = h1 ? window.getComputedStyle(h1).fontSize : '0px'

    return { ruleCount, fontSize }
  })

  expect(styleProof.ruleCount, 'at least one stylesheet should expose CSS rules').toBeGreaterThan(10)
  expect(styleProof.fontSize).not.toBe('16px')
  expect(consoleErrors, `console errors: ${consoleErrors.join('; ')}`).toEqual([])
  expect(pageErrors, `page errors: ${pageErrors.join('; ')}`).toEqual([])
})
