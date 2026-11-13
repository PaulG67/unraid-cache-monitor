#!/bin/bash
CFG=/boot/config/plugins/cache-monitor/cache-monitor.cfg
mkdir -p /boot/config/plugins/cache-monitor /var/local/cache-monitor
if [ ! -f "$CFG" ]; then
  printf '%s\n' \
    'WARN_PERCENT="80"' \
    'CRIT_PERCENT="90"' \
    'META_PERCENT="85"' \
    'FORECAST_DAYS="14"' \
    'SCAN_HOURS="6"' \
    'DEPTH="2"' \
    'NOTIFY="yes"' \
    'POOL=""' > "$CFG"
fi
chmod 755 /usr/local/emhttp/plugins/cache-monitor/scripts/collect.php 2>/dev/null
if [ -f /var/run/crond.pid ]; then
  kill -HUP "$(cat /var/run/crond.pid)" 2>/dev/null
fi
exit 0
