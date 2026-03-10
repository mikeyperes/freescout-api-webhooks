@extends('layouts.app')

@section('title_full', 'API & Webhooks - ' . config('app.name'))

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h3>API & Webhooks</h3>

            @if(session('flash_success'))
                <div class="alert alert-success">{{ session('flash_success') }}</div>
            @endif

            @if(session('new_api_key'))
                <div class="alert alert-warning">
                    <strong>Save these credentials now - the secret will not be shown again!</strong><br>
                    <code>API Key: {{ session('new_api_key') }}</code><br>
                    <code>API Secret: {{ session('new_api_secret') }}</code>
                </div>
            @endif

            {{-- Create Key --}}
            <div class="panel panel-default">
                <div class="panel-heading"><strong>Create API Key</strong></div>
                <div class="panel-body">
                    <form method="POST" action="{{ route('apiwebhooks.keys.create') }}">
                        {{ csrf_field() }}
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Name</label>
                                    <input type="text" name="name" class="form-control" placeholder="e.g. n8n Integration" required maxlength="100">
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="form-group">
                                    <label>Allowed IPs <small class="text-muted">(comma-separated, blank = all)</small></label>
                                    <input type="text" name="allowed_ips" class="form-control" placeholder="e.g. 192.168.1.1, 10.0.0.0/24">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-primary btn-block">Generate Key</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            {{-- API Keys List --}}
            <div class="panel panel-default">
                <div class="panel-heading"><strong>API Keys</strong></div>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>API Key</th>
                            <th>Allowed IPs</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($keys as $key)
                            <tr>
                                <td>{{ $key->name }}</td>
                                <td><code style="font-size:11px;">{{ substr($key->api_key, 0, 12) }}...{{ substr($key->api_key, -4) }}</code></td>
                                <td><small>{{ $key->allowed_ips ?: 'All' }}</small></td>
                                <td>
                                    @if($key->active)
                                        <span class="label label-success">Active</span>
                                    @else
                                        <span class="label label-default">Disabled</span>
                                    @endif
                                </td>
                                <td><small>{{ $key->created_at->format('M j, Y') }}</small></td>
                                <td>
                                    <form method="POST" action="{{ route('apiwebhooks.keys.toggle', $key->id) }}" style="display:inline;">
                                        {{ csrf_field() }}
                                        <button type="submit" class="btn btn-xs btn-default">{{ $key->active ? 'Disable' : 'Enable' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('apiwebhooks.keys.delete', $key->id) }}" style="display:inline;" onsubmit="return confirm('Delete this key and all its logs?');">
                                        {{ csrf_field() }}
                                        <button type="submit" class="btn btn-xs btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted text-center">No API keys yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- API Documentation --}}
            <div class="panel panel-default">
                <div class="panel-heading"><strong>API Documentation</strong></div>
                <div class="panel-body">
                    <p><strong>Base URL:</strong> <code>{{ url('/api/v1') }}</code></p>
                    <p><strong>Authentication:</strong> Pass your API key via <code>X-Api-Key</code> header (recommended) or <code>?api_key=</code> query param.</p>
                    <p><strong>Content-Type:</strong> <code>application/json</code> for POST/PUT requests.</p>
                    <p><strong>Rate Limit:</strong> 60 requests/minute per key. Returns <code>429</code> when exceeded.</p>
                    <hr>

                    @php $base = url('/api/v1'); @endphp

                    {{-- CONVERSATIONS --}}
                    <h4 style="margin-top:20px; border-bottom:2px solid #337ab7; padding-bottom:5px; color:#337ab7;">Conversations</h4>

                    {{-- List Conversations --}}
                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-primary">GET</span> <code>/conversations</code> &mdash; List/search conversations</p>
                        <p><small class="text-muted">Query params: <code>mailbox_id</code>, <code>status</code> (active|pending|closed|spam), <code>state</code> (published|draft|deleted), <code>assignee</code> (user ID), <code>customer_id</code>, <code>search</code>, <code>sort_by</code>, <code>order</code> (asc|desc), <code>per_page</code> (max 200), <code>page</code></small></p>
