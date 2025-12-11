#!/bin/bash

echo "========================================="
echo "Activity Log Debug Output"
echo "========================================="
echo ""

cd /Users/lichtttt/webdev/kmerch

echo "=== JWT Authentication Logs ==="
grep "JwtAuthenticationSubscriber" var/log/dev.log | tail -20
echo ""

echo "=== Activity Log Subscriber Logs ==="
grep "ActivityLogSubscriber" var/log/dev.log | tail -20
echo ""

echo "=== Recent Activity Logs in Database ==="
php bin/console dbal:run-sql "SELECT id, username, action, target_data, created_at FROM activity_logs ORDER BY created_at DESC LIMIT 5"
echo ""

echo "=== Supplier-specific Logs ==="
grep -i "supplier" var/log/dev.log | tail -10
echo ""
