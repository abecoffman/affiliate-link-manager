# Affiliate Link Manager

Finds, classifies, and manages affiliate links across post content, keeping
that classification accurate as the content changes -- new posts, edited
posts, links that go dead, shortened links that need resolving.

Not on WP.org; a private plugin shared across the author's own sites via a
symlinked checkout (see [Install](#install) below).

## What it does

- **Scans** published post content for outbound links and classifies each
  one: a real, already-converted **Affiliate Link**; a **Candidate
  Affiliate Link** (looks like it could become one); or **Other Outbound
  Links** (navigation, social, embeds -- never going to be an opportunity).
- **Classifies candidates** using a domain content-check (real e-commerce
  signals: schema.org Product markup, `og:type=product`, price meta,
  known shop-platform fingerprints) rather than a hand-maintained allow/
  deny list, so it scales to shops nobody thought to list.
- **Expands shortened links** (bit.ly, etsy.me, and similar) to their real
  destination so they get classified instead of sitting unclassified
  forever.
- **Checks link health** on candidates so a dead destination moves out of
  the opportunities list instead of sitting there unusable, and offers a
  one-click "Remove from Post" for links confirmed dead.
- **Recognizes and classifies** links from six affiliate networks
  (ShopMy, RewardStyle/LTK, Amazon, CJ, Rakuten, ShopStyle) through a
  pluggable network-provider architecture. None of them build a new
  tracked link automatically -- generate the real link on the network's
  own site and paste it in via a link's Edit modal, which infers the
  provider and saves it.
- **Keeps itself current automatically**: an hourly watchdog cron
  incrementally rescans posts modified since the last check, and quietly
  cleans up tracking rows for links that were never rediscovered, all
  without any user action. See `includes/class-alm-background-runner.php`
  for the mechanism.

## Requirements

- PHP 7.4+
- WordPress 6.0+

## Install

This plugin isn't distributed via WP.org. It lives in
`wp-plugins/affiliate-link-manager` and is symlinked into each WordPress
install that uses it (`wp-content/plugins/affiliate-link-manager` ->
this directory), the same pattern used for the sibling `webp-generator`
plugin. Activate normally from **Plugins** once the symlink is in place.

## Architecture

Content flows through four layers:

1. **Adapters** (`includes/class-alm-*-adapter*.php`) read links out of a
   post regardless of how it was authored -- plain post content by
   default, Beaver Builder when active, more via the
   `alm_register_content_adapters` filter.
2. **The scanner** (`ALM_Scanner`) walks scannable posts via an adapter,
   upserting each discovered link into the plugin's own tracking table
   and sweeping links no longer found into a quiet "stale" bookkeeping
   state.
3. **Classification** (`ALM_Candidate_Classifier`, `ALM_Domain_Checker`/
   `ALM_Domain_Scanner`, `ALM_Shortener_Resolver`, `ALM_Link_Health_Checker`)
   decides what each link actually is, using real content signals over
   guesswork wherever possible.
4. **Providers** (`includes/providers/class-alm-provider-*.php`) know how
   to recognize and wrap a URL for a specific affiliate network. Register
   more via the `alm_register_providers` filter.

Everything that takes real time (scanning, domain checks, shortener
expansion, link-health checks, the incremental scan) runs as a
background task via `ALM_Background_Runner`: state is tracked in an
option, progress survives navigating away from the Dashboard, and a
self-rescheduling WP-Cron tick keeps a run going until it's done.

## Development

```bash
composer install

composer run phpcs          # coding standards
composer run test           # fast unit tier (Brain Monkey, no real WordPress)
composer run test:integration  # real WordPress + real DB, local only (see below)
```

The integration tier (`tests/integration/`) needs a real WordPress test
install (`bin/install-wp-tests.sh <db> <user> <pass> [host] [wp-version]`)
and runs against an isolated Composer context pinned to PHPUnit 9.6 --
WP core's own test library isn't PHPUnit 10-compatible. See that
directory's `composer.json` for the full reasoning. A handful of tests
that exercise the Beaver Builder adapter are skipped unless
`BB_PLUGIN_STUB_PATH` points at a local stub fixture.

`tests/e2e/` is a Playwright suite exercising the real admin screens end
to end; see its own README/config for how to point it at a running site.
