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

    public static function transform(User $u)
    {
        return [
            'id'        => $u->id,
            'firstName' => $u->first_name,
            'lastName'  => $u->last_name,
            'email'     => $u->email,
            'role'      => self::$roleMap[$u->role] ?? 'user',
            'status'    => self::$statusMap[$u->status] ?? 'unknown',
            'jobTitle'  => $u->job_title,
            'phone'     => $u->phone,
            'timezone'  => $u->timezone,
            'photoUrl'  => $u->getPhotoUrl(),
            'createdAt' => $u->created_at ? date('c', strtotime($u->created_at)) : null,
        ];
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
