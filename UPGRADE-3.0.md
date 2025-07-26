# EventMachine v3.0 Upgrade Guide

EventMachine v3.0 introduces a new **archival system** for machine events. Instead of in-place compression, v3.0 automatically moves completed machine workflows to a separate compressed archive table, keeping your active `machine_events` table lean and performant.

## 🆕 Fresh Installation (v3.0)

If you're installing EventMachine v3.0 for the first time:

```bash
php artisan vendor:publish --provider="Tarfinlabs\EventMachine\MachineServiceProvider" --tag="machine-migrations"
php artisan migrate
```

This will create both the `machine_events` table (for active events) and `machine_events_archive` table (for archived workflows).

## ⬆️ Upgrading from v2.x

**Great News!** Upgrading from EventMachine v2.x to v3.0 is now **seamless** with no data migration required. Your existing `machine_events` table remains unchanged.

### Step 1: Update Package
```bash
composer update tarfinlabs/event-machine
```

### Step 2: Publish New Configuration
```bash
php artisan vendor:publish --provider="Tarfinlabs\EventMachine\MachineServiceProvider" --tag="machine-config" --force
```

### Step 3: Run New Migration
```bash
php artisan vendor:publish --provider="Tarfinlabs\EventMachine\MachineServiceProvider" --tag="machine-migrations"
php artisan migrate
```

This adds the new `machine_events_archive` table without touching your existing data.

### Step 4: Configure Archival (Optional)
Edit `config/machine.php` to customize archival settings:

```php
'archival' => [
    'enabled' => true,        // Enable/disable archival
    'level' => 6,            // Compression level (0-9)
    'threshold' => 1000,     // Minimum size before compression
    'triggers' => [
        'days_inactive' => 30,    // Archive after X days of inactivity
        'max_events' => 0,        // Archive when machine has X events (0 = disabled)
        'max_size' => 0,          // Archive when machine exceeds X bytes (0 = disabled)
    ],
    'cleanup_after_archive' => false, // Delete original events after archival
    
    // 🆕 NEW: Restore tracking and cooldown settings
    'restore_cooldown_hours' => 24, // Prevent re-archival for X hours after restore
    'archive_retention_days' => null, // Auto-delete archives after X days (null = keep forever)
    
    // 🆕 NEW: Advanced enterprise settings
    'advanced' => [
        'batch_size' => 100,
        'max_concurrent_jobs' => 3,
        'auto_schedule' => false,
        'schedule_expression' => '0 2 * * *', // Daily at 2 AM
        'slow_operation_threshold' => 60,
        'verify_integrity' => true,
    ],
    
    // 🆕 NEW: Machine-specific overrides
    'machine_overrides' => [
        // 'critical_machine' => [
        //     'triggers' => ['days_inactive' => 90],
        //     'compression_level' => 9,
        //     'cleanup_after_archive' => false,
        // ],
    ],
],
```

## ✨ New v3.0 Features

### 🔄 Transparent Archive Restoration

**The biggest improvement in v3.0**: Users can interact with archived machines completely transparently! When your application requests machine state from archived events, EventMachine automatically restores them behind the scenes.

```php
// This works seamlessly, whether events are active or archived!
$machine = Machine::withDefinition($definition);
$state = $machine->restoreStateFromRootEventId($rootEventId);

// ✅ If events are in active table → returns immediately
// ✅ If events are archived → automatically decompresses and returns
// ✅ Transparent to your application code → no changes needed!
```

**Key Benefits:**
- **Zero Code Changes**: Existing Machine::restoreStateFromRootEventId() calls work transparently
- **Automatic Fallback**: Checks active table first, then archives if needed
- **Restore Tracking**: Tracks when and how many times archives are accessed
- **Performance**: Active events are prioritized for fastest access

### 🛡️ Intelligent Restore Tracking & Cooldown

To prevent thrashing and manage archive lifecycle intelligently, v3.0 tracks restore operations:

```php
// After restoring from archive, the system tracks:
// - restore_count: How many times this archive was restored
// - last_restored_at: When it was last restored
// - cooldown period: Prevents immediate re-archival

// The system automatically respects cooldown periods
$archiveService = new ArchiveService();
if ($archiveService->canReArchive($rootEventId)) {
    // Safe to archive again
} else {
    // Still in cooldown period, skip re-archival
}
```

**Cooldown Logic:**
- After an archive is restored, it enters a configurable cooldown period (default: 24 hours)
- During cooldown, the machine won't be re-archived automatically
- This prevents scenarios where frequently accessed archives get repeatedly archived/restored
- Cooldown can be configured per-environment via `restore_cooldown_hours`

