# Upgrading to EventMachine v3.0

EventMachine v3.0 introduces automatic data compression for `payload`, `context`, and `meta` fields to significantly reduce database storage requirements. This comprehensive guide will help you upgrade safely from v2.x to v3.0.

## 🆕 What's New in v3.0

- **Automatic Data Compression**: 82-87% storage reduction for typical JSON data
- **Improved Performance**: Smaller data size improves database performance
- **Binary-Safe Storage**: LONGBLOB columns prevent character set corruption
- **Transparent Operation**: No code changes required - compression is automatic
- **Backward Compatibility**: Works seamlessly with existing uncompressed data
- **Advanced Management Commands**: Tools for migration, compression, and monitoring

## 🔍 Pre-Upgrade Analysis

Before upgrading, analyze your current setup:

```bash
# Check your upgrade requirements and get recommendations
php artisan machine:check-upgrade
```

This command will:
- Analyze your database size
- Estimate migration time
- Recommend the best upgrade path
- Show detailed statistics

## 🆕 Fresh Installation (v3.0)

If you're installing EventMachine v3.0 for the first time:

```bash
# Install the package
composer require tarfinlabs/event-machine

# Publish and run migrations
php artisan vendor:publish --provider="Tarfinlabs\EventMachine\MachineServiceProvider" --tag="machine-migrations"
php artisan migrate
```

The default migration creates the `machine_events` table with compression-optimized columns.

## ⬆️ Upgrading from v2.x

### Step 1: Update Package

```bash
composer update tarfinlabs/event-machine
```

### Step 2: Publish New Configuration

```bash
php artisan vendor:publish --provider="Tarfinlabs\EventMachine\MachineServiceProvider" --tag="machine-config" --force
```

This creates/updates `config/machine.php` with compression settings.

### Step 3: Configure Compression (Optional)

Edit `config/machine.php` to customize compression settings:

```php
<?php

return [
    'compression' => [
        // Enable/disable compression globally
        'enabled' => env('MACHINE_EVENTS_COMPRESSION_ENABLED', true),

        // Compression level (0-9). Higher levels provide better compression
        // but use more CPU. Level 6 provides good balance of speed/compression.
        'level' => env('MACHINE_EVENTS_COMPRESSION_LEVEL', 6),

        // Fields to compress. machine_value is kept as JSON for querying.
        'fields' => ['payload', 'context', 'meta'],

        // Minimum data size (in bytes) before compression is applied.
        // Small data may not benefit from compression due to overhead.
        'threshold' => env('MACHINE_EVENTS_COMPRESSION_THRESHOLD', 100),
    ],
];
```

### Step 4: Choose Your Upgrade Path

EventMachine v3.0 provides two upgrade paths depending on your dataset size:

## 📊 Upgrade Paths

### 🚀 Path A: All-in-One Migration (Small Datasets < 100k records)

For small datasets, use the simplified all-in-one migration:

```bash
# Step 1: Publish migrations
php artisan vendor:publish --provider="Tarfinlabs\EventMachine\MachineServiceProvider" --tag="machine-migrations"

# Step 2: Run the all-in-one migration
php artisan migrate --path=vendor/tarfinlabs/event-machine/database/migrations/2025_01_01_000003_upgrade_machine_events_all_in_one_v3.php.stub

# Step 3: Compress data (optional but recommended)
php artisan machine:compress-events
```

**Advantages:**
- Single migration command
- Faster for small datasets
- No intermediate steps
- Minimal complexity

### 🔧 Path B: Two-Step Migration (Large Datasets > 100k records)

For larger datasets, use the safer two-step approach:

#### Step 1: Add New Columns
```bash
# Publish migrations
php artisan vendor:publish --provider="Tarfinlabs\EventMachine\MachineServiceProvider" --tag="machine-migrations"

# Add new compression columns
php artisan migrate --path=vendor/tarfinlabs/event-machine/database/migrations/2025_01_01_000001_upgrade_machine_events_for_compression_v3.php.stub
```

#### Step 2: Migrate Data

**For Medium Datasets (100k - 1M records):**
```bash
# Preview migration statistics
php artisan machine:migrate-events --dry-run

# Run migration directly
php artisan machine:migrate-events
```

**For Large Datasets (> 1M records):**
```bash
# Dispatch migration to queue (recommended)
php artisan machine:migrate-events --queue

# Run queue workers in separate terminal
php artisan queue:work
```

