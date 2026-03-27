#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PACKAGE_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
QA_PROJECT="${QA_PROJECT:-/tmp/qa-v7-review}"

# Preflight checks
mysql -u root -e "SELECT 1" > /dev/null 2>&1 || { echo "MySQL not running"; exit 1; }
redis-cli PING > /dev/null 2>&1 || { echo "Redis not running"; exit 1; }
[ -f "$QA_PROJECT/artisan" ] || { echo "QA project not found at $QA_PROJECT (set QA_PROJECT env var)"; exit 1; }

# Kill old Horizon
pkill -9 -f horizon 2>/dev/null || true
sleep 1

# Flush Redis
redis-cli FLUSHALL > /dev/null

# Start Horizon
cd "$QA_PROJECT" && php artisan horizon &
HORIZON_PID=$!

# Wait for Horizon workers to register
sleep 5

# Run tests
cd "$PACKAGE_DIR"
vendor/bin/pest tests/LocalQA/ "$@"
EXIT_CODE=$?

# Cleanup
kill "$HORIZON_PID" 2>/dev/null
wait "$HORIZON_PID" 2>/dev/null || true

exit $EXIT_CODE
