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

### Step 4: Run Upgrade Migration
```bash
php artisan vendor:publish --provider="Tarfinlabs\EventMachine\MachineServiceProvider" --tag="machine-migrations"
php artisan migrate
```

This will:
- Convert JSON columns to LONGBLOB for binary data storage  
- Copy existing JSON data to new columns (uncompressed for performance)
- Maintain full backward compatibility

### Step 5: Compress Data
After migration, compress the copied data:

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

## 📊 Expected Benefits

- **~70% storage reduction** for typical machine event data
- **Improved database performance** due to smaller data size
- **Binary-safe storage** with LONGBLOB columns (no character set corruption)
- **Transparent operation** - no code changes required
- **Backward compatibility** with existing uncompressed data

## 🛠️ Management Commands

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
// Dispatch compression job for large datasets
use Tarfinlabs\EventMachine\Jobs\CompressMachineEventsJob;

CompressMachineEventsJob::dispatch(1000); // Chunk size
```

## 🔄 Rollback (Emergency)

If you need to rollback the migration:

```bash
# This will convert compressed data back to JSON
php artisan migrate:rollback --step=1
```

**⚠️ Warning**: Rollback process decompresses all data back to JSON format.

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

| Scenario           | Migration Files Needed | Data Compression |
|--------------------|----------------------|------------------|
| Fresh v3.0 install | `create_machine_events_table` only | Automatic via model casts |
| Upgrade from v2.x  | Both migrations | Manual via command/job after migration |

**Why separate compression for upgrades?**
- GB-scale migrations run faster with copy-only approach
- Gives you control over compression timing (can run during low-traffic hours)
- Allows monitoring compression progress separately
- Reduces migration complexity and failure risk

## 🆘 Troubleshooting

### Migration Issues
- Ensure `config/machine.php` is published before running migrations
- Check that `CompressionManager` class is available
- Verify sufficient disk space for temporary migration data

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
