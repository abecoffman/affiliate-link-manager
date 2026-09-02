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

		// A second fixture, deliberately noise -- a social-platform link
		// that should never surface as a candidate, to prove the split
		// actually discriminates rather than marking everything unclassified
		// as a candidate.
		wp([
			'post', 'create',
			'--post_status=publish',
			'--post_title=ALM E2E noise fixture post',
			'--post_content=<p>Follow along on <a href="https://www.instagram.com/example">Instagram</a>.</p>',
		]);

		// A third, dedicated to the manual-paste-a-URL test -- no
		// registered provider can wrap_url() itself (every one is
		// classify-only; see each provider's own class docblock), so
		// pasting in a link already generated on the network's own site
		// is the *only* way to attach one, not just an alternate path.
		wp([
			'post', 'create',
			'--post_status=publish',
			'--post_title=ALM E2E manual-paste fixture post',
			'--post_content=<p>Get the <a href="https://www.a-manual-paste-retailer.example/product">jacket</a>.</p>',
		]);
	});

	// No separate Providers screen -- folded into Settings (see
	// includes/views/settings.php); this list mirrors the current
	// top-level menu (Dashboard, Links, Settings) exactly, on purpose.
	const screens = [
		{ name: 'Dashboard', path: '/wp-admin/admin.php?page=affiliate-links', heading: 'Affiliate Links' },
		{ name: 'Links', path: '/wp-admin/admin.php?page=affiliate-links-links', heading: 'Affiliate Links' },
		{ name: 'Settings', path: '/wp-admin/admin.php?page=affiliate-links-settings', heading: 'Affiliate Links' },
	];

	test('Run Scan completes and populates the Links table', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=affiliate-links');
		await expect(page.getByText('No scan has run yet.')).toBeVisible();

		await page.getByRole('button', { name: 'Run Scan' }).click();
		await expect(page.getByText(/Last scanned/)).toBeVisible({ timeout: 30000 });
		// The three-tier headline: the fixture link's URL doesn't match
		// any known provider, but it also isn't noise (not internal, not
		// social/reference, not an image) -- ALM_Candidate_Classifier
		// surfaces it as a real Candidate Affiliate Link, not an Other
		// Outbound Link.
		const overview = page.locator('.alm-card', { hasText: 'Overview' });
		await expect(overview.getByText('Candidate Affiliate Links')).toBeVisible();
		// First scan ever: both fixture links are new, nothing is stale yet.
		await expect(page.getByText('2 new, 0 now stale.')).toBeVisible();

		const needsAttention = page.locator('.alm-card', { hasText: 'Needs attention' });
		await expect(needsAttention.getByText('Candidate Affiliate Links')).toBeVisible();
		await needsAttention.getByText('Candidate Affiliate Links').click();
		await expect(page).toHaveURL(/status=convertible/);
	});

	test('Other Outbound Links never appears as a tab and is excluded from "All"', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=affiliate-links-links');

		// Scoped to the view-tabs bar specifically (WP core's own
		// .subsubsub class) -- a bare page-wide search would also match
		// the sidebar's top-level "Affiliate Links" menu link.
		const tabs = page.locator('.subsubsub');

		// The noise-fixture link (a social-platform URL) is real
		// unclassified data on this install, proving this isn't just an
		// empty-state pass -- it must still never get a tab of its own.
		await expect(tabs.getByText('Other Outbound', { exact: false })).toHaveCount(0);
		await expect(tabs.getByText('Candidate Affiliate Links', { exact: false })).toBeVisible();
	});

	test('Check Domains completes and does not reclassify an unreachable domain', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=affiliate-links');

		const domainCard = page.locator('.alm-card', { hasText: 'Domain content check' });
		await expect(domainCard.getByRole('button', { name: /Check Domains/ })).toBeVisible();

		await domainCard.getByRole('button', { name: /Check Domains/ }).click();
		// The fixture link's domain (example-retailer.example.com) doesn't
		// resolve -- a real, if boring, case worth covering: the fetch
		// fails, the domain is marked checked, and the link's status must
		// be untouched (still a Candidate), not silently swept to noise.
		await expect(page.getByText(/\d+ domains? checked so far/)).toBeVisible({ timeout: 30000 });

		const needsAttention = page.locator('.alm-card', { hasText: 'Needs attention' });
		await expect(needsAttention.getByText('Candidate')).toBeVisible();
	});

	/**
	 * Regression guard for the redesigned table (Post | Link | Affiliate
	 * | URL, no Status/Last seen column, no separate Posts screen): the
	 * Post cell's title links straight to the post editor, but has no
	 * row actions at all now -- Edit, View, Ignore, and Delete all live
	 * inside the Edit modal instead (per explicit feedback that they
	 * didn't make sense split across two different columns anymore).
	 * Clicking the Link cell's own text is what opens that modal.
	 */
	test('Links screen has no row actions at all -- Edit/View/Ignore/Delete all live inside the modal', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=affiliate-links-links');

		const row = page.getByRole('row', { name: /a very long product name/ });
		await expect(row).toBeVisible();
		await row.hover();

		await expect(row.getByRole('link', { name: 'Edit Post', exact: true })).toHaveCount(0);
		await expect(row.getByRole('link', { name: 'Edit', exact: true })).toHaveCount(0);
		await expect(row.getByRole('link', { name: 'View', exact: true })).toHaveCount(0);
		await expect(row.getByRole('link', { name: 'Ignore', exact: true })).toHaveCount(0);
		await expect(row.getByRole('link', { name: 'Delete', exact: true })).toHaveCount(0);

		await row.getByRole('link', { name: /a very long product name/ }).click();
		const modal = page.locator('#alm-edit-link-modal');
		await expect(modal).toBeVisible();
		await expect(modal.getByRole('link', { name: 'Edit Post', exact: true })).toBeVisible();
		await expect(modal.getByRole('link', { name: 'View', exact: true })).toBeVisible();
		await expect(modal.locator('#alm-edit-link-ignore')).toBeVisible();
		await expect(modal.locator('#alm-edit-link-delete')).toBeVisible();
	});

	/**
	 * The single-row Edit modal, end to end: opens pre-filled from the
	 * row's own data attributes, pasting a URL live re-infers the
	 * "Affiliate partner" display (ALM_Admin::handle_match_provider(),
	 * the real ALM_Provider_Registry::match_url() the scanner itself
	 * uses -- no manual provider picker), and saving actually rewrites
	 * the live post content, not just this plugin's own tracking table.
	 */
	test('Edit modal infers the provider from a pasted ShopMy URL and saves it', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=affiliate-links-links');

		const row = page.getByRole('row', { name: /a very long product name/ });
		await expect(row).toBeVisible();
		await row.hover();
		await row.getByRole('link', { name: /a very long product name/ }).click();

		const modal = page.locator('#alm-edit-link-modal');
		await expect(modal).toBeVisible();

		// Starts unaffiliated -- this fixture link is a raw retailer URL,
		// a Candidate, not yet a real tracked link.
		await expect(modal.locator('#alm-edit-link-provider-display')).toHaveText('Unaffiliated');

		await modal.locator('#alm-edit-link-url-input').fill('https://go.shopmy.us/p-manually-generated-123');
		await expect(modal.locator('#alm-edit-link-provider-display')).toHaveText('ShopMy', { timeout: 5000 });

		await modal.locator('#alm-edit-link-save').click();

		// Save reloads the page on success (same convention as Run
		// Scan/Check Domains) -- the row's own Affiliate badge is the
		// real assertion, not just that the modal closed. The Links
		// table no longer has a Status column (removed as redundant with
		// Affiliate), so status=active is confirmed here via the
		// "Affiliate Links" tab actually surfacing the row, not a badge.
		await expect(page.getByRole('heading', { name: 'Affiliate Links', exact: true })).toBeVisible();
		const updatedRow = page.getByRole('row', { name: /a very long product name/ });
		await expect(updatedRow.locator('.alm-badge-shopmy')).toBeVisible();

		await page.goto('/wp-admin/admin.php?page=affiliate-links-links&status=active');
		await expect(page.getByRole('row', { name: /a very long product name/ })).toBeVisible();
	});

	/**
	 * The modal's whole reason for existing: RewardStyle/LTK (this
	 * site's dominant network, 2,088+ existing links) can't wrap_url()
	 * itself -- the only way to attach a real tracked link is to
	 * generate it on their own site and paste the result in here.
	 */
	test('Edit modal saves a manually pasted RewardStyle URL verbatim', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=affiliate-links-links');

		const row = page.getByRole('row', { name: /jacket/ });
		await expect(row).toBeVisible();
		await row.hover();
		await row.getByRole('link', { name: /jacket/ }).click();

		const modal = page.locator('#alm-edit-link-modal');
		await expect(modal).toBeVisible();

		const pastedUrl = 'https://rstyle.me/+manually-generated-abc123';
		await modal.locator('#alm-edit-link-url-input').fill(pastedUrl);
		await expect(modal.locator('#alm-edit-link-provider-display')).toHaveText('RewardStyle / LTK', { timeout: 5000 });

		await modal.locator('#alm-edit-link-save').click();

		await expect(page.getByRole('heading', { name: 'Affiliate Links', exact: true })).toBeVisible();
		const updatedRow = page.getByRole('row', { name: /jacket/ });
		await expect(updatedRow.locator('.alm-badge-rewardstyle')).toBeVisible();

		await page.goto('/wp-admin/admin.php?page=affiliate-links-links&status=active');
		await expect(page.getByRole('row', { name: /jacket/ })).toBeVisible();
	});

	/**
	 * No registered provider is can_wrap()-capable today (every one is
	 * classify-only -- see each provider's own class docblock), so the
	 * "Convert to [Provider]" bulk action group never gets any entries.
	 * Real, if boring, coverage worth keeping: this must degrade
	 * gracefully, not leave a broken/empty optgroup behind.
	 */
	test('Bulk actions dropdown has no "Convert to" entries', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=affiliate-links-links');

		const options = page.locator('#bulk-action-selector-top option');
		await expect(options.filter({ hasText: 'Convert to' })).toHaveCount(0);
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

	/**
	 * Real regression this guards, reconfirmed against the redesigned
	 * column set (Post | Link | Affiliate | URL): WP core's own
	 * responsive CSS hides non-primary columns via a
	 * `th.column-primary ~ th` *following*-sibling selector, which can
	 * only ever match columns that come *after* the primary one in the
	 * DOM -- the primary column (now "Post") has to be first, or its
	 * header renders broken and overlapping instead of collapsing into
	 * the row card like every other WP core list table. Found by
	 * actually resizing a live browser, not by reading the markup, more
	 * than once this session with a different column out of order each
	 * time.
	 */
	test('Links screen collapses correctly on narrow screens (primary column first)', async ({ page }) => {
		await page.setViewportSize({ width: 600, height: 900 });
		await page.goto('/wp-admin/admin.php?page=affiliate-links-links');

		// Only the primary column's header should be visible -- every
		// other column header is hidden by WP core's own responsive CSS,
		// which only works when the primary column is the first one.
		await expect(page.locator('thead th#post')).toBeVisible();
		await expect(page.locator('thead th#provider')).toBeHidden();

		await expectNoHorizontalOverflow(page);

		// Expanding a row should surface the hidden columns as labeled
		// detail rows, proving the collapse/expand mechanism actually
		// works end to end, not just that the header looks right. Also
		// guards a second real bug found alongside the first: WP core's
		// row_actions() (called from column_post()) already appends its
		// own toggle-row button on this WP core version, and
		// single_row_columns() unconditionally appends a second one for
		// the primary column -- exactly one "Show more details" button
		// per row, not two, confirms that duplication stays fixed.
		const firstRow = page.locator('tbody tr').first();
		await expect(firstRow.locator('button.toggle-row')).toHaveCount(1);
		await firstRow.locator('button.toggle-row').click();
		// The "Link"/"Affiliate" labels visible in the expanded card are
		// WP core's own CSS -- `content: attr(data-colname)` on a
		// ::before pseudo-element -- not real DOM text, so getByText()
		// can't see them. Checking the data-colname attribute directly
		// (what that CSS actually reads) proves the same thing: the
		// columns are really there and correctly labeled, not just that
		// the header looked right.
		await expect(firstRow.locator('td[data-colname="Link"]')).toBeVisible();
		await expect(firstRow.locator('td[data-colname="Affiliate"]')).toBeVisible();
	});

	test('edit.php (the native Posts list) does not carry an Affiliate Links column', async ({ page }) => {
		// Removed deliberately -- the plugin's own Links screen (with
		// its own Post column, linking straight to the editor) covers
		// this, and edit.php was too crowded already. Real regression
		// guard, not just "nothing to test": the column key/class would
		// still be present in the DOM if ALM_Posts_Column were ever
		// accidentally re-wired in.
		await page.goto('/wp-admin/edit.php');

		await expect(page.locator('th#alm_links')).toHaveCount(0);
		await expect(page.locator('td.column-alm_links')).toHaveCount(0);
	});
});
