<?php

namespace Modules\ApiWebhooks\Transformers;

use App\User;

class UserTransformer
{
    public static $roleMap = [
        User::ROLE_ADMIN => 'admin',
        User::ROLE_USER  => 'user',
    ];

    public static $statusMap = [
        User::STATUS_ACTIVE   => 'active',
        User::STATUS_DISABLED => 'disabled',
        User::STATUS_DELETED  => 'deleted',
    ];

    public static $inviteStateMap = [
        User::INVITE_STATE_ACTIVATED    => 'activated',
        User::INVITE_STATE_SENT         => 'invited',
        User::INVITE_STATE_NOT_INVITED  => 'not_invited',
    ];

    public static function transform(User $u)
    {
        $data = [
            'id'          => $u->id,
            'firstName'   => $u->first_name,
            'lastName'    => $u->last_name,
            'email'       => $u->email,
            'role'        => self::$roleMap[$u->role] ?? 'user',
            'status'      => self::$statusMap[$u->status] ?? 'unknown',
            'inviteState' => self::$inviteStateMap[$u->invite_state] ?? 'unknown',
            'type'        => $u->type == User::TYPE_ROBOT ? 'robot' : 'user',
            'jobTitle'    => $u->job_title,
            'phone'       => $u->phone,
            'timezone'    => $u->timezone,
            'photoUrl'    => $u->getPhotoUrl(),
            'locale'      => $u->locale,
            'permissions' => $u->permissions ?? [],
            'createdAt'   => $u->created_at ? date('c', strtotime($u->created_at)) : null,
            'updatedAt'   => $u->updated_at ? date('c', strtotime($u->updated_at)) : null,
        ];

        // Include last_login_at if the column exists (added by this module)
        if (isset($u->last_login_at)) {
            $data['lastLoginAt'] = $u->last_login_at ? date('c', strtotime($u->last_login_at)) : null;
        }

        // Include telegram_notification_enabled if it exists (from Telegram module)
        if (isset($u->telegram_notification_enabled)) {
            $data['telegramEnabled'] = (bool) $u->telegram_notification_enabled;
        }

        // Mailbox IDs this user has access to
        if (method_exists($u, 'mailboxes')) {
            try {
                $data['mailboxIds'] = $u->mailboxes->pluck('id')->toArray();
            } catch (\Exception $e) {
                $data['mailboxIds'] = [];
            }
        }

        return $data;
    }

    public static function brief($user)
    {
        if (!$user) {
            return null;
        }
        return [
            'id'        => $user->id,
            'type'      => 'user',
            'firstName' => $user->first_name,
            'lastName'  => $user->last_name,
            'email'     => $user->email,
        ];
    }
}
