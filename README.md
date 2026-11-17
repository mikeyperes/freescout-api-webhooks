# FreeScout Agent API and Webhooks

FreeScout 1.8.241 / Laravel 5.5 module. Version 2.0.2.

## Features

- Agent/device credentials: native FreeScout sign-in, SHA-256 storage, expiration,
  revocation and per-user inventory. Devices do not require IP allowlists.
- Server credentials: HMAC-SHA256, unique nonces, a 300-second past / 30-second future
  timestamp window, explicit IP allowlists and the same agent permission policy.
- Root, Own and Broad access with native mailbox policies and AssignedOnly hooks.
- Conversation list/get/create, assignment, status, mailbox move, star, read
  state, merge, tags, custom fields and snooze.
- Native email replies with To/CC/BCC and real attachments; notes and drafts.
- Private attachment upload/download; customer create/get/list/update; users;
  saved replies; notifications and their saved preferences.
- Cursor-based changed-since sync, per-token cache rate limiting and a serialized,
  HMAC hash-chained request journal containing metadata rather than ticket content.
- APNs and FCM delivery using FreeScout's selected notification recipients, including
  assignment/customer-reply/new-conversation settings. API notes support explicit mentions.
- Signed outgoing HTTPS webhooks, retry scheduling, delivery logs and SSRF protection.
- Admin settings with encrypted provider credentials, masked fields, Change controls,
  Test actions, setup instructions and a secret-free status command.

## Ownership and compatibility

This module uses FreeScout's existing models, authorization policies, events,
ConversationsController reply/draft/update handlers and native attachment handling.
It does not replace the mail sender or copy its send logic. Existing SMTP settings,
email queues, undo delay, signatures and module hooks continue to apply.

Tags, custom fields, saved replies and snooze are module-owned extensions because
those optional FreeScout modules are not installed here. Their data lives in this
module's tables. They do not silently import or overwrite another module's data.
Snooze sets the native status to Pending, remembers the previous status and restores
it on expiry unless another action already changed the status. A customer reply
cancels the module's snooze marker. The API exposes Snoozed as an additional folder.

No Billing source changes are required. Existing v1 callers keep their original
X-Api-Key value. Key 2 was hashed in place, not rotated. Its scopes and IP allowlist
were preserved after the compatibility migration.

## Setup and settings

Enable the module and run its migrations through the normal FreeScout module/update
process. The administrator page is `/manage/api-webhooks/mobile`; legacy key management
remains `/manage/api-webhooks`. Both management controllers require an administrator.

FreeScout's normal scheduler must run every minute. The named module task dispatches delivery and snooze work through FreeScout's native
background queue; its normal queue worker must also run. Delivery jobs are bounded so
remote providers do not block the scheduler. A daily task handles log/nonces retention.
Run `php artisan apiwebhooks:status` for JSON containing limits, provider credential
presence and registered endpoint methods/paths. It never returns credentials or hashes.

Settings are native FreeScout Options:

- `apiwebhooks_token_days`: credential lifetime, default 30 days.
- `apiwebhooks_requests_per_minute`: per-credential cache bucket, default 60.
- `apiwebhooks_log_retention_days`: request/delivery retention, default 30 days.
- `apiwebhooks_apns_credentials`: encrypted JSON with `team_id`, `key_id`,
  `private_key`, `topic` and optional boolean `sandbox`. Use an Apple P-256 signing key.
- `apiwebhooks_fcm_credentials`: encrypted Google service-account JSON with
  `client_email`, `private_key` and `project_id`. Enable FCM HTTP v1 for that project.

APNs provider JWTs are cached for 50 minutes of their one-hour lifetime; FCM OAuth
credentials are cached until one minute before provider expiry. Cached credentials are
encrypted. Refreshes use connection-owned MySQL locks because Laravel 5.5 file-cache
add() is not atomic. Retry, batch, deadline, signature-window, nonce-retention and push
text defaults are named constants in ApiSettings.

APNs requires a cURL build supporting HTTP/2. Webhooks require cURL for DNS pinning.
The admin push Test action sends fixed text only to the signed-in admin's registered
provider/device. Server Test needs the original credential supplied temporarily;
its caller IP must be allowed. No test changes the stored credential. Webhook Test
queues a fixed `api.test` event for the configured receiver.