<pre style="font-size:11px; background:#f5f5f5; padding:10px; max-height:200px; overflow:auto;">curl -H "X-Api-Key: YOUR_KEY" "{{ $base }}/conversations?status=active&search=refund&per_page=10"

# Response:
{
  "status": "success",
  "page": 1,
  "pages": 1,
  "total": 2,
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
      "assignee": { "id": 6, "firstName": "Jane", "lastName": "Smith", "email": "jane@example.com" },
      "customer": { "id": 91, "firstName": "Rodney", "lastName": "Robertson", "email": "rodney@example.org" },
      "threadsCount": 5,
      "createdAt": "2026-03-01T10:00:00+00:00",
      "updatedAt": "2026-03-05T14:07:23+00:00"
    }
  ]
}</pre>
                    </div>

                    {{-- Get Conversation --}}
                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-primary">GET</span> <code>/conversations/:id</code> &mdash; Get conversation with all threads</p>
<pre style="font-size:11px; background:#f5f5f5; padding:10px; max-height:180px; overflow:auto;">curl -H "X-Api-Key: YOUR_KEY" "{{ $base }}/conversations/44"

# Response includes _embedded.threads array with full thread bodies</pre>
                    </div>

                    {{-- Create Conversation --}}
                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-success">POST</span> <code>/conversations</code> &mdash; Create a new conversation</p>
                        <table class="table table-condensed" style="font-size:12px; margin-bottom:5px;">
                            <tr><th>Field</th><th>Required</th><th>Description</th></tr>
                            <tr><td><code>mailbox_id</code></td><td>Yes</td><td>Target mailbox ID</td></tr>
                            <tr><td><code>subject</code></td><td>Yes</td><td>Conversation subject</td></tr>
                            <tr><td><code>customer_email</code></td><td>Yes</td><td>Customer email (auto-creates if new)</td></tr>
                            <tr><td><code>body</code></td><td>Yes</td><td>First message body (HTML allowed)</td></tr>
                            <tr><td><code>assignee</code></td><td>No</td><td>Assign to user ID</td></tr>
                            <tr><td><code>status</code></td><td>No</td><td>active (default), pending, closed</td></tr>
                            <tr><td><code>type</code></td><td>No</td><td>email (default), phone, chat</td></tr>
                            <tr><td><code>cc</code></td><td>No</td><td>CC emails, comma-separated</td></tr>
                            <tr><td><code>bcc</code></td><td>No</td><td>BCC emails, comma-separated</td></tr>
                            <tr><td><code>customer_first_name</code></td><td>No</td><td>New customer's first name</td></tr>
                            <tr><td><code>customer_last_name</code></td><td>No</td><td>New customer's last name</td></tr>
                        </table>
<pre style="font-size:11px; background:#f5f5f5; padding:10px; max-height:200px; overflow:auto;">curl -X POST "{{ $base }}/conversations" \
  -H "X-Api-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "mailbox_id": 4,
    "subject": "Order #12345 issue",
    "customer_email": "customer@example.com",
    "customer_first_name": "John",
    "customer_last_name": "Doe",
    "body": "&lt;p&gt;I have an issue with my order.&lt;/p&gt;",
    "assignee": 6,
    "status": "active"
  }'

# Response: { "status": "success", "data": { "id": 45, ... } }</pre>
                    </div>

                    {{-- Update Conversation --}}
                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-warning">PUT</span> <code>/conversations/:id</code> &mdash; Update conversation (status, assignee, subject)</p>
<pre style="font-size:11px; background:#f5f5f5; padding:10px; max-height:150px; overflow:auto;">curl -X PUT "{{ $base }}/conversations/44" \
  -H "X-Api-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "status": "closed", "assignee": 3 }'

