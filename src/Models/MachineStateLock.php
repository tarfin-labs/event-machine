<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Models;

use Illuminate\Database\Eloquent\Model;

class MachineStateLock extends Model
{
    public $timestamps    = false;
    public $incrementing  = false;
    protected $table      = 'machine_locks';
    protected $primaryKey = 'root_event_id';
    protected $keyType    = 'string';
    protected $fillable   = [
        'root_event_id',
        'owner_id',
        'acquired_at',
        'expires_at',
        'context',
    ];
    protected $casts = [
        'acquired_at' => 'datetime',
        'expires_at'  => 'datetime',
    ];
}