Actual delivery to a phone requires valid provider credentials and a registered real
APNs/FCM destination. Isolated transport tests are not proof of phone delivery.

## Common API conventions

Base path: `/api/v2`. HTTPS is mandatory. Send `Accept: application/json` and
`Content-Type: application/json` except multipart attachment uploads. Device calls
send `Authorization: Bearer DEVICE_CREDENTIAL`. Never put credentials in a URL.

Objects are returned in `data`. Conversation fields reuse the existing v1 transformer:
`id`, `number`, `subject`, `status`, `state`, `mailboxId`, `assignee`, `customer`,
`preview`, `cc`, `bcc`, timestamps and counts. V2 adds `tags`, `custom_fields`,
`snoozed_until`, `starred` and `read`. Thread and customer fields also reuse the
module's existing transformers. Treat all returned text/HTML as untrusted input.

Lists accept `page` and `per_page` where documented, with `per_page` from 1 to 100
(default 50). Conversation/thread/customer/notification pages return `page` and `has_more`.
Errors use ordinary HTTP status: 401 invalid credential/signature, 403 denied scope
or IP, 404 inaccessible/missing object, 422 invalid data, 429 rate limit with
Retry-After. Native action failures return `error: native_action_failed` and a message.

### Access levels

- `root`: only for an active FreeScout administrator; all native administrator access.
- `own`: conversation assignment must match the authenticated agent, in addition to
  native mailbox and conversation permissions. Watching alone does not satisfy Own.
- `broad`: every conversation allowed by the agent's mailboxes and native policy.
  AssignedOnly is still enforced; Broad never overrides it.

Every object lookup, thread list, attachment and sync result checks the parent
conversation's visibility. Customer lists are limited to visible conversations for
Own/Broad; Root can see all customers. Users/saved replies are limited to permitted
mailboxes. A device can list/revoke only credentials belonging to its own agent.
Disabled/deleted users, revoked/expired credentials and demoted Root users are rejected.

### Agent login and device credentials

1. `GET /login`: keep the response cookies and returned `csrf_token`.
2. `POST /login`: same cookie jar, X-CSRF-TOKEN, email, password, name and access_level.
3. Without a 2FA module, success returns 201 with `token`, `token_type: Bearer` and
   `expires_at`. The raw credential is returned once with Cache-Control: no-store.
4. The declared `apiwebhooks.device_login_ready` filter receives `(true, request, user)`.
   An additional-authentication owner returns false until its own challenge is complete;
   login then returns 202 with `additional_authentication_required`, the native next URL
   and exchange_url. Token issuance checks the same filter. No module-name guessing occurs.
5. `POST /tokens/exchange` is a 307 gateway to the ordinary protected web path
   `/mobile-api/device-token`. Preserve POST, cookies and CSRF state while completing
   any additional authentication. The native web middleware remains active on that path.
6. Successful issuance logs out the FreeScout web session, invalidates its session ID
   and regenerates CSRF state. Subsequent API requests use the returned device credential.

The current installation has no 2FA module. A future additional-authentication module
must explicitly integrate the declared readiness hook before enabling mobile issuance;
real TOTP/recovery UI testing remains conditional on that installation and integration.

Example request (placeholder values):

```json
{"email":"agent@example.com","password":"PASSWORD","name":"Agent iPhone","access_level":"own"}
```

Example success:

```json
{"token":"ONE_TIME_DEVICE_CREDENTIAL","token_type":"Bearer","expires_at":"2026-12-16T00:00:00+00:00"}
```

`GET /me` returns the agent's brief profile and credential access level.
`GET /tokens` returns its owner's credential metadata, never raw tokens/digests.
`DELETE /tokens/{id}` revokes one of those credentials immediately.

### Server authentication

Create a server credential in the admin settings, bind it to a real active agent,
choose its access level and enter comma-separated allowed addresses/CIDRs. The raw
64-character credential is shown once and stored only as SHA-256.

Send X-Api-Key, X-Api-Timestamp (Unix seconds), X-Api-Nonce (32-128 lowercase hex
characters) and X-Api-Signature (64 lowercase hex characters). Calculate HMAC-SHA256
using the raw credential as key and these five UTF-8 lines joined by LF, with no final LF:

```text
UPPERCASE_HTTP_METHOD
/actual/path?exact=query-string
TIMESTAMP
NONCE
SHA256_OF_EXACT_RAW_REQUEST_BODY
```

