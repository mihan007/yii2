#!/usr/bin/env bash
dt=$(date '+%d/%m/%Y %H:%M:%S');
echo "$dt"
echo "Cleaning runtime files"
cd /var/www/lk.autoscan.online/console/runtime
find . -type f -name '*' | xargs rm
dt=$(date '+%d/%m/%Y %H:%M:%S');
echo "$dt"
echo "Cleaning log files"
rm -f /var/www/lk.autoscan.online/log/*.log.*
dt=$(date '+%d/%m/%Y %H:%M:%S');