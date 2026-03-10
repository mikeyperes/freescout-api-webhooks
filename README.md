# FreeScout API & Webhooks Module

A free, open-source REST API module for [FreeScout](https://freescout.net/) helpdesk with enhanced security features including IP whitelisting, rate limiting, and full request logging.

**Author:** [Michael Peres](https://github.com/mikeyperes)
**License:** AGPL-3.0

## Features

- **Full REST API** for conversations, threads, customers, users, and mailboxes
- **API key authentication** with per-key IP whitelisting (supports CIDR notation)
- **Request logging** with method, endpoint, IP, status code, and response time
- **Rate limiting** (60 requests/minute per key)
- **SMTP/IMAP testing** and test email sending via API
- **Email history** across all mailboxes with direction filtering
- **Admin UI** at Manage > API & Webhooks for key management and log viewing
- **XSS protection** — all inputs sanitized, all outputs JSON-encoded
- **No CORS by default** — API only accessible server-side unless configured

## Requirements

- FreeScout >= 1.8.198
- PHP >= 7.4 (tested on 8.2)
- PHP `imap` extension (for IMAP testing endpoint)

## Installation

1. Copy the `ApiWebhooks` folder into your FreeScout `Modules/` directory:

```bash
cp -r ApiWebhooks /path/to/freescout/Modules/
```

2. Create the public symlink:

```bash
ln -sf /path/to/freescout/Modules/ApiWebhooks/Public /path/to/freescout/public/modules/apiwebhooks
```

3. Run the migration:

```bash
php artisan migrate --force --path=Modules/ApiWebhooks/Database/Migrations
```

4. Clear caches:

```bash
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

5. Verify the module appears in **Manage > Modules** and is enabled.

## Configuration

### Creating an API Key

1. Go to **Manage > API & Webhooks**
2. Enter a name for the key (e.g., "n8n Integration")
3. Optionally enter allowed IPs (comma-separated, supports CIDR: `192.168.1.0/24`)
4. Click **Generate Key**
5. **Copy the API Key and Secret immediately** — the secret is only shown once

### Authentication

Pass your API key in the `X-Api-Key` header:

```bash
curl -H "X-Api-Key: YOUR_API_KEY" https://support.example.com/api/v1/conversations
```

Or as a query parameter (less secure, not recommended):

```bash
curl https://support.example.com/api/v1/conversations?api_key=YOUR_API_KEY
```

## API Reference

**Base URL:** `https://your-freescout.com/api/v1`

All responses return JSON with this structure:

```json
{
  "status": "success",
  "data": { ... }
}
```

Error responses:

```json
{
  "status": "error",
  "message": "Description of the error."
}
```

---

### Conversations

#### List Conversations

```
GET /api/v1/conversations
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `mailbox_id` | int | Filter by mailbox |
| `status` | string | `active`, `pending`, `closed`, `spam` |
| `state` | string | `published`, `draft`, `deleted` |
| `assignee` | int | Filter by assigned user ID |
| `customer_id` | int | Filter by customer ID |
| `search` | string | Search subject, preview, ticket number, or customer email |
| `sort_by` | string | Sort field (default: `updated_at`) |
| `order` | string | `asc` or `desc` (default: `desc`) |
| `per_page` | int | Results per page, max 200 (default: 50) |
| `page` | int | Page number |

**Response:**

```json
{
  "status": "success",
  "page": 1,
  "pages": 3,
  "total": 142,
  "data": [
    {
      "id": 44,
      "number": 44,
      "type": "email",
      "status": "active",
      "state": "published",
      "subject": "Refund request",
      "preview": "Could you please refund...",
      "mailboxId": 4,
      "assignee": {
        "id": 6,
        "type": "user",
        "firstName": "Jane",
        "lastName": "Smith",
        "email": "jane@example.com"
      },
      "customer": {
        "id": 91,
        "type": "customer",
        "firstName": "Rodney",
        "lastName": "Robertson",
        "email": "rodney@example.org"
      },
      "cc": [],
      "bcc": [],
      "closedAt": null,
      "lastReplyAt": "2026-03-05T14:07:23+00:00",
      "createdAt": "2026-03-01T10:00:00+00:00",
      "updatedAt": "2026-03-05T14:07:23+00:00",
      "threadsCount": 5,
      "hasAttachments": false
    }
  ]
}
```

#### Get Conversation (with threads)

```
GET /api/v1/conversations/:id
```

Returns full conversation object with `_embedded.threads` array.

#### Create Conversation

```
POST /api/v1/conversations
```

**Body Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `mailbox_id` | int | Yes | Target mailbox ID |
| `subject` | string | Yes | Conversation subject |
| `customer_email` | string | Yes | Customer's email address |
| `body` | string | Yes | First message body (HTML allowed) |
| `assignee` | int | No | Assign to user ID |
| `status` | string | No | `active` (default), `pending`, `closed` |
| `type` | string | No | `email` (default), `phone`, `chat` |
| `cc` | string | No | CC emails, comma-separated |
| `bcc` | string | No | BCC emails, comma-separated |
| `customer_first_name` | string | No | New customer's first name |
| `customer_last_name` | string | No | New customer's last name |

**Example:**

```bash
curl -X POST https://support.example.com/api/v1/conversations \
  -H "X-Api-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "mailbox_id": 4,
    "subject": "Order #12345 issue",
    "customer_email": "customer@example.com",
    "customer_first_name": "John",
    "customer_last_name": "Doe",
    "body": "<p>I have an issue with my order.</p>",
    "assignee": 6,
    "status": "active"
  }'
```

#### Update Conversation

```
PUT /api/v1/conversations/:id
```

**Body Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | `active`, `pending`, `closed`, `spam` |
| `assignee` | int | Reassign to user ID |
| `subject` | string | Update subject line |

#### Delete Conversation

```
DELETE /api/v1/conversations/:id
```

Moves conversation to Deleted state (soft delete).

---

### Threads (Replies & Notes)

#### List Threads

```
GET /api/v1/conversations/:id/threads
```

Returns all threads for a conversation, newest first.

#### Create Thread (Reply or Note)

```
POST /api/v1/conversations/:id/threads
```

**Body Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `body` | string | Yes | Thread body (HTML allowed) |
| `type` | string | No | `note` (default), `message` (agent reply), `customer` |
| `user_id` | int | No | User creating the thread |

**Example — Add a note:**

```bash
curl -X POST https://support.example.com/api/v1/conversations/44/threads \
  -H "X-Api-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "body": "Internal note: Customer called about this.",
    "type": "note",
    "user_id": 1
  }'
