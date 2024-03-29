<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Tarfinlabs\EventMachine\Database\Factories\MachineEventFactory;

/**
 * Class MachineEvent.
 *
 * The MachineEvent model represents an event generated by a machine.
 *
 * @property string $id The unique identifier of the event.
 * @property int $sequence_number The sequence number of the event.
 * @property Carbon $created_at The timestamp when the event was created.
 * @property SourceType $source The source type of the event (Internal or External).
 * @property string $type The type of the event.
 * @property array $payload The payload data of the event.
 * @property int $version The version of the event.
 * @property string $machine_id The unique identifier of the machine that generated the event.
 * @property array $machine_value Machine state after the event.
 * @property string $root_event_id The unique identifier of the root event in event sequence.
 * @property array $context The context data of the event.
 * @property array $meta The metadata of the event.
 */
class MachineEvent extends Model
{
    use HasFactory;
    use HasUlids;

    public $timestamps  = false;
    protected $fillable = [
        // ID Related Attributes
        'id',
        'sequence_number',
        'created_at',
        // Machine ID Related Attributes
        'machine_id',
        'machine_value',
        'root_event_id',
        // Event Related Attributes
        'source',
        'type',
        'payload',
        'version',
        // Machine Data Related Attributes
        'context',
        'meta',
    ];
    protected $casts = [
        'id'              => 'string',
        'sequence_number' => 'integer',
        'created_at'      => 'datetime',
        'machine_id'      => 'string',
        'machine_value'   => 'array',
        'root_event_id'   => 'string',
        'source'          => SourceType::class,
        'type'            => 'string',
        'payload'         => 'array',
        'version'         => 'integer',
        'context'         => 'array',
        'meta'            => 'array',
    ];

    /**
     * Create a new instance of MachineEventFactory.
     *
     * @return MachineEventFactory The newly created MachineEventFactory instance.
     */
    protected static function newFactory(): MachineEventFactory
    {
        return MachineEventFactory::new();
    }

    /**
     * Create a new collection of models.
     *
     * This method overrides the default Eloquent collection with a custom
     * EventCollection. This allows for additional methods to be available
     * on the collection of MachineEvent models.
     *
     * @param  array  $models  An array of MachineEvent models.
     *
     * @return EventCollection A new instance of EventCollection.
     */
    public function newCollection(array $models = []): EventCollection
    {
        return new EventCollection($models);
    }
}
