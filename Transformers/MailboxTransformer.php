<?php

namespace Modules\ApiWebhooks\Transformers;

use App\Mailbox;

class MailboxTransformer
{
    public static function transform(Mailbox $m, $includeConfig = false)
    {
        $data = [
            'id'    => $m->id,
            'name'  => $m->name,
            'email' => $m->email,
            'aliases' => $m->aliases ? array_filter(explode(',', $m->aliases)) : [],
            'createdAt' => $m->created_at ? date('c', strtotime($m->created_at)) : null,
            'updatedAt' => $m->updated_at ? date('c', strtotime($m->updated_at)) : null,
        ];

        if ($includeConfig) {
            $encMap = [1 => 'none', 2 => 'ssl', 3 => 'tls', 4 => 'starttls'];
            $data['smtp'] = [
                'server'     => $m->out_server,
                'port'       => $m->out_port,
                'username'   => $m->out_username,
                'encryption' => $encMap[$m->out_encryption] ?? 'none',
                'method'     => $m->out_method,
            ];
            $data['imap'] = [
                'server'       => $m->in_server,
                'port'         => $m->in_port,
                'username'     => $m->in_username,
                'protocol'     => $m->in_protocol == 1 ? 'imap' : 'pop3',
                'encryption'   => $encMap[$m->in_encryption] ?? 'none',
                'validateCert' => (bool) $m->in_validate_cert,
            ];
        }

        return $data;
    }
}
