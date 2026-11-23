#!/bin/bash
# ==============================================================================
# Softcents Systems - WhiteInfo PBX 1-Command Automated Installer for Debian
# Repository: https://github.com/softcents/whiteinfo-pbx1.git
# ==============================================================================
set -e

# Check for root privileges
if [ "$(id -u)" -ne 0 ]; then
    echo -e "\e[31m[ERROR]\e[0m This installer must be executed as root (or with sudo)."
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive

echo -e "\e[34m===============================================================\e[0m"
echo -e "\e[1;32m Softcents Systems - WhiteInfo PBX 1-Command Debian Installer \e[0m"
echo -e "\e[34m===============================================================\e[0m"

echo -e "\e[33m[1/6] Updating system packages...\e[0m"
apt-get update && apt-get upgrade -y

echo -e "\e[33m[2/6] Installing prerequisite packages...\e[0m"
apt-get install -y git curl wget lsb-release ca-certificates apt-transport-https gnupg unzip zip nano dialog

echo -e "\e[33m[3/6] Setting up PBX installer framework...\e[0m"
mkdir -p /usr/src
if [ -d "/usr/src/fusionpbx-install.sh" ]; then
    cd /usr/src/fusionpbx-install.sh && git pull 2>/dev/null || true
else
    cd /usr/src && git clone https://github.com/fusionpbx/fusionpbx-install.sh.git
fi

cd /usr/src/fusionpbx-install.sh/debian

# Configure installer to use our repository and branch
sed -i 's|https://github.com/fusionpbx/fusionpbx.git|https://github.com/softcents/whiteinfo-pbx1.git|g' resources/fusionpbx.sh 2>/dev/null || true
sed -i 's|system_branch=.*|system_branch=main|g' resources/config.sh 2>/dev/null || true
sed -i 's|git clone \$branch https://github.com/softcents/whiteinfo-pbx1.git|git clone -b main https://github.com/softcents/whiteinfo-pbx1.git|g' resources/fusionpbx.sh 2>/dev/null || true

echo -e "\e[33m[4/6] Installing FreeSWITCH, PostgreSQL, PHP, Nginx, and Core VoIP stack...\e[0m"
# If already installed, don't re-run full stack, just pull latest code
if [ -f "/etc/fusionpbx/config.conf" ] && [ -d "/var/www/fusionpbx" ]; then
    echo "Existing PBX stack detected. Updating repository code..."
    cd /var/www/fusionpbx
    git remote set-url origin https://github.com/softcents/whiteinfo-pbx1.git 2>/dev/null || true
    git fetch origin
    git checkout -B main origin/main
    git pull origin main
else
    # Run full automated install
    ./install.sh
fi

echo -e "\e[33m[5/6] Finalizing permissions and database setup...\e[0m"
# Set directory permissions
chown -R nobody:nogroup /var/www/fusionpbx 2>/dev/null || chown -R www-data:www-data /var/www/fusionpbx
chmod -R 755 /var/www/fusionpbx

mkdir -p /var/cache/fusionpbx
chown -R nobody:nogroup /var/cache/fusionpbx 2>/dev/null || chown -R www-data:www-data /var/cache/fusionpbx
chmod -R 775 /var/cache/fusionpbx

# Upgrade schema and domains
cd /var/www/fusionpbx
php core/upgrade/upgrade_schema.php 2>/dev/null || true
php core/upgrade/upgrade_domains.php 2>/dev/null || true

# Update copyright to Softcents Systems in PostgreSQL
if [ -f "/etc/fusionpbx/config.conf" ]; then
    DB_PASS=$(grep "database.0.password" /etc/fusionpbx/config.conf | awk -F'=' '{print $2}' | tr -d ' ')
    PGPASSWORD="$DB_PASS" psql -U fusionpbx -h 127.0.0.1 -d fusionpbx -c "
    UPDATE v_default_settings 
    SET default_setting_value = '&copy; Copyright 2026 <a href=\"https://www.softcents.com\" class=\"footer\" target=\"_blank\">Softcents Systems</a>. All rights reserved.'
    WHERE default_setting_category = 'theme' AND default_setting_subcategory = 'footer';
    " 2>/dev/null || true
fi

# Clear runtime cache
rm -rf /var/cache/fusionpbx/*

# Reload Nginx
systemctl reload nginx 2>/dev/null || service nginx reload 2>/dev/null || true

SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}' || echo "your-server-ip")

echo -e "\e[34m===============================================================\e[0m"
echo -e "\e[1;32m [SUCCESS] Softcents WhiteInfo PBX Installation Complete! \e[0m"
echo -e "\e[34m===============================================================\e[0m"
echo -e " Web Interface : https://${SERVER_IP}/"
echo -e " Branding      : Softcents Systems (https://www.softcents.com)"
echo -e " Repository    : https://github.com/softcents/whiteinfo-pbx1"
echo -e "\e[34m===============================================================\e[0m"
