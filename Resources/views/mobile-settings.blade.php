@extends('layouts.app')

@section('title', 'Mobile API settings')

@section('content')
<div class="container">
    <h1>Mobile API settings</h1>
    @foreach(['flash_success' => 'success', 'flash_error' => 'danger'] as $message => $style)
        @if(session($message))
            <div class="alert alert-{{ $style }}">{{ session($message) }}</div>
        @endif
    @endforeach
    @if($errors->any())
        <div class="alert alert-danger">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif
    <p>FreeScout mobile API, server credentials, push notifications and outgoing webhooks.</p>
    <p>
        <a href="{{ route('apiwebhooks.settings') }}">Legacy API keys and logs</a>
    </p>
    @if(session('mobile_new_token'))
        <div class="alert alert-success">Copy this server credential now. It will not be shown again.<div id="mobile-new-server-token" style="overflow-wrap:anywhere">{{ decrypt(session('mobile_new_token')) }}</div>
            <button type="button" class="btn btn-default mobile-copy" data-copy="mobile-new-server-token">Copy</button>
        </div>
    @endif
    @if(session('mobile_webhook_secret'))
        <div class="alert alert-success">Copy this webhook signing secret now. It will not be shown again.<div id="mobile-new-webhook-secret" style="overflow-wrap:anywhere">{{ decrypt(session('mobile_webhook_secret')) }}</div>
            <button type="button" class="btn btn-default mobile-copy" data-copy="mobile-new-webhook-secret">Copy</button>
        </div>
    @endif
    <div class="panel panel-default">
        <div class="panel-heading">Setup instructions</div>
        <div class="panel-body">
            <ol>
                <li>Mobile clients request the login challenge, retain its cookie and CSRF token, then submit FreeScout credentials to the login endpoint. Store the returned device credential in the operating system's secure storage.</li>
                <li>Choose Own for assigned conversations, Broad for the agent's permitted mailboxes, or Root for an administrator. Existing AssignedOnly permissions always apply.</li>
                <li>Create server credentials below with explicit allowed IPs. Sign each request with its method, full request URI, timestamp, nonce and body digest as documented in the README.</li>
                <li>For Apple push, supply JSON containing team_id, key_id, private_key, topic and optional sandbox. Obtain the signing key in Apple Developer, Certificates, Identifiers and Profiles.</li>
                <li>For Firebase push, paste a Google service-account JSON containing client_email, private_key and project_id. Enable the Firebase Cloud Messaging HTTP v1 API for that project.</li>
                <li>Register the signed-in user's device through the device endpoint and enable Mobile notification subscriptions before testing. Test sends fixed content only to your own registered device.</li>
                <li>Keep FreeScout's normal scheduler running. It processes delivery retries, snooze expiry and log retention.</li>
            </ol>
            <p>Manual fallback: agents can use the existing FreeScout web interface.</p>
            <input id="mobile-api-fallback" class="form-control" value="{{ url('/login') }}" readonly>
            <button class="btn btn-default mobile-copy" data-copy="mobile-api-fallback" type="button">Copy</button>
        </div>
    </div>
    <form method="POST" action="{{ route('apiwebhooks.mobile.save') }}" class="mobile-saving-form">
        {{ csrf_field() }}
        <div class="panel panel-default">
            <div class="panel-heading">Credentials and limits</div>
            <div class="panel-body">
                @foreach(['apns' => $apnsPresent, 'fcm' => $fcmPresent] as $provider => $present)
                    <div class="form-group">
                        <label>{{ strtoupper($provider) }} credentials</label>
                        <p class="help-block">Saved credentials are encrypted and never returned to the browser. Use Change to replace them.</p>
                        @if($present)
                            <input type="text" class="form-control" value="•••••••• (configured)" readonly>
                            <button type="button" class="btn btn-default mobile-change" data-field="{{ $provider }}_credentials">Change</button>
                        @endif
                        <textarea id="{{ $provider }}_credentials" @if(!$present) name="{{ $provider }}_credentials" @endif class="form-control" rows="5" @if($present) hidden @endif autocomplete="off" placeholder="Paste provider credential JSON">
                        </textarea>
                    </div>
                @endforeach
                @foreach(\Modules\ApiWebhooks\Services\ApiSettings::limits() as $field => $definition)
                    <div class="form-group">
                        <label>{{ $definition['label'] }}</label>
                        <input class="form-control" type="number" min="1" name="{{ $field }}" value="{{ \Modules\ApiWebhooks\Services\ApiSettings::value($field) }}" required>
                    </div>
                @endforeach
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </div>
    </form>
    @foreach(['apns', 'fcm'] as $provider)
        <form method="POST" action="{{ route('apiwebhooks.mobile.push-test') }}" class="mobile-saving-form" style="display:inline-block">
            {{ csrf_field() }}
            <input type="hidden" name="provider" value="{{ $provider }}">
            <button type="submit" class="btn btn-default">Test {{ strtoupper($provider) }}</button>
        </form>
    @endforeach
    <h2>Server credentials</h2>
    <form method="POST" action="{{ route('apiwebhooks.mobile.server') }}" class="mobile-saving-form">
        {{ csrf_field() }}
        <div class="form-group">
            <label>Name</label>
            <input name="name" class="form-control" maxlength="100" required>
        </div>
        <div class="form-group">
            <label>Agent identity</label>
            <select name="user_id" class="form-control">
                @foreach($users as $user)
                    <option value="{{ $user->id }}">{{ $user->first_name }} {{ $user->last_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label>Access level</label>
            <select name="access_level" class="form-control">
                <option value="own">Own</option>
                <option value="broad">Broad</option>
                <option value="root">Root (administrator only)</option>
            </select>
        </div>
        <div class="form-group">
            <label>Allowed IPs (comma-separated addresses or CIDRs)</label>
            <input name="allowed_ips" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">Create server credential</button>
    </form>
    @foreach($serverTokens as $token)
        <div class="well">{{ $token->name }} · {{ $token->access_level }} · {{ $token->revoked_at ? 'Revoked' : 'Active' }}
            <input type="text" class="form-control" value="•••••••• (stored as a hash)" readonly>
            <p>To change a server credential, create its replacement, update the client, then revoke the old one.</p>
            <form method="POST" action="{{ route('apiwebhooks.mobile.server-test', $token->id) }}" class="mobile-saving-form">
                {{ csrf_field() }}
                <label>Credential to test (not saved)</label>
                <input type="password" name="candidate" class="form-control" autocomplete="off" required>
                <button type="submit" class="btn btn-default">Test signed request</button>
            </form>
            <form method="POST" action="{{ route('apiwebhooks.mobile.server-revoke', $token->id) }}" class="mobile-saving-form">{{ csrf_field() }}
                <button type="submit" class="btn btn-danger">Revoke</button>
            </form>
        </div>
    @endforeach
    <h2>Outgoing webhooks</h2>
    <p>Use an HTTPS receiver on port 443 that resolves only to public IP addresses. Verify X-FreeScout-Signature and deduplicate X-FreeScout-Event. Failed deliveries retry five times with increasing delays.</p>
    <form method="POST" action="{{ route('apiwebhooks.mobile.webhook') }}" class="mobile-saving-form">
        {{ csrf_field() }}
        <div class="form-group">
            <label>Name</label>
            <input name="name" class="form-control" required maxlength="100">
        </div>
        <div class="form-group">
            <label>Receiver URL</label>
            <input type="url" name="url" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">Add webhook</button>
    </form>
    @foreach($webhooks as $webhook)
        <div class="well" style="overflow-wrap:anywhere">
            <strong>{{ $webhook->name }}</strong> · {{ $webhook->active ? 'Active' : 'Disabled' }}<p>
                <a href="{{ $webhook->url }}" target="_blank" rel="noopener noreferrer">{{ $webhook->url }} ↗</a>
            </p>
            <form method="POST" action="{{ route('apiwebhooks.mobile.webhook-test', $webhook->id) }}" class="mobile-saving-form" style="display:inline-block">{{ csrf_field() }}
                <button type="submit" class="btn btn-default">Test</button>
            </form>
            <form method="POST" action="{{ route('apiwebhooks.mobile.webhook-delete', $webhook->id) }}" class="mobile-saving-form" style="display:inline-block">{{ csrf_field() }}
                <button type="submit" class="btn btn-danger">Disable</button>
            </form>
        </div>
    @endforeach
    <h2>Saved replies</h2>
    <form method="POST" action="{{ route('apiwebhooks.mobile.saved-reply') }}" class="mobile-saving-form">
        {{ csrf_field() }}
        <div class="form-group">
            <label>Name</label>
            <input name="name" class="form-control" required maxlength="100">
        </div>
        <div class="form-group">
            <label>Reply body</label>
            <textarea name="body" class="form-control" rows="5" required>
            </textarea>
        </div>
        <div class="form-group">
            <label>Mailbox</label>
            <select name="mailbox_id" class="form-control">
                <option value="">All permitted mailboxes</option>
                @foreach($mailboxes as $mailbox)
                    <option value="{{ $mailbox->id }}">{{ $mailbox->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label>Agent</label>
            <select name="user_id" class="form-control">
                <option value="">All agents</option>
                @foreach($users as $user)
                    <option value="{{ $user->id }}">{{ $user->first_name }} {{ $user->last_name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Add saved reply</button>
    </form>
    @foreach($savedReplies as $reply)
        <div class="well">
            <strong>{{ $reply->name }}</strong>
            <div style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $reply->body }}</div>
            <form method="POST" action="{{ route('apiwebhooks.mobile.saved-reply-delete', $reply->id) }}" class="mobile-saving-form">{{ csrf_field() }}
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        </div>
    @endforeach
    <h2>Delivery log</h2>
    <p>Only event IDs and delivery status are retained here. Provider credentials, device destinations, response bodies and ticket content are excluded.</p>
    <div style="background:#17202a;color:#fff;padding:16px;overflow-wrap:anywhere">
        <p>Administrator {{ auth()->id() }} · Mobile API delivery history</p>
        @forelse($deliveries as $delivery)
        <p id="mobile-delivery-{{ $delivery->id }}">#{{ $delivery->id }} · {{ $delivery->kind }} · Destination #{{ $delivery->destination_id }} · Attempts {{ $delivery->attempts }} · HTTP {{ $delivery->status_code }} · {{ $delivery->delivered_at ? 'Delivered' : ($delivery->next_attempt_at ? 'Retry scheduled' : 'Stopped') }}</p>
            <button type="button" class="btn btn-default mobile-copy" data-copy="mobile-delivery-{{ $delivery->id }}">Copy</button>
        @empty
        <p>No deliveries recorded.</p>
        @endforelse
    </div>
</div>
@endsection


@section('javascript')
    $('.mobile-change').on('click', function () {
        var field = $(this).data('field');
        $('#' + field).attr('name', field).prop('hidden', false).focus();
    });
    $('.mobile-saving-form').on('submit', function () {
        $(this).find('button[type="submit"]').prop('disabled', true).html('<i class="glyphicon glyphicon-refresh"></i> Saving...');
    });
    $('.mobile-copy').on('click', function () {
        var input = document.getElementById($(this).data('copy'));
        if (typeof input.select === 'function') {
            input.select();
        } else {
            var range = document.createRange();
            range.selectNodeContents(input);
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        }
        document.execCommand('copy');
        $(this).text('Copied');
    });
@endsection
