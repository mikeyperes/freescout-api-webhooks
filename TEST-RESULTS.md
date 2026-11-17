# Production verification

Recorded 2026-11-16 20:00:44 EST on the live FreeScout 1.8.241 installation.

| Runner | Passed checks |
| --- | ---: |
| migration.php (isolated SQLite, PHP 8.1) | 8 |
| security.php | 21 |
| login.php | 5 |
| mobile.php | 22 |
| boundaries.php | 51 |
| live-endpoints.php | 39 |
| outbound.php | 16 |
| settings.php | 19 |
| events.php | 8 |
| Total | 189 |

All runners exited 0. Live calls used HTTPS against the installed application.
The migration runner used an in-memory database. Transport protocol tests used
fresh generated signing keys and isolated APNs/FCM mock responses.

The workflow exercised native login/token exchange, every business endpoint,
conversation actions, real reply/send with an uploaded attachment, private download,
notes/drafts, customer operations, device registration, preferences and merge.
Native customer-mail send logs for fixture conversation 411 recorded accepted
status 1 for threads 2300, 2306 and 2309. Successful and interrupted regression
conversations were closed; generated credentials, devices and webhook fixtures
were removed. No real customer address was used.

Security checks include every protected route without credentials, Own/Broad
visibility, root restrictions, revoked/disabled identities, unsigned requests,
HMAC body/query tampering, replay, timestamp limits, IP restrictions, independent
cache rate buckets and every retained audit-chain link/hash. A future timestamp
probe uses a margin beyond the 30-second boundary so network latency cannot turn
an invalid test request into a valid one.

The settings runner performed native authenticated form submissions and verified
real signed server HTTP requests and an HTTPS webhook accepted with status 204.
Native subscription dispatch reached the push transport; failure scheduled a
retry, retry succeeded, and preference changes/revocation suppressed delivery.
Key 2's scopes and IP allowlist compared exactly unchanged.

## External activation and verification limits

- APNs and FCM credentials are absent in production. Actual phone delivery needs
  valid provider settings and a registered real device; it has not been claimed.
- The native 2FA module is not installed. Login delegates its eventual challenge
  and protected token exchange to FreeScout; real TOTP/recovery UI is untested.
- Settings were verified through rendered HTML and real authenticated HTTP form
  submissions. A visual browser inspection has not been performed.
- Sync is a scoped conversation snapshot with cursor pagination. Permission loss
  requires periodic client visibility reconciliation, as documented in README.
