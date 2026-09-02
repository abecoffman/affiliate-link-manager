# Changelog

All notable changes to this project are documented here. Reconstructed
from git history up through 1.20.0; entries from that point on are
written as the change ships.

## [1.23.0] - 2026-09-02

Removed ShopMy's ability to build a new tracked link (`wrap_url()`/
`can_wrap()`), the plugin's only remaining wrap-capable provider.
ShopMy has no public creator API to verify a destination is actually
monetizable through their network -- the previous implementation built
the documented redirect-wrapper URL by hand with no way to confirm it,
which risked handing out a link that doesn't actually earn anything.
ShopMy is now classify-only, same as every other registered network
(RewardStyle, Amazon, CJ, Rakuten, ShopStyle): recognized on sight,
labeled correctly, but this plugin never builds or edits the link
itself -- generate it on the network's own site and paste it in via a
link's Edit modal to track it here. `go.shopmy.us` link recognition is
unaffected.

The Settings screen's ShopMy affiliate/collection ID fields are gone
(nothing left to configure) in favor of a plain read-only list of
recognized networks; the underlying options are deleted on uninstall
for any site that saved one before this shipped. The generic
"Convert to [Provider]" bulk-action/Edit-modal wrap mechanism itself is
unchanged and stays in place for a future provider that does support
it -- it just has no qualifying provider today.

## [1.22.1] - 2026-08-15

Follow-up TL-style cleanup pass on the 1.22.0 category/modifier refactor
-- no behavior change, all internal consistency:

- Removed 6 dead, redundant `phpcs:ignore` comment lines left over in
  `ALM_Install::migrate_status_to_category()` from an earlier draft.
- Renamed `ALM_Dashboard_Data::get_category_summary()` (was
  `get_status_summary()`) and its `$category_summary` variable
  throughout, so the name matches what it actually summarizes now.
