<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Models;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Definition\SourceType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Tarfinlabs\EventMachine\Database\Factories\MachineEventFactory;

class MachineEvent extends Model
{
    use HasUlids;
    use HasFactory;

    public $timestamps  = false;
    protected $fillable = [
        // ID Related Attributes
        'id',
        'sequence_number',
        'created_at',
        // Event Related Attributes
        'source',
        'type',
        'payload',
        'version',
        // Machine ID Related Attributes
        'machine_id',
        'machine_value',
        'root_event_id',
        // Machine Data Related Attributes
        'context',
        'meta',
    ];
    protected $casts = [
        'id'              => 'string',
        'sequence_number' => 'integer',
        'created_at'      => 'datetime',
        'machine_id'      => 'string',
        'machine_value'   => 'json',
        'root_event_id'   => 'string',
        'source'          => SourceType::class,
        'type'            => 'string',
        'payload'         => 'json',
        'version'         => 'integer',
        'context'         => 'json',
        'meta'            => 'json',
    ];

    protected static function newFactory(): MachineEventFactory
    {
        return MachineEventFactory::new();
    }
}
