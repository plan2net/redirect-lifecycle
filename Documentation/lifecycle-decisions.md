# Confirmed lifecycle decisions

The lifecycle model was confirmed at the end of the design interview on 2026-10-02. These decisions are incorporated into [the specification](../redirect_lifecycle-spezifikation.md); exact release compatibility remains subject to implementation and verification.

- The Core site setting `redirects.redirectTTL` determines the initial lifetime. See [ADR 0001](adr/0001-use-core-redirect-ttl.md).
- TYPO3 12.4, 13.4, and 14 are mandatory targets; TYPO3 11 is excluded. In TYPO3 14 only normal redirects are eligible, including for existing redirect adoption. See [ADR 0002](adr/0002-supported-versions-and-redirect-types.md).
- Setting a fixed expiry time ends automatic management. Removing that time does not restore membership; explicit enrollment starts a full initial lifetime.
- Explicit lifetime renewal and manual reactivation replace expiry and deletion times with a full initial lifetime, even if this shortens the remaining lifetime.
- New normal redirects without a fixed expiry time belong to automatic management even if initially protected. Protection pauses management. Removing protection from an existing redirect that has not been adopted does not enroll it.
- A TTL of zero means a managed redirect without expiry or deletion times. Hits do not create an expiry time. Configuration changes do not alter stored dates or create missing ones; an explicit lifetime restart uses the current TTL.
- The backend offers an explicit choice between automatic management, a fixed expiry time, and no automatic management. A managed expiry time is displayed without direct editing; editing a fixed time requires selecting that mode.
- Cleanup uses the regular TYPO3 deletion mechanism, retaining its recoverability where supported. Core source verification confirms soft-delete support for the examined TYPO3 12.4, 13.4, and 14.0.0 sources. There is no additional permanent purge.
- The initial lifetime follows the Core's calendar-day calculation. Minimum remaining lifetime, hit renewal lifetime, and cleanup grace period use fixed spans of 86,400 seconds per day. This supersedes the initial acceptance of fixed spans for all three periods.
- Redirects without an unambiguous site use a global `redirectTTL` fallback from the extension settings, with an initial default of zero. A known site's TTL of zero remains authoritative and does not invoke the fallback.
- A site reassignment does not change stored expiry or deletion times. Subsequent explicit lifetime restarts or removal of protection use the newly assigned site's TTL; hits continue to use the installation-wide minimum remaining lifetime.
- Explicitly ending automatic management removes the managed expiry and deletion times without changing manual disabling. This can restore validity to an expired redirect. A fixed expiry time is removed only through an explicit change.
- Restoring a deleted managed redirect is an explicit lifetime restart. Protection and manual disabling remain unchanged; protected redirects receive no managed dates, and fixed expiry times are preserved.
- Changing a managed normal redirect to a QR code or short URL ends management and clears the deletion time, retaining any existing expiry as a fixed expiry time. Changing back to a normal redirect does not automatically enroll it again.
- Valid hits renew only below the installation-wide minimum remaining lifetime (default: 90 fixed days). Renewal sets remaining lifetime to `renewalLifetime` (default: 180 fixed days), which must exceed the minimum. Hits at or above the threshold leave dates unchanged and trigger no lifecycle cache rebuild. Initial lifetimes and explicit restarts continue to follow Core `redirectTTL`.
- Configuration periods are integer days: `redirectTTL >= 0`, minimum remaining lifetime `> 0`, and cleanup grace period `>= 0`. Negative values are rejected. A zero grace period allows deletion at the next scheduler run after expiry; the defaults for minimum remaining lifetime and cleanup grace period remain 90 days each.
- Automatically created redirects use the affected page's site. For manual redirects and existing redirect adoption, an unambiguous site assignment through the record's storage location takes precedence; otherwise use an unambiguous source-host assignment. If neither resolves a site, use the global TTL. The target domain does not determine the TTL.

## Verification boundary

The interview settles behavior, not a tested implementation. Exact patch/PHP support, deprecation-free integration, cache consistency, and concurrency safety still require implementation and tests in separate TYPO3 12, 13, and 14 DDEV installations using the TYPO3 testing framework. Code and documentation use English; user-facing labels require English and German translations.

The source review confirms the Core TTL calculation and soft-delete mechanism in the examined sources; it does not establish extension compatibility or a tested restore action in the Redirects backend module. See the [Core SlugService](https://github.com/TYPO3/typo3/blob/13.4/typo3/sysext/redirects/Classes/Service/SlugService.php), [redirect TCA](https://github.com/TYPO3/typo3/blob/13.4/typo3/sysext/redirects/Configuration/TCA/sys_redirect.php), and [DataHandler](https://github.com/TYPO3/typo3/blob/13.4/typo3/sysext/core/Classes/DataHandling/DataHandler.php).
