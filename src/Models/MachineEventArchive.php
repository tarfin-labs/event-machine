<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Models;

use RuntimeException;
use InvalidArgumentException;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\EventCollection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Tarfinlabs\EventMachine\Support\CompressionManager;

/**
 * Class MachineEventArchive.
 *
 * The MachineEventArchive model represents archived machine events that have been
 * compressed and stored separately from the main machine_events table.
 *
 * @property string $root_event_id The unique identifier of the root event (primary key).
 * @property string $machine_id The unique identifier of the machine.
 * @property string $events_data The compressed binary data containing all events.
 * @property int $event_count The number of events archived in this record.
 * @property int $original_size The size before compression (bytes).
 * @property int $compressed_size The size after compression (bytes).
 * @property int $compression_level The compression level used (0-9).
 * @property Carbon $archived_at The timestamp when the events were archived.
 * @property Carbon $first_event_at The timestamp of the first event in this machine.
 * @property Carbon $last_event_at The timestamp of the last event in this machine.
 * @property int $restore_count The number of times this archive was restored.
 * @property Carbon|null $last_restored_at The timestamp when this archive was last restored.
 */
class MachineEventArchive extends Model
{
    use HasFactory;
    use HasUlids;

    public $timestamps    = false;
    protected $primaryKey = 'root_event_id';
    protected $keyType    = 'string';
    public $incrementing  = false;
    protected $fillable   = [
        'root_event_id',
        'machine_id',
        'events_data',
        'event_count',
        'original_size',
        'compressed_size',
        'compression_level',
        'archived_at',
        'first_event_at',
        'last_event_at',
        'restore_count',
        'last_restored_at',
    ];
    protected $casts = [
        'root_event_id'     => 'string',
        'machine_id'        => 'string',
        'event_count'       => 'integer',
        'original_size'     => 'integer',
        'compressed_size'   => 'integer',
        'compression_level' => 'integer',
        'archived_at'       => 'datetime',
        'first_event_at'    => 'datetime',
        'last_event_at'     => 'datetime',
        'restore_count'     => 'integer',
        'last_restored_at'  => 'datetime',
    ];

    /**
     * Archive a collection of machine events.
     */
    public static function archiveEvents(EventCollection $events, ?int $compressionLevel = null): self
    {
        if ($events->isEmpty()) {
            throw new InvalidArgumentException('Cannot archive empty event collection');
        }

        $rootEventId = $events->first()->root_event_id;
        $machineId   = $events->first()->machine_id;

        // Convert events to array format for compression
        $eventsData = $events->map(function (MachineEvent $event) {
            return $event->toArray();
        })->all();

        $jsonData     = json_encode($eventsData, JSON_THROW_ON_ERROR);
        $originalSize = strlen($jsonData);

        $level          = $compressionLevel ?? CompressionManager::getLevel();
        $compressedData = gzcompress($jsonData, $level);

        if ($compressedData === false) {
            throw new RuntimeException('Failed to compress events data');
        }

        return self::create([
            'root_event_id'     => $rootEventId,
            'machine_id'        => $machineId,
            'events_data'       => $compressedData,
            'event_count'       => $events->count(),
            'original_size'     => $originalSize,
            'compressed_size'   => strlen($compressedData),
            'compression_level' => $level,
            'archived_at'       => now(),
            'first_event_at'    => $events->first()->created_at,
            'last_event_at'     => $events->last()->created_at,
            'restore_count'     => 0,
            'last_restored_at'  => null,
        ]);
    }

    /**
     * Restore archived events to a collection.
     */
    public function restoreEvents(): EventCollection
    {
        $decompressed = gzuncompress($this->events_data);

        if ($decompressed === false) {
            throw new RuntimeException('Failed to decompress archived events data');
        }

        $eventsData = json_decode($decompressed, true, 512, JSON_THROW_ON_ERROR);

        $events = collect($eventsData)->map(function (array $eventData): MachineEvent {
            return new MachineEvent($eventData);
        });

        return new EventCollection($events->all());
    }

    /**
     * Get compression ratio (0.0 - 1.0, lower is better compression).
     */
    protected function getCompressionRatioAttribute(): float
    {
        if ($this->original_size === 0) {
            return 1.0;
        }

        return $this->compressed_size / $this->original_size;
    }

    /**
     * Get compression savings percentage (0-100%).
     */
    protected function getSavingsPercentAttribute(): float
    {
        if ($this->original_size === 0) {
            return 0.0;
        }

        return (($this->original_size - $this->compressed_size) / $this->original_size) * 100;
    }

    /**
     * Scope to filter by machine ID.
     */
    protected function scopeForMachine($query, string $machineId)
    {
        return $query->where('machine_id', $machineId);
    }

    /**
     * Scope to filter by archived date range.
     */
    protected function scopeArchivedBetween($query, Carbon $from, Carbon $to)
    {
        return $query->whereBetween('archived_at', [$from, $to]);
    }
}
