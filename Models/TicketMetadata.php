<?php

namespace Modules\ApiWebhooks\Models;

use Illuminate\Database\Eloquent\Model;

class TicketMetadata extends Model
{
    protected $table = 'api_conversation_metadata';
    protected $primaryKey = 'conversation_id';
    public $incrementing = false;
    protected $guarded = [];
    protected $casts = ['tags' => 'array', 'custom_fields' => 'array'];
    protected $dates = ['snoozed_until'];
}
