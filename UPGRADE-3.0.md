# EventMachine v3.0 Upgrade Guide

EventMachine v3.0 introduces automatic data compression for `payload`, `context`, and `meta` fields to significantly reduce database storage requirements.

## 🆕 Fresh Installation (v3.0)

If you're installing EventMachine v3.0 for the first time:

```bash
php artisan vendor:publish --provider="Tarfinlabs\EventMachine\MachineServiceProvider" --tag="machine-migrations"
php artisan migrate
```

The default migration will create the `machine_events` table with compression-optimized columns.

## ⬆️ Upgrading from v2.x

If you're upgrading from EventMachine v2.x:

### Step 1: Update Package
```bash
composer update tarfinlabs/event-machine
```

### Step 2: Publish New Configuration
```bash
php artisan vendor:publish --provider="Tarfinlabs\EventMachine\MachineServiceProvider" --tag="machine-config" --force
```

### Step 3: Configure Compression (Optional)
Edit `config/machine.php` to customize compression settings:

```php
'compression' => [
    'enabled' => true,        // Enable/disable compression
    'level' => 6,            // Compression level (0-9)
    'fields' => ['payload', 'context', 'meta'], // Fields to compress  
    'threshold' => 100,      // Minimum size before compression
],
```

### Step 4: Check Your Upgrade Path
```bash
php artisan machine:check-upgrade
```

This command will analyze your database and recommend the best upgrade path based on your data size.

## 📊 Choose Your Upgrade Path

EventMachine v3.0 provides two upgrade paths depending on your dataset size:

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

### 🔧 Path B: Two-Step Migration (Large Datasets > 100k records)

For larger datasets, use the safer two-step approach:

#### Step 1: Run First Migration
```bash
# Publish migrations
php artisan vendor:publish --provider="Tarfinlabs\EventMachine\MachineServiceProvider" --tag="machine-migrations"

# Add new columns
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
# Dispatch migration to queue
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
For storage optimization:

```bash
# Check compression statistics (how much space you'll save)
php artisan machine:compress-events --dry-run

# Compress data in foreground (small datasets)
php artisan machine:compress-events

# Or use background job for large datasets (GB-scale)
php artisan tinker
>>> Tarfinlabs\EventMachine\Jobs\CompressMachineEventsJob::dispatch(5000);
```

## 🔧 Configuration Options

### Compression Settings
- **`enabled`**: Enable/disable compression globally
- **`level`**: Compression level (0=fastest, 9=best compression, 6=balanced)
- **`fields`**: Which fields to compress (`payload`, `context`, `meta`)
- **`threshold`**: Minimum data size (bytes) before compression kicks in

### Environment Variables
```env
MACHINE_EVENTS_COMPRESSION_ENABLED=true
MACHINE_EVENTS_COMPRESSION_LEVEL=6
MACHINE_EVENTS_COMPRESSION_THRESHOLD=100
```

### 📊 Compression Level Benchmarks

Based on 1MB of JSON data (measured on MacBook Pro M1):

| Level | Compression Time | Decompression Time | Size Reduction | Recommendation |
|-------|------------------|--------------------|----------------|----------------|
| 1 | 4.49ms | 0.88ms | 82.7% | Real-time applications |
| 2 | 4.67ms | 0.87ms | 83.6% | - |
| 3 | 5.31ms | 0.85ms | 84.3% | - |
| 4 | 7.77ms | 0.77ms | 84.8% | - |
| 5 | 9.07ms | 0.75ms | 85.7% | - |
| 6 | 11.39ms | 0.72ms | 86.4% | **Default - Best balance** |
| 7 | 13.29ms | 0.74ms | 86.6% | - |
| 8 | 22.07ms | 0.72ms | 87.1% | - |
| 9 | 27.63ms | 0.74ms | 87.1% | Archival storage |

**Key Insights:**
- Level 6 is 2.5x slower than Level 1 but achieves 3.7% better compression
- Level 9 is 2.4x slower than Level 6 but only 0.7% better compression
- Decompression is consistently fast across all levels (~0.7-0.9ms)
- JSON data compresses extremely well (82-87% reduction)

## 📊 Expected Benefits

- **82-87% storage reduction** for typical JSON machine event data (based on benchmarks)
- **Improved database performance** due to smaller data size
- **Binary-safe storage** with LONGBLOB columns (no character set corruption)
- **Transparent operation** - no code changes required
- **Backward compatibility** with existing uncompressed data
- **Fast decompression** - consistently under 1ms regardless of compression level

## 🛠️ Management Commands

### Migrate Data (v2 to v3 upgrade)
```bash
# Preview migration statistics
php artisan machine:migrate-events --dry-run