#### Step 3: Complete Schema Changes
After data migration is complete:
```bash
php artisan migrate --path=vendor/tarfinlabs/event-machine/database/migrations/2025_01_01_000002_complete_machine_events_compression_upgrade_v3.php.stub
```

#### Step 4: Compress Data (Optional)
For maximum storage optimization:

```bash
# Check compression statistics (see potential savings)
php artisan machine:compress-events --dry-run

# Compress data in foreground (small-medium datasets)
php artisan machine:compress-events

# For very large datasets, use background processing
php artisan tinker
>>> Tarfinlabs\EventMachine\Jobs\CompressMachineEventsJob::dispatch(5000);
```

## 🛠️ Management Commands

### Migration Command Options

```bash
# Preview migration without making changes
php artisan machine:migrate-events --dry-run

# Control batch size for memory management
php artisan machine:migrate-events --chunk-size=1000

# Skip confirmation prompts (for scripts)
php artisan machine:migrate-events --force

# Use queue for very large datasets
php artisan machine:migrate-events --queue
```

### Compression Command Options

```bash
# Preview compression statistics and savings
php artisan machine:compress-events --dry-run

# Compress in smaller batches
php artisan machine:compress-events --chunk-size=500

# Skip confirmation prompts
php artisan machine:compress-events --force

# Background compression for large datasets
php artisan tinker
>>> Tarfinlabs\EventMachine\Jobs\CompressMachineEventsJob::dispatch(1000);
```

### Analysis Commands

```bash
# Check upgrade requirements and recommendations
php artisan machine:check-upgrade

# Validate machine configurations (useful after upgrade)
php artisan machine:validate-config
```

## 📊 Compression Benchmarks

Based on 1MB of JSON data (measured on MacBook Pro M1):

| Level | Compression Time | Decompression Time | Size Reduction | Use Case |
|-------|------------------|--------------------|----------------|----------|
| 1 | 4.49ms | 0.88ms | 82.7% | Real-time applications |
| 2 | 4.67ms | 0.87ms | 83.6% | High-frequency writes |
| 3 | 5.31ms | 0.85ms | 84.3% | Balanced performance |
| 4 | 7.77ms | 0.77ms | 84.8% | Standard applications |
| 5 | 9.07ms | 0.75ms | 85.7% | Good compression |
| 6 | 11.39ms | 0.72ms | 86.4% | **Default - Best balance** |
| 7 | 13.29ms | 0.74ms | 86.6% | Better compression |
| 8 | 22.07ms | 0.72ms | 87.1% | High compression |
| 9 | 27.63ms | 0.74ms | 87.1% | Archival storage |

**Key Insights:**
- Level 6 provides excellent balance of speed and compression
- Decompression is consistently fast (~0.7-0.9ms) across all levels
- JSON data compresses extremely well (82-87% reduction)
- Higher levels have diminishing returns on compression ratio

## 📈 Expected Benefits

### Storage Reduction
- **82-87% storage reduction** for typical JSON machine event data
- Significant cost savings for cloud database storage
- Reduced backup sizes and transfer times

### Performance Improvements
- **Faster queries** due to smaller data size
- **Reduced I/O** operations
- **Better cache efficiency** with more data fitting in memory
- **Improved replication** performance

### Reliability Improvements
- **Binary-safe storage** prevents character set corruption issues
- **Data integrity** maintained through automatic validation
- **Graceful handling** of mixed compressed/uncompressed data

## 🔧 Configuration Options

### Environment Variables

```env
# Enable/disable compression (default: true)
MACHINE_EVENTS_COMPRESSION_ENABLED=true

# Compression level 0-9 (default: 6)
MACHINE_EVENTS_COMPRESSION_LEVEL=6

# Minimum size in bytes before compression (default: 100)
MACHINE_EVENTS_COMPRESSION_THRESHOLD=100
```

### Runtime Configuration

```php
// In your AppServiceProvider or similar
config(['machine.compression.enabled' => true]);
config(['machine.compression.level' => 6]);
config(['machine.compression.threshold' => 100]);
```

### Per-Environment Settings

```php
// config/machine.php
return [
    'compression' => [
        'enabled' => env('APP_ENV') === 'production', // Only in production
        'level' => env('APP_ENV') === 'production' ? 6 : 1, // Lower level in dev
        'threshold' => env('APP_ENV') === 'production' ? 100 : 1000,
    ],
];
```

## 🧪 Testing After Upgrade

### Verify Compression is Working