This follows the Publish API's signing pattern while additionally binding the query
string. Empty GET bodies use SHA-256 of the empty string. Each accepted nonce may be
used only once for that credential. Device bearer tokens cannot be used as server
keys and server keys cannot be used as bearer tokens. IP resolution follows FreeScout's
trusted-proxy configuration; configure that correctly before placing a proxy in front.

## Endpoint reference

The paths below are relative to `/api/v2`.

### Mailboxes and conversations

- `GET /mailboxes`: accessible mailbox ID/name/email and native folder IDs/names/counts.
  Mine, Unassigned, Assigned, Starred, Drafts, Closed and Spam come from native folders;
  the module adds Snoozed with `id: null` and `type: snoozed`. Counts apply token visibility.
- `GET /conversations`: filters `mailbox_id`, `folder_id`, `status` (active/pending/closed/spam),
  or `snoozed=1`, plus page/per_page. Default sorting is newest activity first
  (last_reply_at, falling back to updated_at). Allowed sort values are activity,
  last_reply_at, updated_at and created_at; direction is asc or desc (default desc).
  Use snoozed=1 with mailbox_id for the virtual folder.
- `GET /conversations/{id}`: one visible conversation including extension metadata.
- `POST /conversations`: native send/create. Required mailbox_id, subject, body and `to`
  email array; optional cc, bcc and uploaded attachment IDs. Identity/initial assignment
  is the authenticated agent. Returns 201 with native result plus conversation_id/thread_id.
- `PATCH /conversations/{id}`: one explicit action per request, examples below.
- `PUT /conversations/{id}/read`: `{"read":true}` or false. Updates this user's native
  conversation notifications/read state and clears its notification cache.

Creation example:

```json
{"mailbox_id":2,"subject":"Order question","body":"<p>How can we help?</p>","to":["customer@example.com"],"cc":[],"bcc":[],"attachments":[123]}
```

Example create/reply result:

```json
{"data":{"status":"success","msg":"","conversation_id":405,"thread_id":2264}}
```

Mutation examples (one request each):

```json
{"action":"status","status":"closed"}
{"action":"assignee","user_id":7}
{"action":"assignee","user_id":-1}
{"action":"move","mailbox_id":3}
{"action":"star","starred":true}
{"action":"merge","merge_id":406}
{"action":"metadata","tags":["billing","urgent"],"custom_fields":{"order_number":"A123"}}
{"action":"metadata","snoozed_until":"2026-12-01T15:00:00Z"}
{"action":"metadata","snoozed_until":null}
```

Assignment validates the target agent's mailbox access. Moving checks the destination
mailbox. Merge checks update access to both conversations and refuses self-merge.
Metadata writes replace the supplied tags/custom_fields map, preserve omitted fields
and touch the native conversation timestamp so sync observes them. Custom-field values
are bounded strings, numbers, booleans or null; nested values are refused.

### Threads, replies, notes and drafts

- `GET /conversations/{id}/threads`: published threads, page/per_page, ascending thread ID.
- `POST /conversations/{id}/reply`: required body; optional to/cc/bcc email arrays and
  attachment IDs. Uses the native send path and configured email queue. A 200 response
  means FreeScout accepted the operation, not that the recipient opened the message.
- `POST /conversations/{id}/notes`: required body; optional `mentions` agent-ID array.
  Mentioned agents must be active and able to view the conversation.
- `GET /conversations/{id}/drafts`: only the authenticated agent's drafts.
- `POST /conversations/{id}/drafts`: required body; optional to/cc/bcc and attachments.
  Returns the native draft conversation_id/thread_id.
- `DELETE /conversations/{id}/drafts/{draftId}`: native discard, only the agent's own draft.

Reply example:

```json
{"body":"<p>Your order has shipped.</p>","to":["customer@example.com"],"cc":["team@example.com"],"bcc":[],"attachments":[123]}
```

Note example:

```json
{"body":"Please review the invoice.","mentions":[7]}
```

### Attachments

- `POST /attachments`: multipart field `file`, using FreeScout's normal upload handling
  and private storage. Returns 201; `data.id` is used in reply/create/draft attachments.