# Migrate data in batches
php artisan machine:migrate-events --chunk-size=5000

# Skip confirmation prompt
php artisan machine:migrate-events --force

# Use queue for large datasets
php artisan machine:migrate-events --queue
```

### Compress Existing Data
```bash
# Preview compression statistics
php artisan machine:compress-events --dry-run

# Compress data in batches
php artisan machine:compress-events --chunk-size=1000

# Skip confirmation prompt
php artisan machine:compress-events --force
```

### Background Processing
```php
// Dispatch migration job for large datasets
use Tarfinlabs\EventMachine\Jobs\MigrateMachineEventsJob;
MigrateMachineEventsJob::dispatch(5000); // Chunk size

// Dispatch compression job for large datasets
use Tarfinlabs\EventMachine\Jobs\CompressMachineEventsJob;
CompressMachineEventsJob::dispatch(1000); // Chunk size
```

## 🔄 Rollback (Emergency)

If you need to rollback the migration:

```bash
# First rollback the second migration (if applied)
php artisan migrate:rollback --path=vendor/tarfinlabs/event-machine/database/migrations/2025_01_01_000002_complete_machine_events_compression_upgrade_v3.php.stub

# Then rollback the first migration
php artisan migrate:rollback --path=vendor/tarfinlabs/event-machine/database/migrations/2025_01_01_000001_upgrade_machine_events_for_compression_v3.php.stub
```

**⚠️ Warning**: You'll need to manually migrate data back if you've already run the second migration.

## 🧪 Testing

All existing functionality remains the same. The compression is transparent:

```php
// This works exactly the same as before
$event = MachineEvent::create([
    'payload' => ['large' => 'data'],
    'context' => ['user' => 'context'],
    'meta' => ['debug' => 'info'],
]);

// Data is automatically compressed on save
// and decompressed on retrieval
$payload = $event->payload; // Returns original array
```

## 📋 Migration Strategy Summary

| Dataset Size | Records | Migration Path | Estimated Time | Complexity |
|--------------|---------|----------------|----------------|------------|
| Small | < 100k | All-in-One | < 5 minutes | Simple |
| Medium | 100k - 1M | Two-Step Direct | 5-30 minutes | Moderate |
| Large | > 1M | Two-Step Queue | 30+ minutes | Complex |
| Fresh Install | Any | Standard | Instant | Simple |

### 🤔 Which Path Should I Choose?

Run this command to get a personalized recommendation:
```bash
php artisan machine:check-upgrade
```

This will:
- Analyze your database size
- Estimate migration time
- Recommend the best approach
- Show detailed statistics

**Why different paths?**
- **Small datasets**: All-in-one is simpler and faster
- **Large datasets**: Two-step prevents timeouts and allows better control
- **Queue processing**: Enables parallel processing and resumability
- **Compression**: Optional step that can be done during low-traffic hours

## 🆘 Troubleshooting

### Pre-Upgrade Checklist
- ✅ Backup your database
- ✅ Test in staging environment first
- ✅ Check disk space (need ~2x current table size temporarily)
- ✅ Run `php artisan machine:check-upgrade` to analyze your data
- ✅ Schedule upgrade during low-traffic period

### Migration Issues
- Ensure `config/machine.php` is published before running migrations
- Check that `CompressionManager` class is available
- Verify sufficient disk space for temporary migration data
- For timeout issues, use queue-based migration instead

### Performance Issues
- Reduce `chunk-size` for large datasets
- Use background jobs for heavy compression tasks
- Monitor memory usage during migration

### Data Issues
- All data integrity is maintained through the migration
- Use `--dry-run` to preview changes before applying
- Backup database before major migrations (recommended)

---

For questions or issues, please check the EventMachine documentation or open an issue on GitHub.
