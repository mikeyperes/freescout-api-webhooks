<?php

namespace Modules\ApiWebhooks\Transformers;

use App\Customer;

class CustomerTransformer
{
    public static function transform(Customer $c)
    {
        $emails = [];
        foreach ($c->emails as $e) {
            $emails[] = ['id' => $e->id, 'value' => $e->email, 'type' => 'email'];
        }

        return [
            'id'             => $c->id,
            'firstName'      => $c->first_name,
            'lastName'       => $c->last_name,
            'fullName'       => $c->getFullName(),
            'company'        => $c->company,
            'jobTitle'       => $c->job_title,
            'photoUrl'       => $c->getPhotoUrl(),
            'notes'          => $c->background,
            'address'        => $c->address,
            'city'           => $c->city,
            'state'          => $c->state,
            'zip'            => $c->zip,
            'country'        => $c->country,
            'createdAt'      => $c->created_at ? date('c', strtotime($c->created_at)) : null,
            'updatedAt'      => $c->updated_at ? date('c', strtotime($c->updated_at)) : null,
            '_embedded'      => [
                'emails'   => $emails,
                'phones'   => $c->getPhones(),
                'websites' => $c->getWebsites(),
                'social_profiles' => $c->getSocialProfiles(),
            ],
        ];
    }

    public static function brief($customer)
    {
        if (!$customer) {
            return null;
        }
        return [
            'id'        => $customer->id,
            'type'      => 'customer',
            'firstName' => $customer->first_name,
            'lastName'  => $customer->last_name,
            'email'     => $customer->getMainEmail(),
        ];
    }
}
