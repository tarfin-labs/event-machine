# Upgrading to EventMachine v3.0

EventMachine v3.0 introduces data compression for better storage efficiency. This guide will help you upgrade from v2.x to v3.0.

## Overview

The v3.0 upgrade converts JSON columns to LONGBLOB columns for compressed binary storage. This is done in multiple steps to handle large datasets safely.

## Upgrade Steps

### Step 1: Update Package

```bash
composer update tarfinlabs/event-machine
```

### Step 2: Run First Migration

This adds new columns for compressed data:

```bash
php artisan migrate --path=vendor/tarfinlabs/event-machine/database/migrations/2025_01_01_000001_upgrade_machine_events_for_compression_v3.php.stub
```

### Step 3: Migrate Data

Choose one of the following methods:

#### Option A: Direct Migration (for smaller datasets)

```bash
# Check migration statistics first
php artisan machine:migrate-events --dry-run

# Run the migration
php artisan machine:migrate-events
```

#### Option B: Queue-based Migration (recommended for large datasets)

```bash
# Dispatch migration jobs to queue
php artisan machine:migrate-events --queue

# In another terminal, run queue workers
php artisan queue:work
```

### Step 4: Complete Schema Changes

After data migration is complete, run the second migration:

```bash
php artisan migrate --path=vendor/tarfinlabs/event-machine/database/migrations/2025_01_01_000002_complete_machine_events_compression_upgrade_v3.php.stub
```

### Step 5: Compress Data (Optional)

To optimize storage with compression:

```bash
# Check compression statistics
php artisan machine:compress-events --dry-run

# Run compression
php artisan machine:compress-events

# Or use queue for large datasets
php artisan queue:work
# Then dispatch CompressMachineEventsJob
```

## Configuration

Enable compression in your `config/machine.php`:

```php
'compression' => [
    'enabled' => true,
    'threshold' => 1024, // Only compress data larger than 1KB
],
```

## Rollback

If you need to rollback:

1. First rollback the second migration
2. Then rollback the first migration
3. Downgrade the package

Note: Rollback will require manual data migration back to JSON format.

## Performance Considerations

- The migration process is designed to handle large datasets
- Use `--chunk-size` option to control memory usage
- Queue-based migration is recommended for tables with millions of records
- Compression typically reduces storage by 70-90% for JSON data