```

---

### Customers

#### List/Search Customers

```
GET /api/v1/customers
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `search` | string | Search by name, company, or email |
| `per_page` | int | Results per page (default: 50, max: 200) |

#### Get Customer

```
GET /api/v1/customers/:id
```

Returns full customer object with emails, phones, websites, social profiles, and address.

---

### Users (Agents)

#### List Users

```
GET /api/v1/users
```

Returns all non-deleted users with full profile data:

```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "firstName": "Jane",
      "lastName": "Smith",
      "email": "jane@example.com",
      "role": "admin",
      "status": "active",
      "inviteState": "activated",
      "type": "user",
      "jobTitle": "Support Lead",
      "phone": "+1-555-0100",
      "timezone": "America/New_York",
      "locale": "en",
      "photoUrl": "https://...",
      "permissions": [],
      "mailboxIds": [1, 2, 4],
      "lastLoginAt": "2026-03-07T14:30:00+00:00",
      "telegramEnabled": false,
      "createdAt": "2025-01-15T10:00:00+00:00",
      "updatedAt": "2026-03-07T14:30:00+00:00"
    }
  ]
}
```

**Fields:** `lastLoginAt` requires the login tracking migration included in this module. `telegramEnabled` is populated if the Telegram notifications module is installed. `mailboxIds` lists mailboxes the user has access to.

#### Get User

```
GET /api/v1/users/:id
```

#### Create User

```
POST /api/v1/users
```

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `email` | string | Yes | Unique email address |
| `first_name` | string | Yes | First name (max 20 chars) |
| `last_name` | string | Yes | Last name (max 30 chars) |
| `password` | string | No | Password (min 8 chars, auto-generated if omitted) |
| `role` | string | No | `user` (default) or `admin` |
| `mailbox_ids` | string/array | No | Comma-separated mailbox IDs to grant access |

#### Disable User

```
POST /api/v1/users/:id/disable
```

Disables a non-admin user. Admin users cannot be disabled via API.

#### Enable User

```
POST /api/v1/users/:id/enable
```

Re-enables a disabled user.

---

### Mailboxes

#### List Mailboxes

```
GET /api/v1/mailboxes
```

#### Get Mailbox (with SMTP/IMAP config)

```
GET /api/v1/mailboxes/:id?include_config=1
```

When `include_config=1` is passed, the response includes SMTP and IMAP server settings (passwords excluded).

**Response with config:**

```json
{
  "status": "success",
  "data": {
    "id": 4,
    "name": "Support",
    "email": "support@example.com",
    "smtp": {
      "server": "smtp.example.com",
      "port": 587,
      "username": "user@example.com",
      "encryption": "tls"
    },
    "imap": {
      "server": "imap.example.com",
      "port": 993,
      "username": "user@example.com",
      "protocol": "imap",
      "encryption": "ssl",
      "validateCert": true
    }
  }
}
```

---

### Email History

#### Get Email History

```
GET /api/v1/emails
```

Returns all inbound and outbound email threads across mailboxes.

| Parameter | Type | Description |
|-----------|------|-------------|
| `mailbox_id` | int | Filter by mailbox |
| `conversation_id` | int | Filter by conversation |
| `direction` | string | `in` (customer messages) or `out` (agent replies) |
| `since` | datetime | Only emails after this date (ISO 8601) |
| `per_page` | int | Results per page (default: 50, max: 200) |

**Example:**

