<?php

namespace Modules\ApiWebhooks\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ApiWebhooks\Models\ApiKey;
use Modules\ApiWebhooks\Models\ApiLog;

class ApiKeysController extends Controller
{
    public function index()
    {
        $keys = ApiKey::orderBy('created_at', 'desc')->get();
        $logs = ApiLog::orderBy('created_at', 'desc')->limit(100)->get();

        return view('apiwebhooks::settings', compact('keys', 'logs'));
    }

    public function create(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'allowed_ips' => 'nullable|string|max:1000',
        ]);

        $key = ApiKey::create([
            'name'               => e($request->input('name')),
            'api_key'            => ApiKey::generateKey(),
            'api_secret'         => ApiKey::generateSecret(),
            'allowed_ips'        => $request->input('allowed_ips') ? e(trim($request->input('allowed_ips'))) : null,
            'created_by_user_id' => auth()->id(),
            'active'             => true,
        ]);

        \Session::flash('flash_success', __('API key created.'));
        \Session::flash('new_api_key', $key->api_key);
        \Session::flash('new_api_secret', $key->api_secret);

        return redirect()->route('apiwebhooks.settings');
    }

    public function toggle($id)
    {
        $key = ApiKey::findOrFail($id);
        $key->active = !$key->active;
        $key->save();

        \Session::flash('flash_success', $key->active ? __('API key enabled.') : __('API key disabled.'));
        return redirect()->route('apiwebhooks.settings');
    }

    public function destroy($id)
    {
        $key = ApiKey::findOrFail($id);
        ApiLog::where('api_key_id', $key->id)->delete();
        $key->delete();

        \Session::flash('flash_success', __('API key deleted.'));
        return redirect()->route('apiwebhooks.settings');
    }

    public function clearLogs()
    {
        ApiLog::truncate();
        \Session::flash('flash_success', __('API logs cleared.'));
        return redirect()->route('apiwebhooks.settings');
    }
}
