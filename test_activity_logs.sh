#!/bin/bash

# Activity Log Implementation Test Script
# Run this script to verify the activity logging system is working correctly

echo "==================================="
echo "Activity Log Implementation Tests"
echo "==================================="
echo ""

cd /Users/lichtttt/webdev/kmerch

# Test 1: Check EventSubscriber service registration
echo "Test 1: Checking EventSubscriber service registration..."
php bin/console debug:container ActivityLogSubscriber 2>&1 | grep -q "doctrine.event_subscriber"
if [ $? -eq 0 ]; then
    echo "✅ PASS: ActivityLogSubscriber is registered with doctrine.event_subscriber tag"
else
    echo "❌ FAIL: ActivityLogSubscriber is not properly registered"
fi
echo ""

# Test 2: Check API routes
echo "Test 2: Checking API routes..."
php bin/console debug:router | grep -q "api_get_activity_logs"
if [ $? -eq 0 ]; then
    echo "✅ PASS: /api/activity-logs route is registered"
else
    echo "❌ FAIL: /api/activity-logs route is missing"
fi
echo ""

# Test 3: Check database schema sync
echo "Test 3: Checking database schema sync..."
php bin/console doctrine:schema:update --dump-sql 2>&1 | grep -q "Nothing to update"
if [ $? -eq 0 ]; then
    echo "✅ PASS: Database schema is in sync with entities"
else
    echo "⚠️  WARNING: Database schema may need updates"
    php bin/console doctrine:schema:update --dump-sql
fi
echo ""

# Test 4: Check ActivityLog entity
echo "Test 4: Checking ActivityLog entity..."
if [ -f "src/Entity/ActivityLog.php" ]; then
    # Check if ipAddress was removed
    grep -q "ipAddress" src/Entity/ActivityLog.php
    if [ $? -ne 0 ]; then
        echo "✅ PASS: ActivityLog entity exists and ipAddress column has been removed"
    else
        echo "⚠️  WARNING: ActivityLog entity still contains ipAddress property"
    fi
else
    echo "❌ FAIL: ActivityLog entity file not found"
fi
echo ""

# Test 5: Check frontend file
echo "Test 5: Checking frontend ActivityLogs.js..."
if [ -f "../frontend/src/pages/admin/ActivityLogs.js" ]; then
    echo "✅ PASS: Frontend ActivityLogs.js file exists"
else
    echo "❌ FAIL: Frontend ActivityLogs.js file not found"
fi
echo ""

# Test 6: Check Doctrine events configuration
echo "Test 6: Checking EventSubscriber implementation..."
grep -q "getSubscribedEvents" src/EventSubscriber/ActivityLogSubscriber.php
if [ $? -eq 0 ]; then
    echo "✅ PASS: EventSubscriber implements getSubscribedEvents()"
else
    echo "❌ FAIL: EventSubscriber missing getSubscribedEvents() method"
fi
echo ""

# Summary
echo "==================================="
echo "Test Summary"
echo "==================================="
echo ""
echo "To manually test the implementation:"
echo "1. Start Symfony server: php -S localhost:8000 -t public"
echo "2. Start React frontend: cd ../frontend && npm start"
echo "3. Login as admin/staff user"
echo "4. Create/Update/Delete entities (Product, Supplier, etc.)"
echo "5. Check Activity Logs page to see logged actions"
echo ""
echo "To check database logs directly:"
echo "php bin/console dbal:run-sql 'SELECT * FROM activity_log ORDER BY created_at DESC LIMIT 10'"
echo ""
echo "To monitor logs in real-time:"
echo "tail -f var/log/dev.log | grep -i activity"
echo ""