```bash
# Get all outbound emails from mailbox 4 since March 1
curl -H "X-Api-Key: YOUR_KEY" \
  "https://support.example.com/api/v1/emails?mailbox_id=4&direction=out&since=2026-03-01"
```

---

### SMTP/IMAP Testing

#### Test SMTP Connection

```
POST /api/v1/mailboxes/:id/test-smtp
```

Tests the SMTP connection for the specified mailbox. Returns success or error with details.

#### Test IMAP Connection

```
POST /api/v1/mailboxes/:id/test-imap
```

Tests the IMAP connection and returns mailbox stats:

```json
{
  "status": "success",
  "message": "IMAP connection successful.",
  "data": {
    "messages": 152,
    "recent": 0,
    "unread": 3
  }
}
```

#### Send Test Email

```
POST /api/v1/mailboxes/:id/send-test
```

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `to` | string | Yes | Recipient email address |
| `subject` | string | No | Email subject (default: "FreeScout API Test Email") |
| `body` | string | No | Email body text |

---

## Security

### API Key Authentication
Every request must include a valid API key. Keys are 48-character hex strings generated with `random_bytes()`.

### IP Whitelisting
Each API key can have an IP whitelist. Supports:
- Single IPs: `192.168.1.100`
- CIDR ranges: `10.0.0.0/8`
- Multiple: `192.168.1.1, 10.0.0.0/24`
- Empty = allow all IPs

### Rate Limiting
60 requests per minute per API key. Returns `429 Too Many Requests` when exceeded.

### Request Logging
Every API call is logged with:
- Timestamp
- API key used
- HTTP method and endpoint
- Client IP address
- Response status code
- Response time (ms)
- Request body (passwords/secrets stripped)

Logs are viewable at **Manage > API & Webhooks** in the admin panel.

### Input Validation
- All inputs validated via Laravel's validation rules
- SQL injection prevented via Eloquent ORM parameterized queries
- XSS prevented via `e()` output escaping
- API key format enforced via regex (`/^[a-f0-9]{48}$/`)

### What is NOT exposed
- User passwords
- API secrets (shown only once at creation)
- Mailbox SMTP/IMAP passwords (never returned in API responses)
- Internal file paths

---

## HTTP Status Codes

| Code | Description |
|------|-------------|
| 200 | Success |
| 201 | Created (new resource) |
| 401 | Missing or invalid API key |
| 403 | IP not whitelisted / insufficient permissions |
| 404 | Resource not found |
| 422 | Validation error |
| 429 | Rate limit exceeded |
| 500 | Server error |

---

## Admin Interface

Navigate to **Manage > API & Webhooks** to:

- Create and manage API keys
- Set IP whitelists per key
- Enable/disable keys without deleting them
- View real-time API request logs
- See endpoint reference documentation

---

## Module Structure

```
Modules/ApiWebhooks/
├── module.json                          # Module metadata
├── README.md                            # This file
├── Database/
│   └── Migrations/
│       ├── 2026_03_06_000001_create_api_keys_table.php
│       ├── 2026_03_06_200000_add_detail_columns_to_api_logs.php
│       └── 2026_03_07_000001_add_last_login_at_to_users_table.php
├── Http/
│   ├── Controllers/
│   │   ├── ApiController.php            # All API endpoints
│   │   └── ApiKeysController.php        # Admin key management
│   ├── Middleware/
│   │   └── ApiAuth.php                  # Auth, IP check, rate limit, logging
│   └── routes.php                       # Route definitions
├── Models/
│   ├── ApiKey.php                       # API key model with IP validation
│   └── ApiLog.php                       # Request log model
├── Providers/
│   └── ApiWebhooksServiceProvider.php   # Module bootstrap
├── Resources/
│   └── views/
│       └── settings.blade.php           # Admin settings page
├── Transformers/
│   ├── ConversationTransformer.php      # Conversation JSON formatter
│   ├── ThreadTransformer.php            # Thread JSON formatter
│   ├── CustomerTransformer.php          # Customer JSON formatter
│   ├── UserTransformer.php              # User JSON formatter
│   └── MailboxTransformer.php           # Mailbox JSON formatter
└── Public/                              # Static assets (empty)
```

---

## Differences from Paid Module

| Feature | Paid Module | This Module |
|---------|-------------|-------------|
| Price | $12.99 | Free / Open Source |
| API Key Auth | Yes | Yes |
| IP Whitelisting | No | Yes |
| Rate Limiting | No | Yes (60/min) |
| Request Logging | No | Yes (full audit trail) |
| CIDR Support | No | Yes |
| Webhooks | Yes | Planned |
| CORS Config | Yes | Planned |
| SMTP/IMAP Testing | No | Yes |
| Test Email Sending | No | Yes |

---

## License

AGPL-3.0 — Same as FreeScout.

## Author

**Michael Peres** — [GitHub](https://github.com/mikeyperes)

## Contributing

Pull requests welcome. Please ensure:
- No security vulnerabilities (OWASP Top 10)
- All inputs validated
- All API calls logged
- No hardcoded credentials, tokens, or sensitive data