### 🏢 Enterprise ArchiveService

New centralized service for programmatic archive management:

```php
use Tarfinlabs\EventMachine\Services\ArchiveService;

$archiveService = new ArchiveService();

// Archive individual machines
$archive = $archiveService->archiveMachine($rootEventId, $compressionLevel, $cleanup);

// Restore with tracking
$events = $archiveService->restoreMachine($rootEventId, $keepArchive = true);

// Get eligible machines for archival (respects cooldown)
$eligibleMachines = $archiveService->getEligibleMachines(100);

// Batch archive multiple machines
$results = $archiveService->batchArchive($rootEventIds, $compressionLevel, $cleanup);

// Get comprehensive statistics
$stats = $archiveService->getArchiveStats();
// Returns: total_archives, total_events_archived, space_saved_mb, compression_ratio

// Clean up old archives based on retention policy
$deletedCount = $archiveService->cleanupOldArchives();
```

## ✨ Archive System Benefits

EventMachine v3.0 automatically moves old machine workflows from the active `machine_events` table to compressed archive storage. This provides immediate performance and storage benefits with zero configuration complexity.

## 🎯 Using the New Archival System

The v3.0 archival system provides powerful tools to manage your machine events efficiently:

### Manual Archival

**Preview what will be archived:**
```bash
php artisan machine:archive-events --dry-run
```

**Archive qualified events immediately:**
```bash
php artisan machine:archive-events
```

**Run archival in background:**
```bash
php artisan machine:archive-events --queue
```

### Automated Archival

Schedule the archival job to run automatically:

```php
// In your App\Console\Kernel::schedule() method
$schedule->job(new \Tarfinlabs\EventMachine\Jobs\ArchiveMachineEventsJob(100))
         ->daily(); // Run daily, or whatever frequency you prefer
```

Or use a more customized approach:

```bash
# Add to your crontab or scheduler
php artisan machine:archive-events --force --queue
```

### Managing Archives

**View archival status and statistics:**
```bash
php artisan machine:archive-status

# 🆕 NEW: Enhanced output now shows:
# - Recent archival activity with restore counts
# - Recent restore activity with tracking
# - Compression statistics and space savings
```

**View archives for a specific machine:**
```bash
php artisan machine:archive-status --machine-id=payment_processor

# 🆕 NEW: Enhanced machine details include:
# - Restore count for each archive
# - Last restoration timestamp
# - Cooldown status
```

**Restore archived events back to active table:**
```bash
php artisan machine:archive-status --restore=01H8BM4VK82JKPK7RPR3YGT2DM

# 🆕 NEW: Restore operation now:
# - Automatically tracks restoration in archive metadata
# - Updates restore_count and last_restored_at
# - Activates cooldown period for the machine
```

**Permanently delete archived events:**
```bash
php artisan machine:archive-status --cleanup-archive=01H8BM4VK82JKPK7RPR3YGT2DM
```

## 🔧 Configuration Options

### Archival Settings
- **`enabled`**: Enable/disable archival globally
- **`level`**: Compression level (0=fastest, 9=best compression, 6=balanced)
- **`threshold`**: Minimum data size (bytes) before compression is applied
- **`triggers.days_inactive`**: Archive machines after X days of inactivity
- **`triggers.max_events`**: Archive when machine has X events (0=disabled)
- **`triggers.max_size`**: Archive when machine exceeds X bytes (0=disabled)

### 🆕 NEW: Restore Tracking Settings
- **`restore_cooldown_hours`**: Prevent re-archival for X hours after restore (default: 24)
- **`archive_retention_days`**: Auto-delete archives after X days (null=keep forever)

### 🆕 NEW: Advanced Enterprise Settings
- **`advanced.batch_size`**: Archival processing batch size (default: 100)
- **`advanced.max_concurrent_jobs`**: Maximum concurrent archival jobs (default: 3)
- **`advanced.auto_schedule`**: Automatically schedule periodic archival (default: false)
- **`advanced.schedule_expression`**: Cron expression for auto scheduling (default: '0 2 * * *')
- **`advanced.slow_operation_threshold`**: Log slow operations over X seconds (default: 60)
- **`advanced.verify_integrity`**: Verify compressed data integrity (default: true)

### 🆕 NEW: Machine-Specific Overrides
Configure different archival policies per machine type via `machine_overrides` array.