```php
use Tarfinlabs\EventMachine\Models\MachineEvent;
use App\Machines\YourMachine;

// Create a machine with some data
$machine = YourMachine::create([
    'largeContext' => str_repeat('test data ', 1000) // Large context
]);

// Check that data is compressed
$event = MachineEvent::where('machine_id', $machine->id)->first();

// If compression is working, payload_compressed should not be null
$this->assertNotNull($event->payload_compressed);

// Data should be retrievable normally
$this->assertEquals($machine->state->context->largeContext, 
                   str_repeat('test data ', 1000));
```

### Performance Testing

```php
public function test_compression_performance()
{
    $largeData = ['data' => str_repeat('x', 10000)];
    
    $startTime = microtime(true);
    
    // Create 100 events with large data
    for ($i = 0; $i < 100; $i++) {
        MachineEvent::create([
            'machine_id' => 'test-' . $i,
            'payload' => $largeData,
            'context' => $largeData,
            'meta' => $largeData,
        ]);
    }
    
    $endTime = microtime(true);
    $duration = $endTime - $startTime;
    
    // Should complete reasonably quickly
    $this->assertLessThan(10.0, $duration);
}
```

## 🔄 Rollback Strategy

If you need to rollback the migration:

### Immediate Rollback (Before Data Migration)

```bash
# Rollback the column addition migration
php artisan migrate:rollback --path=vendor/tarfinlabs/event-machine/database/migrations/2025_01_01_000001_upgrade_machine_events_for_compression_v3.php.stub
```

### Complete Rollback (After Data Migration)

```bash
# Step 1: Rollback the second migration (if applied)
php artisan migrate:rollback --path=vendor/tarfinlabs/event-machine/database/migrations/2025_01_01_000002_complete_machine_events_compression_upgrade_v3.php.stub

# Step 2: Manually migrate data back to JSON format
# (You'll need to write a custom script for this)

# Step 3: Rollback the first migration
php artisan migrate:rollback --path=vendor/tarfinlabs/event-machine/database/migrations/2025_01_01_000001_upgrade_machine_events_for_compression_v3.php.stub

# Step 4: Downgrade the package
composer require "tarfinlabs/event-machine:^2.0"
```

**⚠️ Warning**: Full rollback after data migration requires manual data conversion. Always test rollback procedures in staging first.

## 📋 Migration Decision Matrix

| Dataset Size | Records | Migration Path | Estimated Time | Risk Level | Recommended Approach |
|--------------|---------|----------------|----------------|------------|----------------------|
| Small | < 10k | All-in-One | < 1 minute | Low | Direct migration |
| Medium | 10k-100k | All-in-One | 1-5 minutes | Low | Direct migration |
| Large | 100k-1M | Two-Step Direct | 5-30 minutes | Medium | Direct with monitoring |
| Very Large | 1M-10M | Two-Step Queue | 30+ minutes | Medium | Queue-based |
| Massive | > 10M | Two-Step Queue | Hours | High | Queue with careful planning |

### Decision Factors

**Choose All-in-One if:**
- Dataset is under 100k records
- You want simplicity
- Downtime tolerance is high
- Testing showed good performance

**Choose Two-Step if:**
- Dataset is over 100k records
- You need more control over the process
- You want to monitor progress
- You have strict downtime requirements

**Use Queue-based migration if:**
- Dataset is over 1M records
- You need the migration to be resumable
- You want to process during off-peak hours
- You have limited PHP execution time limits

## 🆘 Troubleshooting

### Pre-Upgrade Checklist

- ✅ **Backup your database** - Essential for any major migration
- ✅ **Test in staging environment** - Validate the process first
- ✅ **Check disk space** - Need ~2x current table size temporarily
- ✅ **Run analysis command** - `php artisan machine:check-upgrade`
- ✅ **Schedule during low traffic** - Minimize impact on users
- ✅ **Verify PHP memory limits** - Large datasets need more memory
- ✅ **Check queue workers** - If using queue-based migration

### Common Issues and Solutions

#### Migration Timeouts

**Problem**: Migration fails with timeout errors

**Solutions**:
```bash
# Reduce chunk size
php artisan machine:migrate-events --chunk-size=500

# Use queue-based processing
php artisan machine:migrate-events --queue

# Increase PHP limits temporarily
ini_set('max_execution_time', 0);
ini_set('memory_limit', '2G');
```

#### Insufficient Disk Space

**Problem**: Migration fails due to disk space

**Solutions**:
- Free up disk space before migration
- Use smaller chunk sizes to reduce temporary storage
- Monitor disk usage during migration
- Consider migrating in smaller batches over time

