#!/bin/bash
set -e
mkdir -p /boot/config/plugins/cache-monitor
CFG=/boot/config/plugins/cache-monitor/cache-monitor.cfg
if [ ! -f "$CFG" ]; then
  cat > "$CFG" << 'EOF'
WARN_PERCENT="80"
CRIT_PERCENT="90"
META_PERCENT="85"
FORECAST_DAYS="14"
SCAN_HOURS="6"
DEPTH="2"
NOTIFY="yes"
POOL=""
EOF
fi
chmod 755 /usr/local/emhttp/plugins/cache-monitor/scripts/collect.php
if [ -f /var/run/crond.pid ]; then
  kill -HUP "$(cat /var/run/crond.pid)" 2>/dev/null || true
fi
nohup php -f /usr/local/emhttp/plugins/cache-monitor/scripts/collect.php -- --full >/dev/null 2>&1 &
exit 0