- Corrected several docblocks that still described *current* behavior
  in the retired `status=x` vocabulary (network-signal scanner,
  dashboard data, the Edit modal's Remove-from-Post gating, the
  `ALM_Link_Converter` class docblock) to `category=x`/`modifier=x`.
  Left references that are deliberately historical (documenting a past
  bug or the old tile's old behavior) as-is.

## [1.22.0] - 2026-08-14

Collapsed the `status` column (`active`/`convertible`/`unclassified`/
`stale`/`ignored`, with `stale` overloaded to mean two different things
depending on a separate nullable `dead_confirmed_at`) into two
dimensions that match how a user actually thinks about a link:

- `category`: `affiliate` | `candidate` | `nonaffiliate`.
- `modifier`: only ever set when `category = nonaffiliate`, exactly one
  of `ignored` | `dead` | `stale` at a time.
- `classified_at`: one timestamp, stamped whenever `category`/`modifier`
  actually changes -- replaces `dismissed_at` and `dead_confirmed_at`,
  both of which were only ever doing double duty as a boolean test and
  a timestamp for one specific transition each.

A link no longer found in any post now demotes all the way to
`nonaffiliate`+`stale`, even if it was a real `affiliate` link or a
`candidate` a moment ago -- previously it kept its old status while
secretly not being found. The quiet retention cleanup for those rows
also got a real behavior change alongside the rename: the grace period
before deletion dropped from 60 days to 3 (`alm_stale_link_retention_days`)
and its cutoff now keys off `classified_at` (the actual moment a row
became stale) instead of `last_seen` (a proxy for it).

Self-healing migration in `ALM_Install::maybe_upgrade()`, same pattern
as every prior schema change here -- backfills every existing row from
its old status/timestamp shape, then drops the retired columns (plus
the already-vestigial `last_verified`) in the same pass.

## [1.21.0] - 2026-08-14

Addressed findings from a TL-style code review of the whole plugin:

- Hardened `ALM_Domain_Checker`, `ALM_Link_Health_Checker`, and
  `ALM_Thumbnail_Fetcher` against SSRF by switching them to
  `wp_safe_remote_get()`, matching `ALM_Shortener_Resolver`'s existing
  practice. Confirmed live: a real public URL still succeeds, a loopback
  address and the AWS metadata-service IP are now correctly rejected.
- Fixed a latent execution-time risk in the daily
  `alm_run_domain_recheck_cron()`: it had no `set_time_limit()` override
  and used larger, hardcoded batch sizes than the vetted
  `ALM_Background_Runner::TASK_BATCH_SIZES` values the AJAX/continuation
  path already uses. Now shares the same source of truth and the same
  240s ceiling.
- Removed an orphaned `alm_auto_convert_unclassified` option cleanup and
  marked the unused `last_verified` schema column as vestigial.
- Extended phpcs coverage to test files (previously excluded wholesale).
- Added `README.md` and this changelog.
- Wired the 100+ test integration suite into CI (previously only phpcs
  and the fast unit tier ran there). Caught a real PHP <8.1
  compatibility bug on its very first run -- a `ReflectionMethod::
  setAccessible()` call an earlier round removed because it was
  unnecessary (and, as of PHP 8.5, a hard-failing deprecation) on the
  PHP 8.5 used for local testing, which had silently broken that test
  on every PHP version below 8.1, including this plugin's own
  supported floor.
- Extracted `ALM_Dashboard_Data` out of `ALM_Admin` (the provider/status
  summary counts and Tasks-table formatting -- pure data aggregation
  with no hooks or AJAX wiring of its own) and added integration test
  coverage for it, plus the previously-untested `handle_edit_link`,
  `handle_fetch_thumbnail`, `handle_match_provider`, and
  `handle_settings_forms`/`save_settings` AJAX handlers, and light
  smoke coverage for the three screen-render methods.

## [1.20.0] - 2026-08-13

Rethought the Stale/Dead information architecture and the index-vs-post
action vocabulary. "Stale" (merely not-rediscovered) stopped being a
user-facing concept entirely -- no tab, no badge, no tile -- since it
can never resolve into anything else on its own. Only genuinely
confirmed-dead links are shown, as "Dead Links." Bulk actions gained
real `<optgroup>` grouping distinguishing "edits your post content"
from "tracking only." Added automatic incremental scanning (posts
modified since the last check, picked up by the hourly watchdog with
zero user action) and quiet background cleanup of long-unresolved
tracking rows.

## [1.19.1] - 2026-08-13

Fixed a silent freeze in background task continuation: an uncatchable
PHP fatal between acquiring the batch lock and scheduling the next tick
could permanently break the self-rescheduling chain. Reordered so the
next tick is scheduled immediately after the lock is acquired, before
any risky work runs.

## [1.19.0] - 2026-08-12

Removed the Dead-tab shortcut notice in favor of standard WordPress
Bulk Actions.

## [1.18.2] - 2026-08-12

Rebuilt the Dead-tab notice as a real WP admin notice.

## [1.18.1] - 2026-08-12

Fixed a confusing mismatch between the dead-links banner count and the
tab's own total.

## [1.18.0] - 2026-08-12

Background tasks (Scan, Check Domains, Expand Shortened Links, Check
Link Health) now survive navigating away from the Dashboard -- a run
started via AJAX keeps making progress through WP-Cron even after the
browser tab that started it closes.

## [1.17.1] - 2026-08-12

Renamed the "Check Candidate Links" task to "Check Link Health" for
internal naming consistency.

## [1.17.0] - 2026-08-12

Added a discoverable "Dead" filter, disambiguated from plain "Stale."

## [1.16.0] - 2026-08-11

Added the ability to remove dead links directly from posts.

## [1.15.0] - 2026-08-11

Added candidate link-health checking: dead links now move out of the
opportunities list instead of sitting there unusable.

## [1.14.2] - 2026-08-11

Fixed the thumbnail fetcher's redirect limit -- RewardStyle links need
more than 3 hops.

## [1.14.1] - 2026-08-11

Edit modal redesign, plus product thumbnails.

## [1.13.0] - 2026-08-10

Dashboard redesign: one Tasks table instead of a grab-bag of separate
buttons.

## [1.12.0] - 2026-08-10

IA/UX pass: merged Providers into Settings, tightened the Dashboard,
added an empty state on the Links screen.

## [1.11.0] - 2026-08-10

Added shortened-link expansion (bit.ly, etsy.me, and similar) to their
real destination.

## [1.10.0] - 2026-08-10

Added Amazon/CJ/Rakuten/ShopStyle providers, plus a standing
unrecognized-network detector.

## [1.9.0] - 2026-08-10

Moved View/Ignore/Delete off row actions and into the Edit modal.

## [1.8.0] - 2026-08-10

Redesigned the Links table (Post/Link/Affiliate/URL columns) and
removed the separate Posts screen.

## [1.7.0] - 2026-08-10

Added Edit/Convert UX: turn a Candidate into a real tracked affiliate
link.

## [1.6.1] - 2026-08-10

Removed the Affiliate Links column from the native Posts list
(`edit.php`).

## [1.6.0] - 2026-08-10

Restructured around three tiers: Affiliate Links / Candidate Affiliate
Links / Other Outbound Links.

## [1.5.0] - 2026-08-09

Added domain content-checking (`ALM_Domain_Checker`/`ALM_Domain_Scanner`)
to stop guessing shop status from the domain name and start verifying
it against real page content.

## [1.4.0] - 2026-08-09

Expanded the candidate classifier's default noise list with real
editorial/media publishers.

## [1.3.4] - 2026-08-08

Design/UX review: fixed real layout, responsive, and markup bugs.

## [1.3.0] - 2026-08-08

Split "unclassified" into real candidates vs. noise
(`ALM_Candidate_Classifier`).

## [1.2.0] - 2026-08-08

Added a Posts rollup screen (Admin IA Phase A).

## [1.1.0] - 2026-08-08

Rebuilt the Links screen on `WP_List_Table` (Admin IA Phase A).

## [1.0.1] - 2026-08-08

Fixed the Links table breaking out of its card, found via real browser
testing.

## [1.0.0] - 2026-08-08

Initial version: Affiliate Link Manager foundation.
