#!/bin/bash

echo "========================================="
echo "Activity Log Debug Helper"
echo "========================================="
echo ""

cd /Users/lichtttt/webdev/kmerch

echo "1. Clearing logs..."
> var/log/dev.log

echo "2. Cache cleared - ready for testing"
echo ""
echo "========================================="
echo "Now perform these steps:"
echo "========================================="
echo ""
echo "1. Go to Supplier Management in your browser"
echo "2. Create, update, or delete a supplier"
echo "3. Then run: ./view_supplier_logs.sh"
echo ""
echo "This will show you detailed debug logs"
echo ""