# Response: { "status": "success", "data": { "id": 44, "status": "closed", ... } }</pre>
                    </div>

                    {{-- Delete Conversation --}}
                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-danger">DELETE</span> <code>/conversations/:id</code> &mdash; Soft-delete conversation</p>
<pre style="font-size:11px; background:#f5f5f5; padding:10px;">curl -X DELETE "{{ $base }}/conversations/44" -H "X-Api-Key: YOUR_KEY"

# Response: { "status": "success", "message": "Conversation deleted." }</pre>
                    </div>

                    {{-- THREADS --}}
                    <h4 style="margin-top:25px; border-bottom:2px solid #337ab7; padding-bottom:5px; color:#337ab7;">Threads (Replies &amp; Notes)</h4>

                    {{-- List Threads --}}
                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-primary">GET</span> <code>/conversations/:id/threads</code> &mdash; List all threads for a conversation</p>
<pre style="font-size:11px; background:#f5f5f5; padding:10px; max-height:180px; overflow:auto;">curl -H "X-Api-Key: YOUR_KEY" "{{ $base }}/conversations/44/threads"

# Response:
{
  "status": "success",
  "data": [
    {
      "id": 120,
      "type": "customer",
      "body": "Hi, I need help with...",
      "createdBy": { "id": 91, "type": "customer", "name": "Rodney Robertson", "email": "rodney@example.org" },
      "createdAt": "2026-03-05T14:07:23+00:00"
    }
  ]
}</pre>
                    </div>

                    {{-- Create Thread --}}
                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-success">POST</span> <code>/conversations/:id/threads</code> &mdash; Add a reply or note</p>
                        <table class="table table-condensed" style="font-size:12px; margin-bottom:5px;">
                            <tr><th>Field</th><th>Required</th><th>Description</th></tr>
                            <tr><td><code>body</code></td><td>Yes</td><td>Thread body (HTML allowed)</td></tr>
                            <tr><td><code>type</code></td><td>No</td><td>note (default), message (agent reply), customer</td></tr>
                            <tr><td><code>user_id</code></td><td>No</td><td>User creating the thread</td></tr>
                        </table>
<pre style="font-size:11px; background:#f5f5f5; padding:10px; max-height:180px; overflow:auto;"># Add an internal note:
curl -X POST "{{ $base }}/conversations/44/threads" \
  -H "X-Api-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "body": "Customer called about this.", "type": "note", "user_id": 1 }'

# Send an agent reply:
curl -X POST "{{ $base }}/conversations/44/threads" \
  -H "X-Api-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "body": "&lt;p&gt;Thanks for contacting us. We are looking into it.&lt;/p&gt;", "type": "message", "user_id": 1 }'

# Response: { "status": "success", "data": { "id": 121, "type": "note", ... } }</pre>
                    </div>

                    {{-- CUSTOMERS --}}
                    <h4 style="margin-top:25px; border-bottom:2px solid #5cb85c; padding-bottom:5px; color:#5cb85c;">Customers</h4>

                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-primary">GET</span> <code>/customers</code> &mdash; List/search customers</p>
                        <p><small class="text-muted">Query params: <code>search</code> (name, company, or email), <code>per_page</code> (max 200), <code>page</code></small></p>
<pre style="font-size:11px; background:#f5f5f5; padding:10px;">curl -H "X-Api-Key: YOUR_KEY" "{{ $base }}/customers?search=rodney"</pre>
                    </div>

                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-primary">GET</span> <code>/customers/:id</code> &mdash; Get customer with all details (emails, phones, social, address)</p>
<pre style="font-size:11px; background:#f5f5f5; padding:10px;">curl -H "X-Api-Key: YOUR_KEY" "{{ $base }}/customers/91"</pre>
                    </div>

                    {{-- USERS --}}
                    <h4 style="margin-top:25px; border-bottom:2px solid #f0ad4e; padding-bottom:5px; color:#f0ad4e;">Users (Agents)</h4>

                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-primary">GET</span> <code>/users</code> &mdash; List all agents</p>
