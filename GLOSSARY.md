# Redirect lifecycle

Shared vocabulary for managing redirect validity, renewal, protection, and cleanup.

## Language

**Managed redirect**:
A redirect enrolled in automatic lifecycle management at creation or through explicit adoption. Initial protection pauses management without removing membership; a fixed expiry time ends membership, and removing that fixed time does not restore it.

**Expiry time**:
The last second during which a redirect is valid. A redirect is expired only when the current time is later than its expiry time.

**Valid hit**:
A redirect request actually executed by TYPO3 while the redirect is valid, including its final valid second. Requests answered by a browser or CDN do not count.

**Protected redirect**:
A redirect excluded from automatic expiry management and automatic deletion. Protection does not cancel a fixed expiry time or prevent manual deletion.

**Fixed expiry time**:
An explicitly chosen expiry time that takes precedence over automatic lifetime renewal. Enabled fixed-expiry redirects may be soft-deleted after expiry and the cleanup grace period; disabled redirects follow the optional disabled-retention policy, and protection always prevents automatic deletion.

**Manually disabled redirect**:
A redirect explicitly switched off, without changing its lifetime-management membership. It cannot renew through hits and is excluded from automatic deletion unless an unprotected disabled-retention countdown has been initialized.

**Disabled retention period**:
A configured waiting period starting when an unprotected normal redirect is disabled or its existing disabled countdown is explicitly initialized. It does not enable the redirect or change its expiry time.

**Cleanup grace period**:
The waiting period after a redirect's expiry time before automatic soft deletion is allowed.

**Deletion time**:
The earliest time at which an eligible, unprotected redirect may be automatically soft-deleted. Enabled redirects use expiry-based deletion times; disabled redirects may use a separate retention deadline, independently of expiry.

**Existing redirect adoption**:
An explicitly initiated enrollment of eligible existing redirects in automatic lifecycle management. Adoption starts a full initial lifetime and does not renew redirects that have already been adopted.

**Lifetime restart**:
An explicit renewal, manual reactivation, or restoration of a deleted managed redirect that starts a full initial lifetime and replaces the previous expiry and deletion times, subject to protection and fixed expiry rules. Unlike renewal through a valid hit, a restart may shorten the remaining lifetime.