- `GET /attachments/{id}`: authenticated download with Content-Disposition and nosniff.
  Published attachments require parent-conversation access. Draft attachments additionally
  require their creator; unattached uploads are available only to their uploading agent.

### Customers, users and saved replies

- `GET /customers`: scoped customer list, page/per_page.
- `GET /customers/{id}`: one scoped profile, using the existing customer transformer.
- `POST /customers`: required first_name/email; optional last_name. Native create rules
  apply. Returns 201 with the resulting customer profile.
- `PATCH /customers/{id}`: required first_name; optional last_name, company, job_title,
  background. Uses native setData/sanitization and the customer.updated hook. Existing
  email/channel associations are preserved; moving emails between customers is not exposed.
- `GET /users`: native list of agents visible through the caller's mailboxes, brief fields.
- `GET /saved-replies`: shared/per-agent replies in permitted mailboxes; optional mailbox_id.
  Administrators add/remove these module-owned replies in the settings page.

Customer examples:

```json
{"first_name":"Alex","last_name":"Customer","email":"alex@example.com"}
{"first_name":"Alex","company":"Example Ltd","background":"Prefers email."}
```

### Notifications and push devices

- `GET /notifications`: this agent's native notifications whose conversation remains
  visible, with page/per_page. Returns IDs, conversation_id, read_at and created_at without exposing hidden tickets.
- `GET /notification-settings`: native medium/event rows for the agent.
- `PUT /notification-settings`: replace the complete map of medium IDs to event-ID arrays.
  An empty map disables all notifications. Medium 1 is email, 2 browser and 3 mobile.
  Native event IDs are retained; module event 250 enables API-note mentions on mobile.
- `PUT /device`: required provider (apns or fcm) and push_token. Registration belongs to
  the current device credential. Provider destinations are encrypted and never returned.
- `DELETE /device`: remove the current credential's push registration.

Examples:

```json
{"subscriptions":{"1":[2,13,3,5],"2":[2,13,3,5],"3":[1,2,3,250]}}
{"provider":"apns","push_token":"APNS_DEVICE_TOKEN"}
```

Native subscription dispatch selects recipients for new conversations, customer replies,
assignment and other supported native events. API notes with explicit mentions additionally
use event 250. Delivery rechecks current active user/token, conversation access, Own
assignment and the saved preferences before sending. Changes to notification preferences
invalidate previously queued push notifications. Payloads contain event/conversation IDs
and a generic alert, not ticket bodies. Revoked/expired device credentials cannot receive push.

### Changed-since synchronization

`GET /sync?since=ISO8601&per_page=50`, then use each returned next_cursor until null.
The encrypted cursor binds its token, time window and last (syncUpdatedAt,id) pair and expires
in one hour. `next_since` is the checkpoint for the next completed synchronization.
The inclusive checkpoint deliberately overlaps second-resolution timestamps; deduplicate
by conversation ID and syncUpdatedAt. Per-user read/star changes have their own
indexed user/conversation timestamp; they never touch the shared conversation updatedAt.
Soft-deleted conversations retain their native state.

```json
{"data":[],"next_cursor":null,"next_since":"2026-11-16T23:00:00+00:00","requires_visibility_refresh":true}
```

Permission/assignment changes can remove a conversation from visibility without producing
an accessible tombstone. Clients must periodically reconcile their cached IDs against the
scoped conversation list, and always purge cached content on 403/404. The flag above makes
that requirement explicit; this endpoint does not promise a historical event replay feed.

## Outgoing webhooks

Administrators configure an HTTPS receiver and copy its signing secret once. Secrets
are encrypted at rest. Events cover native new conversations, customer/agent replies,
notes, assignment and status changes. Payloads contain event name and conversation ID.

Headers: X-FreeScout-Event (stable UUID), X-FreeScout-Timestamp (Unix seconds) and
X-FreeScout-Signature. Verify the lowercase HMAC-SHA256 of:

```text
TIMESTAMP.EVENT_UUID.EXACT_RAW_JSON_BODY
```

Use the configured signing secret, compare in constant time, reject stale timestamps
and deduplicate event UUIDs. Deliveries are at least once. Each event has up to five
attempts with exponential retry delays; status/attempts are visible in the admin log.
URLs must use HTTPS port 443, no userinfo/fragments, and public addresses only. DNS is
revalidated and pinned on every attempt; redirects are disabled. Response bodies are not logged.