<pre style="font-size:11px; background:#f5f5f5; padding:10px;">curl -H "X-Api-Key: YOUR_KEY" "{{ $base }}/users"</pre>
                    </div>

                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-primary">GET</span> <code>/users/:id</code> &mdash; Get single agent</p>
<pre style="font-size:11px; background:#f5f5f5; padding:10px;">curl -H "X-Api-Key: YOUR_KEY" "{{ $base }}/users/1"</pre>
                    </div>

                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-success">POST</span> <code>/users</code> &mdash; Create a new agent</p>
                        <table class="table table-condensed" style="font-size:12px; margin-bottom:5px;">
                            <tr><th>Field</th><th>Required</th><th>Description</th></tr>
                            <tr><td><code>email</code></td><td>Yes</td><td>Unique email address</td></tr>
                            <tr><td><code>first_name</code></td><td>Yes</td><td>First name (max 20 chars)</td></tr>
                            <tr><td><code>last_name</code></td><td>Yes</td><td>Last name (max 30 chars)</td></tr>
                            <tr><td><code>password</code></td><td>No</td><td>Min 8 chars (auto-generated if omitted)</td></tr>
                            <tr><td><code>role</code></td><td>No</td><td>user (default) or admin</td></tr>
                            <tr><td><code>mailbox_ids</code></td><td>No</td><td>Comma-separated mailbox IDs</td></tr>
                        </table>
<pre style="font-size:11px; background:#f5f5f5; padding:10px;">curl -X POST "{{ $base }}/users" \
  -H "X-Api-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "newagent@example.com",
    "first_name": "Sarah",
    "last_name": "Connor",
    "role": "user",
    "mailbox_ids": "4,5"
  }'

# Response: { "status": "success", "data": { "id": 9, "password": "auto_generated_pw", ... } }</pre>
                    </div>

                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-success">POST</span> <code>/users/:id/disable</code> / <code>/users/:id/enable</code> &mdash; Disable or enable an agent</p>
<pre style="font-size:11px; background:#f5f5f5; padding:10px;">curl -X POST "{{ $base }}/users/9/disable" -H "X-Api-Key: YOUR_KEY"
# Response: { "status": "success", "message": "User disabled." }

curl -X POST "{{ $base }}/users/9/enable" -H "X-Api-Key: YOUR_KEY"
# Response: { "status": "success", "message": "User enabled." }</pre>
                        <p><small class="text-muted">Note: Admin users cannot be disabled via API.</small></p>
                    </div>

                    {{-- MAILBOXES --}}
                    <h4 style="margin-top:25px; border-bottom:2px solid #5bc0de; padding-bottom:5px; color:#5bc0de;">Mailboxes</h4>

                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-primary">GET</span> <code>/mailboxes</code> &mdash; List all mailboxes</p>
<pre style="font-size:11px; background:#f5f5f5; padding:10px;">curl -H "X-Api-Key: YOUR_KEY" "{{ $base }}/mailboxes"</pre>
                    </div>

                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-primary">GET</span> <code>/mailboxes/:id?include_config=1</code> &mdash; Get mailbox with SMTP/IMAP config</p>
                        <p><small class="text-muted">Pass <code>include_config=1</code> to include server settings. Passwords are never returned.</small></p>
<pre style="font-size:11px; background:#f5f5f5; padding:10px; max-height:250px; overflow:auto;">curl -H "X-Api-Key: YOUR_KEY" "{{ $base }}/mailboxes/4?include_config=1"

