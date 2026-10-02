# Local development

Three DDEV projects use separate databases and share the extension source:

| Directory | TYPO3 | PHP | Backend |
| --- | --- | --- | --- |
| `dev/typo3-v12` | 12.4.45 | 8.2 | https://redirect-lifecycle-v12.ddev.site/typo3/ |
| `dev/typo3-v13` | 13.4.35 | 8.3 | https://redirect-lifecycle-v13.ddev.site/typo3/ |
| `dev/typo3-v14` | 14.3.7 | 8.4 | https://redirect-lifecycle-v14.ddev.site/typo3/ |

Backend login: `docker` / `docker`. Exact dependency versions are recorded in
each project's Composer lock file.

## Setup

From the repository root, choose an instance:

```sh
cd dev/typo3-v13
ddev config --auto
ddev start
ddev composer install
```

Composer symlinks the extension from `/opt/redirect-lifecycle`; source changes
apply to all three instances. After configuration or schema changes, run:

```sh
ddev exec vendor/bin/typo3 extension:setup
ddev exec vendor/bin/typo3 cache:flush
```

For a fresh database, run `ddev exec vendor/bin/typo3 setup` with database
host `db`, database/user/password `db`, port `3306`, and driver `mysqli`.
Use `docker` / `docker` for the local backend user. Then run
`ddev config --auto` and `ddev restart`. Existing instances need no initial setup.

TYPO3 12 is pinned to 12.4.45 for local development. Its Composer configuration
allows the matching security advisories during installation; audits still report them.

## Functional tests

Run from each instance directory:

```sh
ddev composer test:functional

# Run selected tests.
ddev composer test:functional -- --filter RenewalTest
```

Tests use disposable SQLite databases, enforced by `Tests/Functional.xml`.
They never use the development MariaDB database. Run the suite on all three
TYPO3 versions; cases specific to TYPO3 14 are skipped on 12 and 13.
Concurrency tests use real processes and a shared SQLite cache; they do not
verify production database isolation or distributed locking.

## Sluggi integration

All three setups install `wazum/sluggi`. Run its integration tests with:

```sh
ddev composer test:functional -- --filter SluggiIntegrationTest
```

The TYPO3 14 test script uses `sluggi-deprecations.xml` to suppress known upstream
Sluggi deprecations. New deprecations still fail the suite. To inspect the known
messages, add `--ignore-baseline`; that diagnostic run is expected to fail.
Review baseline changes after dependency updates rather than regenerating it wholesale.
