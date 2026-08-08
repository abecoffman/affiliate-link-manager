// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const SANDBOX_HTTPDOCS = path.join(__dirname, '../../../../plugin-sandbox/httpdocs');

/**
 * Real assertion this exists to make permanent: the Links table once
 * broke straight out of its .alm-card and off the right edge of the
 * page (found by actually rendering it, not by reading the markup --
 * see the affiliate-link-manager-plugin project memory). Checking
 * scrollWidth against the viewport is the general form of that guard --
 * catches this class of layout bug on any screen, not just the one
 * that broke.
 *
 * @param {import('@playwright/test').Page} page
 */
async function expectNoHorizontalOverflow(page) {
	const overflow = await page.evaluate(() => ({
		body: document.body.scrollWidth,
		viewport: window.innerWidth,
	}));
	expect(overflow.body, 'page should not scroll horizontally').toBeLessThanOrEqual(overflow.viewport);
}

/**
 * Seeds a post with a deliberately long URL and runs `wp` directly
 * against the sandbox. The Links table only renders its <table> markup
 * (and so only *can* overflow) when there's at least one row -- a bare
 * sandbox with an empty table isn't a real test of the overflow guard,
 * it just never exercises the code path at all. Confirmed the hard way:
 * an earlier version of this spec passed against a deliberately
 * reintroduced copy of the real bug, for exactly this reason.
 *
 * @param {string[]} args
 * @returns {string}
 */
function wp(args) {
	return execFileSync('wp', ['--path=' + SANDBOX_HTTPDOCS, ...args], { encoding: 'utf8' });
}

test.describe('Affiliate Links admin screens', () => {
	test.beforeAll(() => {
		// The Links table renders the raw URL as the visible link text
		// (see includes/views/links.php) -- browsers only wrap at
		// hyphens/slashes/spaces, so a URL built entirely of those still
		// wraps fine and never actually overflows. A real unbreakable
		// token (a long tracking-id-style query value with no separators,
		// same shape as the real long query strings that triggered the
		// original bug at scale) is what actually forces a column wide.
		const unbreakableToken = 'x'.repeat(220);
		const longUrl = `https://www.example-retailer.example.com/products/item?tracking=${unbreakableToken}`;
		wp([
			'post', 'create',
			'--post_status=publish',
			'--post_title=ALM E2E fixture post',
			`--post_content=<p><a href="${longUrl}">a very long product name that also needs to fit somewhere</a></p>`,
		]);
	});

	const screens = [
		{ name: 'Dashboard', path: '/wp-admin/admin.php?page=affiliate-links', heading: 'Affiliate Links' },
		{ name: 'Links', path: '/wp-admin/admin.php?page=affiliate-links-links', heading: 'Affiliate Links' },
		{ name: 'Providers', path: '/wp-admin/admin.php?page=affiliate-links-providers', heading: 'Affiliate Links' },
		{ name: 'Settings', path: '/wp-admin/admin.php?page=affiliate-links-settings', heading: 'Affiliate Links' },
	];

	test('Run Scan completes and populates the Links table', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=affiliate-links');
		await expect(page.getByText('No scan has run yet.')).toBeVisible();

		await page.getByRole('button', { name: 'Run Scan' }).click();
		await expect(page.getByText(/Last scanned/)).toBeVisible({ timeout: 30000 });
		await expect(page.getByText(/\d+ links? found/)).toBeVisible();
	});

	for (const screen of screens) {
		test(`${screen.name} renders with no console errors and no horizontal overflow`, async ({ page }) => {
			const consoleErrors = [];
			page.on('console', (msg) => {
				if (msg.type() === 'error') {
					consoleErrors.push(msg.text());
				}
			});
			page.on('pageerror', (err) => consoleErrors.push(String(err)));

			await page.goto(screen.path);
			await expect(page.getByRole('heading', { name: screen.heading, exact: true })).toBeVisible();

			if ('Links' === screen.name) {
				// Confirms the overflow check below is actually exercising
				// real table markup, not silently no-op-ing against an
				// empty-state message.
				await expect(page.locator('.alm-links-table')).toBeVisible();
			}

			await expectNoHorizontalOverflow(page);
			expect(consoleErrors).toEqual([]);
		});
	}
});