### Environment Variables
```env
# Basic archival settings
MACHINE_EVENTS_ARCHIVAL_ENABLED=true
MACHINE_EVENTS_COMPRESSION_LEVEL=6
MACHINE_EVENTS_ARCHIVAL_THRESHOLD=1000
MACHINE_EVENTS_ARCHIVAL_DAYS=30
MACHINE_EVENTS_ARCHIVAL_MAX_EVENTS=0
MACHINE_EVENTS_ARCHIVAL_MAX_SIZE=0

# 🆕 NEW: Restore tracking settings
MACHINE_EVENTS_RESTORE_COOLDOWN_HOURS=24
MACHINE_EVENTS_ARCHIVE_RETENTION_DAYS=null

# 🆕 NEW: Advanced enterprise settings
MACHINE_EVENTS_ARCHIVAL_BATCH_SIZE=100
MACHINE_EVENTS_MAX_CONCURRENT_JOBS=3
MACHINE_EVENTS_AUTO_SCHEDULE_ARCHIVAL=false
MACHINE_EVENTS_ARCHIVAL_SCHEDULE="0 2 * * *"
MACHINE_EVENTS_SLOW_THRESHOLD=60
MACHINE_EVENTS_VERIFY_INTEGRITY=true
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

### 🚀 Performance & Storage
- **Zero-downtime upgrades** - no existing data modification required
- **82-87% storage reduction** for archived machine event data (based on benchmarks)
- **Improved active table performance** - separate archived data from active queries
- **Fast restoration** - quickly restore archived workflows when needed

### 🔄 Operational Excellence
- **🆕 Transparent Operations** - archived machines work exactly like active ones
- **🆕 Intelligent Cooldown** - prevents archive thrashing with configurable cooldown periods
- **🆕 Restore Tracking** - comprehensive analytics on archive usage patterns
- **🆕 Enterprise Management** - centralized ArchiveService for programmatic control

### 🛡️ Data Safety & Flexibility
- **Complete data safety** - archival process is fully reversible
- **Flexible archival policies** - configure per-machine or globally
- **🆕 Machine-specific overrides** - different policies for different machine types
- **🆕 Retention management** - automatic cleanup of old archives
- **Background processing** - archive during low-traffic periods

### 📈 Monitoring & Analytics
- **🆕 Comprehensive statistics** - space savings, compression ratios, restore patterns
- **🆕 Enhanced CLI tools** - detailed archival status and management commands
- **🆕 Performance monitoring** - track slow operations and optimize accordingly

## 🛠️ Management Commands

### Archive Machine Events
```bash
# Preview archival statistics
php artisan machine:archive-events --dry-run

# Archive data in batches
php artisan machine:archive-events --batch-size=100

# Skip confirmation prompt
php artisan machine:archive-events --force

# Use queue for large datasets
php artisan machine:archive-events --queue
```

### Manage Archived Data
```bash
# View overall archival status
php artisan machine:archive-status

# View archives for specific machine
php artisan machine:archive-status --machine-id=my_machine

# Restore archived events
php artisan machine:archive-status --restore=01H8BM4VK82JKPK7RPR3YGT2DM

# Delete archived events permanently
php artisan machine:archive-status --cleanup-archive=01H8BM4VK82JKPK7RPR3YGT2DM
```

### Background Processing
```php
// Dispatch archival job for automated processing
use Tarfinlabs\EventMachine\Jobs\ArchiveMachineEventsJob;
ArchiveMachineEventsJob::dispatch(100); // Batch size

// Custom archival configuration
$customConfig = [
    'enabled' => true,
    'triggers' => ['days_inactive' => 15],
];
ArchiveMachineEventsJob::withConfig($customConfig, 50)->dispatch();
```

## 🔄 Rollback (Emergency)

If you need to rollback the v3.0 upgrade:

**Step 1: Restore any archived data you want to keep**
```bash
# List all archived machines
php artisan machine:archive-status

# Restore specific machines as needed
php artisan machine:archive-status --restore=01H8BM4VK82JKPK7RPR3YGT2DM
```

**Step 2: Rollback the migration**
```bash
php artisan migrate:rollback
```

**✅ Safe Rollback**: Since v3.0 doesn't modify existing data, rollback is completely safe. Your `machine_events` table remains untouched throughout the process.

## 🧪 Testing

All existing functionality remains exactly the same. Archival is completely transparent:

```php
// Your existing code works without any changes
$event = MachineEvent::create([
    'payload' => ['large' => 'data'],
    'context' => ['user' => 'context'],
    'meta' => ['debug' => 'info'],
]);

// Events are stored normally in machine_events table
// Archival happens separately based on your configured triggers
$payload = $event->payload; // Returns original array, no changes needed

// 🆕 NEW: Machine restoration works transparently with archives
$machine = Machine::withDefinition($definition);
$state = $machine->restoreStateFromRootEventId($rootEventId);
// ✅ Works whether events are active or archived!

