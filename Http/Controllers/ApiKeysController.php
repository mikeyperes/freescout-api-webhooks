<?php

namespace Modules\ApiWebhooks\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ApiWebhooks\Models\ApiKey;
use Modules\ApiWebhooks\Models\ApiLog;

/**
 * ApiKeysController — Admin interface for managing API keys,
 * viewing logs, and handling IP whitelisting.
 */
class ApiKeysController extends Controller
{
    /**
     * Display the API & Webhooks settings page.
     * Includes: keys, logs, and blocked IPs.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $keys = ApiKey::orderBy('created_at', 'desc')->get();
        $logs = ApiLog::orderBy('created_at', 'desc')->limit(100)->get();

        // Blocked IPs: distinct IPs that received 403 responses
        $blockedIps = ApiLog::where('status_code', 403)
            ->selectRaw('ip, COUNT(*) as attempts, MAX(created_at) as last_attempt, MIN(created_at) as first_attempt')
            ->groupBy('ip')
            ->orderByDesc('last_attempt')
            ->limit(50)
            ->get()
            ->map(function ($row) {
                // Check geolocation from most recent log
                $latestLog = ApiLog::where('ip', $row->ip)
                    ->where('status_code', 403)
                    ->orderByDesc('created_at')
                    ->first();

                return [
                    'ip'            => $row->ip,
                    'attempts'      => $row->attempts,
                    'last_attempt'  => $row->last_attempt,
                    'first_attempt' => $row->first_attempt,
                    'country'       => $latestLog->country ?? null,
                    'city'          => $latestLog->city ?? null,
                    'user_agent'    => $latestLog->device_summary ?? 'Unknown',
                ];
            });

        return view('apiwebhooks::settings', compact('keys', 'logs', 'blockedIps'));
    }

    /**
     * Create a new API key.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function create(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:100',
            'allowed_ips' => 'required|string|max:1000',
        ]);

        $key = ApiKey::create([
            'name'               => e($request->input('name')),
            'api_key'            => ApiKey::generateKey(),
            'api_secret'         => ApiKey::generateSecret(),
            'allowed_ips'        => e(trim($request->input('allowed_ips'))),
            'created_by_user_id' => auth()->id(),
            'active'             => true,
        ]);

        \Session::flash('flash_success', __('API key created.'));
        \Session::flash('new_api_key', $key->api_key);
        \Session::flash('new_api_secret', $key->api_secret);

        return redirect()->route('apiwebhooks.settings');
    }

    /**
     * Update an existing API key's name and allowed IPs.
     *
     * @param Request $request
     * @param int     $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, $id)
    {
        $key = ApiKey::findOrFail($id);

        $request->validate([
            'name'        => 'required|string|max:100',
            'allowed_ips' => 'required|string|max:1000',
        ]);

        $key->name = e($request->input('name'));
        $key->allowed_ips = e(trim($request->input('allowed_ips')));
        $key->save();

        \Session::flash('flash_success', __('API key updated.'));
        return redirect()->route('apiwebhooks.settings');
    }

    /**
     * Toggle an API key's active status.
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function toggle($id)
    {
        $key = ApiKey::findOrFail($id);
        $key->active = !$key->active;
        $key->save();

        \Session::flash('flash_success', $key->active ? __('API key enabled.') : __('API key disabled.'));
        return redirect()->route('apiwebhooks.settings');
    }

    /**
     * Delete an API key and all its associated logs.
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy($id)
    {
        $key = ApiKey::findOrFail($id);
        ApiLog::where('api_key_id', $key->id)->delete();
        $key->delete();

        \Session::flash('flash_success', __('API key deleted.'));
        return redirect()->route('apiwebhooks.settings');
    }

    /**
     * Add a blocked IP to a specific API key's whitelist.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function whitelistIp(Request $request)
    {
        $request->validate([
            'ip'         => 'required|string|max:45',
            'api_key_id' => 'required|integer',
        ]);

        $key = ApiKey::findOrFail($request->input('api_key_id'));
        $ip = trim($request->input('ip'));

        $key->addAllowedIp($ip);

        \Session::flash('flash_success', __('IP :ip whitelisted for key ":name".', ['ip' => $ip, 'name' => $key->name]));
        return redirect()->route('apiwebhooks.settings');
    }

    /**
     * Truncate all API logs.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function clearLogs()
    {
        ApiLog::truncate();
        \Session::flash('flash_success', __('API logs cleared.'));
        return redirect()->route('apiwebhooks.settings');
    }
}
