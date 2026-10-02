# Redirect Lifecycle

TYPO3 extension for automatic redirect lifetime management.

The alpha package `plan2net/redirect-lifecycle` uses the PHP namespace
`Plan2net\RedirectLifecycle` and shares its source across three local DDEV instances.

The implementation covers manual and automatic redirect creation: site TTL,
global fallback (initially zero), stored cleanup grace period, protection, fixed
expiry, opt-out, and normal redirect types only.
Existing redirects are not enrolled automatically.

`wazum/sluggi` is recommended through Composer `suggest` and TYPO3 extension
metadata. Sluggi 14.15.2 is installed in all three development instances. Its
title synchronization, direct slug edits, and child-page cascades create managed
redirects through native Core events and DataHandler. Sluggi's choices to suppress
redirects remain effective. The extension also runs without Sluggi.
On TYPO3 14, Sluggi 14.15.2 emits package metadata and `ext_tables.php`
deprecations; see the [integration test status](docs/development.md#sluggi-integration).

Backend forms offer automatic management, fixed expiry, and no management.
Managed dates are read-only. Mode changes, protection changes, and manual
reactivation follow the agreed lifetime rules. In TYPO3 14, changing to a QR code
or short URL retains expiry as fixed and ends automatic management.

Valid hits extend eligible managed expiry to at least the configured minimum
remaining lifetime (initially 90 fixed days), independently of hit counting.
Renewal never shortens expiry or the stored deletion time. The final valid second
can renew; expired requests cannot.

The schedulable command `redirect-lifecycle:cleanup` previews due candidates with
`--dry-run` and otherwise soft-deletes them through TYPO3 DataHandler, preserving
record history and dates. Each deletion rechecks eligibility under a database lock.
No cleanup task is installed or activated automatically.

Native restoration restarts a managed redirect's current lifetime atomically,
preserving protection and manual disabling. Fixed and unmanaged records keep
their existing dates, and repeated restoration commands do not restart twice.

The commands `redirect-lifecycle:adopt` and `redirect-lifecycle:renew` preview
eligible and skipped records. `--execute` applies the action; both commands are
excluded from scheduling. Adoption never restarts already managed redirects.
Explicit renewal replaces current managed dates and may shorten a lifetime.
SQLite tests cover real parallel hits, shared host-cache rebuilding, overlapping
cleanup, adoption, explicit renewal, restoration, and backend changes after
candidate selection. Production database,
cluster locking, and installation-specific load checks remain release requirements.
This is a development alpha.

- [Specification](redirect_lifecycle-spezifikation.md)
- [Domain glossary](GLOSSARY.md)
- [Development setup](docs/development.md)

Target versions: TYPO3 12.4, 13.4, and 14.