The signed `/api/v2/webhook-diagnostic/{challenge}` endpoint supports short-lived self-tests
only. It accepts no ticket-system business action. Without an existing encrypted diagnostic
challenge it returns 404; it validates timestamp/signature and consumes the challenge once.

## Logs and retention

Legacy request bodies, request headers, query strings and response content are erased by
the security migration. New legacy logs contain status metadata only; no IP geolocation
service is called. The v2 request journal stores method/route-template/IP/status/token ID/time with
an HMAC hash chain serialized on one state row. Retention advances a stored anchor before
removing an old prefix; the remaining chain can still be verified from that anchor.
Credentials, ticket bodies, provider responses, cookies and signatures are not stored in
request logs. Completed/exhausted delivery records follow the same retention setting.

## Legacy v1 security

All `/manage/api-webhooks/*` management actions require an admin. New v1 credentials are
shown once and stored as SHA-256. Existing callers send their unchanged X-Api-Key.
New keys default to `read`; existing keys migrated to explicit legacy permissions for
compatibility. Key 2's permissions/allowlist are not changed by the v2 release.

GET v1 routes require `read`. Conversation create/update require `conversations:write`,
delete requires `conversations:delete`, thread creation `threads:write`, user actions
`users:write`, SMTP/IMAP diagnostics `mailboxes:test`. Admin creation additionally requires
`admin`; fixed-content test email requires `admin`. A thread defaults to the key's creating
agent. Choosing another user_id requires `agents:impersonate`.

The legacy v1 routes remain conversations list/get/create/update/delete, threads list/create,
customers list/get, users list/get/create/disable/enable, mailboxes list/get, email history,
SMTP/IMAP tests and fixed-content send-test. V1 is a compatibility server API, not the mobile
agent boundary; use v2 for agent visibility and normal native reply/send behavior.

## Tests

See [TEST-RESULTS.md](TEST-RESULTS.md) for the recorded production verification and limits.

The production install does not include a PHPUnit executable. The module therefore supplies
standalone PHP regression runners bootstrapping the actual application. The isolated SQLite
migration fixture uses installed PHP 8.1 because the legacy SQLite grammar emits PHP 8.4
deprecations; the live HTTP application tests use the current runtime. Run as its site user:

```text
/opt/cpanel/ea-php81/root/usr/bin/php Modules/ApiWebhooks/Tests/migration.php
php Modules/ApiWebhooks/Tests/security.php
php Modules/ApiWebhooks/Tests/login.php
php Modules/ApiWebhooks/Tests/mobile.php
php Modules/ApiWebhooks/Tests/boundaries.php ACTIVE_TEST_ADMIN_ID
php Modules/ApiWebhooks/Tests/live-endpoints.php ACTIVE_TEST_ADMIN_ID
php Modules/ApiWebhooks/Tests/outbound.php
php Modules/ApiWebhooks/Tests/settings.php ACTIVE_TEST_ADMIN_ID
php Modules/ApiWebhooks/Tests/events.php ACTIVE_TEST_ADMIN_ID
php Modules/ApiWebhooks/Tests/review.php ACTIVE_TEST_ADMIN_ID
```

Tests never print credentials. They create and remove temporary API credentials, validate
real HTTPS routes, policy boundaries, HMAC/replay/time/IP/rate controls and the audit chain.
The workflow runner performs real native sends to the explicitly selected administrator's
email, creates labeled regression conversations and permanently removes its generated
conversations, attachments, metadata, fixture notifications and send-log records in finally.
Remaining queued fixture mail is cancelled before those models are removed.
The runner must not be run with a real customer's address. Outbound tests deliver a real signed HTTPS self-test
and use isolated generated signing keys/mock responses for APNs/FCM protocol checks; they
never replace production provider credentials. Phone delivery still needs real credentials
and a real registered device. The 2FA module is not installed, so real TOTP/recovery UI testing
is conditional on enabling that native module.

## Tables

Legacy: api_keys, api_logs. Mobile: api_device_tokens, api_nonces, api_audit_chain,
api_audit_entries, api_push_devices, api_webhook_subscriptions, api_deliveries,
api_conversation_metadata, api_saved_replies, api_agent_sync_changes. FreeScout's own users,
mailboxes, conversations, threads, customers, attachments, subscriptions and notifications remain
the authoritative native records.
