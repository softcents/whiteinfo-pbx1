#!/bin/bash
# ==============================================================================
# Softcents Systems - WhiteInfo PBX Automated Update Worker
# Repository: https://github.com/softcents/whiteinfo-pbx1.git
# ==============================================================================
set -e

LOCKFILE="/tmp/pbx_auto_update.lock"
LOGFILE="/var/log/pbx-auto-update.log"
APP_DIR="/var/www/fusionpbx"

# Prevent concurrent execution
exec 200>"$LOCKFILE"
flock -n 200 || exit 0

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOGFILE"
}

if [ ! -d "$APP_DIR/.git" ]; then
    exit 0
fi

cd "$APP_DIR"
git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true

# Fetch latest refs from remote
git fetch origin main >/dev/null 2>&1 || exit 0

LOCAL=$(git rev-parse HEAD 2>/dev/null)
REMOTE=$(git rev-parse origin/main 2>/dev/null)

if [ "$LOCAL" != "$REMOTE" ] && [ -n "$REMOTE" ]; then
    log "New updates detected on GitHub. Applying update: $LOCAL -> $REMOTE..."
    
    # Reset and pull latest updates
    git reset --hard origin/main >> "$LOGFILE" 2>&1
    
    # Restore permissions
    chown -R nobody:nogroup "$APP_DIR" 2>/dev/null || chown -R www-data:www-data "$APP_DIR" 2>/dev/null || true
    chmod -R 755 "$APP_DIR"
    
    # Run database schema & domain migrations
    php "$APP_DIR/core/upgrade/upgrade_schema.php" >> "$LOGFILE" 2>&1 || true
    php "$APP_DIR/core/upgrade/upgrade_domains.php" >> "$LOGFILE" 2>&1 || true
    
    # Re-apply Softcents Systems branding in PostgreSQL
    if [ -f "/etc/fusionpbx/config.conf" ]; then
        DB_PASS=$(grep "database.0.password" /etc/fusionpbx/config.conf | awk -F'=' '{print $2}' | tr -d ' ')
        PGPASSWORD="$DB_PASS" psql -U fusionpbx -h 127.0.0.1 -d fusionpbx -c "
        UPDATE v_default_settings 
        SET default_setting_value = '&copy; Copyright 2026 <a href=\"https://www.softcents.com\" class=\"footer\" target=\"_blank\">Softcents Systems</a>. All rights reserved.'
        WHERE default_setting_category = 'theme' AND default_setting_subcategory = 'footer';
        " >> "$LOGFILE" 2>&1 || true
    fi
    
    # Clear runtime cache
    rm -rf /var/cache/fusionpbx/* 2>/dev/null || true
    
    # Reload web server
    systemctl reload nginx >/dev/null 2>&1 || service nginx reload >/dev/null 2>&1 || true
    
    log "PBX auto-update completed successfully."
fi
