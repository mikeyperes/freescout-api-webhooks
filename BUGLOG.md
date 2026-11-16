# Security bug log

## API-001 through API-007, 2026-11-16

High severity: authenticated non-admins could manage keys; credentials were plaintext;
keys had unrestricted endpoint authority; user creation could grant admin; test email
accepted arbitrary content; caller-supplied thread identities were trusted; request logs
retained sensitive content and disclosed IPs to a plain-HTTP third party.

Fix: admin controller middleware, SHA-256 credential migration, explicit route scopes,
admin role/test checks, fixed test content, explicit impersonation permission, metadata-only
logging with historical payload scrubbing and scheduled retention. Existing keys retain
explicit legacy permissions to avoid breaking callers. New keys default to read only.

Regression guard: `php Modules/ApiWebhooks/Tests/security.php` exercises hashed credentials,
scopes, CIDR handling, and live HTTP authorization. Full v2 agent scoping is separate work.