// 🆕 NEW: Use ArchiveService for programmatic management
use Tarfinlabs\EventMachine\Services\ArchiveService;
$archiveService = new ArchiveService();

// Archive with restore tracking
$archive = $archiveService->archiveMachine($rootEventId, $compressionLevel);

// Restore with automatic tracking (tracks restore_count, last_restored_at)
$restoredEvents = $archiveService->restoreMachine($rootEventId, $keepArchive = true);

// Check if machine can be re-archived (respects cooldown)
if ($archiveService->canReArchive($rootEventId)) {
    // Safe to archive again
}

// Get comprehensive archive statistics
$stats = $archiveService->getArchiveStats();
// Returns detailed metrics about your archival system
```

### 🆕 NEW: Testing Archive Transparency

Test that your application works seamlessly with archived data:

```php
// 1. Create and process some machine events
$machine = Machine::create($definition);
$machine->send('START');
$machine->send('PROCESS');
$rootEventId = $machine->state->history->first()->root_event_id;

// 2. Archive the machine events
$archiveService = new ArchiveService();
$archive = $archiveService->archiveMachine($rootEventId, 6); // Archive and move to compressed storage

// 3. Test transparent restoration
$newMachine = Machine::withDefinition($definition);
$restoredState = $newMachine->restoreStateFromRootEventId($rootEventId);
// ✅ Should work exactly the same as before archival!

// 4. Verify restoration was tracked
$archive->refresh();
expect($archive->restore_count)->toBe(1);
expect($archive->last_restored_at)->not->toBeNull();
```

## 📋 Upgrade Summary

| Scenario | Upgrade Path | Time Required | Data Safety |
|----------|--------------|---------------|-------------|
| Fresh Install | Standard Migration | Instant | N/A |
| Any Upgrade | Add Archive Table | < 1 minute | 100% Safe |
| Start Archival | Configure & Run | Variable | Fully Reversible |

### 🤔 What's the Best Approach?

**For all users:** The upgrade is now **universal** and **safe**:

1. **Update the package** - Get the latest code
2. **Run migrations** - Adds the archive table (no data changes)
3. **Configure archival** - Set your preferred triggers
4. **Test archival** - Use `--dry-run` to preview
5. **Enable automation** - Schedule the archival job

**Why this approach is better:**
- **Zero risk**: Your existing data is never touched
- **Gradual adoption**: Start archival when you're ready
- **Full control**: Configure exactly what gets archived and when
- **Easy rollback**: Simply restore archives and rollback migration
- **Better performance**: Active table stays lean, archives are separate
- **🆕 Transparent operations**: Archived machines work exactly like active ones
- **🆕 Intelligent management**: Cooldown periods prevent archive thrashing
- **🆕 Enterprise features**: Comprehensive tracking, analytics, and management tools

## 🆘 Troubleshooting

### Pre-Upgrade Checklist
- ✅ Backup your database (recommended, but not required for safety)
- ✅ Test in staging environment first
- ✅ Ensure sufficient disk space for archive table
- ✅ Review archival configuration settings

### Common Issues

**Archive table creation fails:**
- Ensure database user has CREATE TABLE permissions
- Check for naming conflicts with existing tables

**Archival job not finding qualified machines:**
- Review your trigger configuration in `config/machine.php`
- Use `--dry-run` to preview what would be archived
- Check that events aren't already archived

**Performance during archival:**
- Reduce `--batch-size` for large datasets
- Use `--queue` option for background processing
- Run archival during low-traffic periods

**🆕 NEW: Archive transparency issues:**
- If Machine::restoreStateFromRootEventId() fails on archived data, check archive integrity
- Verify your archive table has restore tracking columns (restore_count, last_restored_at)
- Use ArchiveService::restoreMachine() for more detailed error reporting

**🆕 NEW: Cooldown period conflicts:**
- If eligible machines aren't being archived, check if they're in cooldown period
- Use ArchiveService::canReArchive($rootEventId) to verify cooldown status
- Adjust `restore_cooldown_hours` configuration if needed

**🆕 NEW: Archive statistics issues:**
- If compression ratios seem low, check data size against threshold setting
- Verify archive integrity with `advanced.verify_integrity` option
- Use CompressionManager::getCompressionStats() for detailed analysis


### Data Safety
- **Zero risk upgrade**: Existing data is never modified
- **Reversible archival**: All archives can be restored
- **Independent operation**: Archive system works alongside existing functionality
- **Backup recommended**: While safe, backups are always good practice

---

For questions or issues, please check the EventMachine documentation or open an issue on GitHub.