# Response:
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
}</pre>
                    </div>

                    {{-- EMAIL HISTORY --}}
                    <h4 style="margin-top:25px; border-bottom:2px solid #777; padding-bottom:5px; color:#777;">Email History</h4>

                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-primary">GET</span> <code>/emails</code> &mdash; All inbound/outbound email threads</p>
                        <p><small class="text-muted">Query params: <code>mailbox_id</code>, <code>conversation_id</code>, <code>direction</code> (in|out), <code>since</code> (ISO 8601 date), <code>per_page</code>, <code>page</code></small></p>
<pre style="font-size:11px; background:#f5f5f5; padding:10px;">curl -H "X-Api-Key: YOUR_KEY" "{{ $base }}/emails?direction=out&mailbox_id=4&since=2026-03-01"</pre>
                    </div>

                    {{-- SMTP/IMAP TESTING --}}
                    <h4 style="margin-top:25px; border-bottom:2px solid #d9534f; padding-bottom:5px; color:#d9534f;">Testing &amp; Diagnostics</h4>

                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-success">POST</span> <code>/mailboxes/:id/test-smtp</code> &mdash; Test SMTP connection</p>
<pre style="font-size:11px; background:#f5f5f5; padding:10px;">curl -X POST "{{ $base }}/mailboxes/4/test-smtp" -H "X-Api-Key: YOUR_KEY"

# Success: { "status": "success", "message": "SMTP connection successful." }
# Failure: { "status": "error", "message": "SMTP error: Connection timed out" }</pre>
                    </div>

                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-success">POST</span> <code>/mailboxes/:id/test-imap</code> &mdash; Test IMAP connection + get stats</p>
<pre style="font-size:11px; background:#f5f5f5; padding:10px;">curl -X POST "{{ $base }}/mailboxes/4/test-imap" -H "X-Api-Key: YOUR_KEY"

# Response:
{
  "status": "success",
  "message": "IMAP connection successful.",
  "data": { "messages": 152, "recent": 0, "unread": 3 }
}</pre>
                    </div>

                    <div class="api-endpoint" style="margin-bottom:20px;">
                        <p><span class="label label-success">POST</span> <code>/mailboxes/:id/send-test</code> &mdash; Send a test email</p>
                        <table class="table table-condensed" style="font-size:12px; margin-bottom:5px;">
                            <tr><th>Field</th><th>Required</th><th>Description</th></tr>
                            <tr><td><code>to</code></td><td>Yes</td><td>Recipient email address</td></tr>
                            <tr><td><code>subject</code></td><td>No</td><td>Email subject (default: "FreeScout API Test Email")</td></tr>
                            <tr><td><code>body</code></td><td>No</td><td>Email body text</td></tr>
                        </table>
<pre style="font-size:11px; background:#f5f5f5; padding:10px;">curl -X POST "{{ $base }}/mailboxes/4/send-test" \
  -H "X-Api-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "to": "test@example.com", "subject": "API Test", "body": "This is a test." }'

