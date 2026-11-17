# Changelog

## 2.0.0, 2026-11-16

- Add revocable hashed agent/device credentials and signed server credentials with nonce replay protection.
- Add root, own and broad access, retaining FreeScout mailbox policies and AssignedOnly hooks.
- Add native conversation actions, paged threads, attachments, customers, saved replies and notifications.
- Add module-owned tags, custom fields and snooze state without modifying FreeScout core.
- Add cursor-based conversation sync and a hash-chained, content-free request journal.
- Add APNs/FCM transports, notification-preference-aware push and signed HTTPS webhooks with retry logs.
- Add administrator settings, masked provider credentials, test actions and a secret-free status command.
- Encrypt one-time credential flash values and prevent caching of credential management pages.
- Add real HTTP endpoint, authorization, signing, transport and workflow regression checks.

## 1.1.0, 2026-11-16

- Reconcile upstream and production Git histories without removing live enhancements.
- Restrict management to admins; hash existing and new API credentials without rotation.
- Add explicit route scopes and impersonation permission; fix test message content.
- Erase historical sensitive payloads, stop geolocation requests, schedule log retention.
- Add focused security regression runner with real HTTP authorization probes.
