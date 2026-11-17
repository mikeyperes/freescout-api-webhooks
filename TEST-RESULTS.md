# Production verification

Version 2.0.2 review repairs recorded 2026-11-16 20:43:21 EST on the live
FreeScout 1.8.241 installation.

| Runner | Passed checks |
| --- | ---: |
| migration.php (unchanged isolated SQLite proof, PHP 8.1) | 8 |
| security.php | 21 |
| login.php | 6 |
| mobile.php | 22 |
| boundaries.php | 51 |
| live-endpoints.php | 39 |
| outbound.php | 20 |
| settings.php | 19 |
| events.php | 12 |
| review.php | 17 |
| Total | 215 |

All runners exited 0. Live calls used HTTPS against the installed application.
The unchanged migration runner used an in-memory database. Transport protocol tests
used fresh generated signing keys and isolated APNs/FCM responses.

The review runner proves that a deep page fetches six SQL rows to return five,
checks policy only for those five, orders by newest activity, aggregates folder
counts in SQL and uses a customer SQL subquery. Presenting 25 conversations with
distinct cold customers takes a constant number of queries compared with one
conversation. Read/star actions
leave the native updated_at unchanged; the initiating user's sync sees its own
change while another agent's timestamp remains unchanged.

Native login issues a hashed device credential and ends its web session. Repeated
APNs pushes reuse the same JWT; two FCM pushes perform only one OAuth exchange.
Native subscription dispatch, explicit note mentions, failed delivery/retry,
notification-preference changes and revocation all reached their expected outcomes.
The mention preference is also verified against the native tiny-integer event column.

The workflow covers every business endpoint, native conversation actions,
reply/send with uploaded attachments, private download, drafts/notes, customer
operations, device registration, preferences and merge. The earlier v2 run observed
native customer-mail accepted status 1 for conversation 411, threads 2300, 2306 and
2309. Those records have now been removed. Current runners exercise the same native
queue path and cancel remaining fixture jobs before deleting their native models.

The settings runner submits authenticated forms, performs a real signed server
request and verifies a signed HTTPS webhook accepted with status 204. Security
checks cover route authentication, Own/Broad visibility, root restrictions,
revoked/disabled identities, body/query signatures, replay, timestamp/IP/rate
limits and every retained audit-chain link/hash.

## Fixture cleanup

The prior eight regression conversations (405 through 412), five unattached test
uploads and four orphan fixture email rows were removed through scoped cleanup.
Current runners remove their own conversations, attachments, new actor/customer
records, queued/failed jobs, notifications, send logs and module extension rows.
Readback found zero tagged conversations, uploads, fixture emails/users/credentials,
webhooks, saved replies and push registrations. Key 2's scopes and allowlist remain
exactly unchanged. The metadata-only request audit trail remains under retention;
it records requests that actually occurred and contains no ticket bodies or secrets.

## External activation and verification limits

- APNs and FCM credentials remain absent. Actual phone delivery needs provider
  settings and a registered real device; protocol tests do not claim phone delivery.
- No native 2FA module is installed. A future authentication owner must integrate
  the declared readiness hook; real TOTP/recovery UI is untested.
- Settings were checked through rendered HTML and authenticated HTTP form
  submissions. A visual browser inspection has not been performed.
- Sync is a scoped conversation snapshot with cursor pagination. Permission loss
  requires periodic client visibility reconciliation, as documented in README.