# Response: { "status": "success", "message": "Test email sent to test@example.com." }</pre>
                    </div>

                    {{-- ERROR CODES --}}
                    <h4 style="margin-top:25px; border-bottom:2px solid #333; padding-bottom:5px;">HTTP Status Codes</h4>
                    <table class="table table-condensed" style="font-size:12px;">
                        <tr><td><code>200</code></td><td>Success</td></tr>
                        <tr><td><code>201</code></td><td>Created (new resource)</td></tr>
                        <tr><td><code>401</code></td><td>Missing or invalid API key</td></tr>
                        <tr><td><code>403</code></td><td>IP not whitelisted</td></tr>
                        <tr><td><code>404</code></td><td>Resource not found</td></tr>
                        <tr><td><code>422</code></td><td>Validation error (check response message)</td></tr>
                        <tr><td><code>429</code></td><td>Rate limit exceeded (60/min per key)</td></tr>
                        <tr><td><code>500</code></td><td>Server error</td></tr>
                    </table>
                </div>
            </div>

            {{-- Logs --}}
            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>API Logs</strong>
                    <form method="POST" action="{{ route('apiwebhooks.logs.clear') }}" style="display:inline; float:right;">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-xs btn-default" onclick="return confirm('Clear all logs?');">Clear Logs</button>
                    </form>
                </div>
                <table class="table table-condensed" style="font-size:12px;">
                    <thead>
                        <tr>
                            <th>Time (EST)</th>
                            <th>Key</th>
                            <th>Method</th>
                            <th>Endpoint</th>
                            <th>IP / Location</th>
                            <th>Client</th>
                            <th>Status</th>
                            <th>ms</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs as $log)
                            <tr class="{{ $log->status_code >= 400 ? 'danger' : '' }}" style="cursor:pointer;" onclick="$('#log-detail-{{ $log->id }}').toggle(); $(this).find('.glyphicon').toggleClass('glyphicon-chevron-down glyphicon-chevron-up');">
                                <td style="white-space:nowrap;">{{ $log->time_est }}</td>
                                <td>{{ $log->api_key_id ? optional($log->apiKey)->name : '-' }}</td>
                                <td><strong>{{ $log->method }}</strong></td>
                                <td><span title="{{ $log->query_string ? $log->endpoint . '?' . $log->query_string : $log->endpoint }}">{{ $log->endpoint }}</span></td>
                                <td>
                                    {{ $log->ip }}
                                    @if($log->city && $log->city !== 'Local')
                                        <br><small class="text-muted">{{ $log->city }}, {{ $log->country }}</small>
                                    @elseif($log->country)
                                        <br><small class="text-muted">{{ $log->country }}</small>
                                    @endif
                                </td>
                                <td><small title="{{ e($log->user_agent) }}">{{ $log->device_summary }}</small></td>
                                <td><span class="label label-{{ $log->status_code < 400 ? 'success' : ($log->status_code < 500 ? 'warning' : 'danger') }}">{{ $log->status_code }}</span></td>
                                <td>{{ $log->response_time_ms }}</td>
                                <td><i class="glyphicon glyphicon-chevron-down"></i></td>
                            </tr>
                            <tr id="log-detail-{{ $log->id }}" style="display:none;">
                                <td colspan="9" style="background:#f9f9f9; padding:12px;">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Request Details:</strong>
                                            <pre style="font-size:11px; max-height:200px; overflow:auto; white-space:pre-wrap;">{{ $log->method }} /{{ $log->endpoint }}{{ $log->query_string ? '?' . $log->query_string : '' }}

IP: {{ $log->ip }}
Location: {{ ($log->city ?: '?') . ', ' . ($log->country ?: '?') }}
Client: {{ $log->device_summary }}
Response: {{ $log->status_code }} ({{ $log->response_time_ms }}ms)</pre>
                                        </div>
                                        <div class="col-md-6">
                                            @if($log->request_body)
                                                <strong>Request Body:</strong>
                                                <pre style="font-size:11px; max-height:150px; overflow:auto; white-space:pre-wrap;">{{ json_encode(json_decode($log->request_body), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                            @endif
                                            @if($log->response_summary)
                                                <strong>Response:</strong>
                                                <pre style="font-size:11px; max-height:100px; overflow:auto; white-space:pre-wrap;">{{ $log->response_summary }}</pre>
                                            @endif
                                        </div>
                                    </div>
                                    @if($log->request_headers)
                                        <details style="margin-top:8px;">
                                            <summary style="cursor:pointer; font-weight:bold; font-size:12px;">Request Headers</summary>
                                            <pre style="font-size:11px; max-height:200px; overflow:auto; margin-top:5px; white-space:pre-wrap;">{{ $log->request_headers }}</pre>
                                        </details>
                                    @endif
                                    @if($log->user_agent)
                                        <div style="margin-top:5px;"><small class="text-muted"><strong>User-Agent:</strong> {{ e($log->user_agent) }}</small></div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-muted text-center">No API logs yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