#### Memory Exhaustion

**Problem**: PHP runs out of memory during migration

**Solutions**:
```bash
# Reduce chunk size
php artisan machine:migrate-events --chunk-size=100

# Increase PHP memory limit
php -d memory_limit=2G artisan machine:migrate-events

# Use queue-based processing
php artisan machine:migrate-events --queue
```

#### Queue Processing Issues

**Problem**: Queue jobs fail or get stuck

**Solutions**:
```bash
# Check failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Monitor queue status
php artisan queue:monitor

# Use database queue driver for reliability
php artisan queue:table
php artisan migrate
```

#### Data Integrity Concerns

**Problem**: Worried about data corruption during migration

**Solutions**:
- Always backup before migration
- Use `--dry-run` to preview changes
- Test with a copy of production data
- Validate data after migration with custom scripts

### Validation After Migration

```php
// Check that all data migrated correctly
$originalCount = DB::table('machine_events')->count();
$migratedCount = DB::table('machine_events')
    ->whereNotNull('payload_compressed')
    ->orWhereNotNull('context_compressed')  
    ->orWhereNotNull('meta_compressed')
    ->count();

echo "Original records: {$originalCount}\n";
echo "Migrated records: {$migratedCount}\n";

// Verify random samples can be read correctly
$samples = MachineEvent::inRandomOrder()->limit(10)->get();
foreach ($samples as $event) {
    // This should not throw exceptions
    $payload = $event->payload;
    $context = $event->context;
    $meta = $event->meta;
    
    echo "Event {$event->id}: OK\n";
}
```

### Performance Monitoring

```php
// Monitor compression ratio
$totalOriginalSize = DB::table('machine_events')
    ->select(DB::raw('
        LENGTH(COALESCE(payload, "{}")) + 
        LENGTH(COALESCE(context, "{}")) + 
        LENGTH(COALESCE(meta, "{}")) as original_size
    '))
    ->sum('original_size');

$totalCompressedSize = DB::table('machine_events')
    ->select(DB::raw('
        LENGTH(COALESCE(payload_compressed, "")) + 
        LENGTH(COALESCE(context_compressed, "")) + 
        LENGTH(COALESCE(meta_compressed, "")) as compressed_size
    '))
    ->sum('compressed_size');

$compressionRatio = (1 - ($totalCompressedSize / $totalOriginalSize)) * 100;
echo "Compression ratio: " . round($compressionRatio, 2) . "%\n";
```

## 🚀 Best Practices

### Before Migration
1. **Backup Everything** - Full database backup is essential
2. **Test Thoroughly** - Run complete migration on staging/copy first
3. **Plan Downtime** - Schedule during low-traffic periods
4. **Monitor Resources** - Check disk space, memory, and CPU capacity
5. **Prepare Rollback** - Have rollback plan and scripts ready

### During Migration
1. **Monitor Progress** - Watch logs and database size changes
2. **Check System Resources** - Monitor memory, disk, and CPU usage
3. **Be Patient** - Large datasets take time; don't interrupt the process
4. **Keep Stakeholders Informed** - Communicate progress to team

### After Migration
1. **Validate Data** - Run integrity checks and sample data verification
2. **Monitor Performance** - Check query performance and storage usage
3. **Update Documentation** - Document any configuration changes
4. **Plan Compression** - Schedule data compression during off-peak hours
5. **Monitor Logs** - Watch for any compression-related errors

### Ongoing Maintenance
1. **Monitor Compression Ratios** - Track storage savings over time
2. **Tune Compression Settings** - Adjust based on performance metrics
3. **Regular Backups** - Compressed data still needs regular backups
4. **Update Procedures** - Update deployment and maintenance procedures

## 📞 Getting Help

### Resources
- **Documentation**: Check the EventMachine documentation
- **GitHub Issues**: Report bugs and get help from the community
- **Stack Overflow**: Tag questions with `eventmachine` and `laravel`

### Before Seeking Help
1. Run `php artisan machine:check-upgrade` for analysis
2. Check logs for specific error messages
3. Try the suggested troubleshooting steps
4. Test with a smaller dataset first

### When Reporting Issues
Include:
- EventMachine version (before and after)
- Laravel version
- PHP version
- Database type and version
- Dataset size (approximate record count)
- Full error messages
- Steps taken before the issue occurred

---

For additional support, please visit the [EventMachine GitHub repository](https://github.com/tarfin-labs/event-machine) or contact the maintainers.