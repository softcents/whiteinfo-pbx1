# Softcents Systems - WhiteInfo PBX Setup & Deployment Guide (Standard Linux / Debian / Ubuntu)

## 1. Project Overview & Session Changelog

### 1.1 Project Information
* **Repository:** [https://github.com/softcents/whiteinfo-pbx1](https://github.com/softcents/whiteinfo-pbx1)
* **Default Branch:** `main`
* **Branding & Copyright:** Softcents Systems ([https://www.softcents.com](https://www.softcents.com))
* **Base Architecture:** FusionPBX 5.6 + FreeSWITCH + PostgreSQL 18 + PHP 8.4 FPM + Nginx (Debian / Ubuntu)

### 1.2 Summary of Updates Performed
1. **Original Themes & Widgets Restored:**
   * Restored original FusionPBX dashboard ([core/dashboard/index.php](file:///var/www/fusionpbx/core/dashboard/index.php)).
   * Restored original widget implementations for `icon.php`, `active_calls.php`, `registrations.php`, and `system_status.php`.
   * Preserved previous custom "White Info" theme files in [themes/whiteinfo_backup/](file:///var/www/fusionpbx/themes/whiteinfo_backup/) for reference.
2. **Logos & Branding:**
   * Restored and preserved the custom company logos in [themes/default/images/](file:///var/www/fusionpbx/themes/default/images/):
     * `logo.png`, `logo_login.png`, `logo_header.png`
     * `logo_side_expanded.png`, `logo_side_contracted.png`
     * `favicon.ico`
3. **Copyright & Footer Updates:**
   * Configured copyright text across the system to:
     `&copy; Copyright 2026 <a href="https://www.softcents.com" class="footer" target="_blank">Softcents Systems</a>. All rights reserved.`
   * Updated [resources/footer.php](file:///var/www/fusionpbx/resources/footer.php) with fallback and unescaped HTML support for the footer.
   * Updated all authentication views (`login.htm`, `email.htm`, `passkey.htm`, `totp.htm`, `username.htm`).
   * Updated PostgreSQL default setting in table `v_default_settings` (`theme -> footer`).
4. **Git Repository Setup:**
   * Cleaned all custom sub-modules (`app/device_logs`, `app/language_model`, `app/speech`, `app/transcribe`, `billing_api.php`, `sso.php`, WHMCS modules).
   * Pushed full codebase to `git@github.com:softcents/whiteinfo-pbx1.git` on branch `main`.

---

## 2. System Architecture

```mermaid
flowchart TD
    Client["Client / Web Browser / Softphone"]
    Nginx["Nginx Reverse Proxy & Static Server"]
    PHP["PHP-FPM 8.4 (unix:/var/run/php/php8.4-fpm.sock)"]
    Postgres[("PostgreSQL 18 (DB: fusionpbx)")]
    FreeSWITCH["FreeSWITCH VoIP Engine"]
    Cache["Runtime File Cache (/var/cache/fusionpbx)"]

    Client -->|HTTP/HTTPS Port 80/443| Nginx
    Client -->|SIP Port 5060 / WebRTC| FreeSWITCH
    Nginx -->|FastCGI| PHP
    Nginx -->|WebSocket Proxy /websockets/| FreeSWITCH
    PHP -->|PDO PGSQL (127.0.0.1:5432)| Postgres
    PHP -->|Event Socket ESL (127.0.0.1:8021)| FreeSWITCH
    PHP -->|Read/Write Cache| Cache
```

---

## 3. Server Setup & Deployment Guide for Developers (Debian / Ubuntu)

### Step 1: Base Packages & Dependencies

Install Nginx, PHP, PostgreSQL, FreeSWITCH, and required utilities:
```bash
# Update repositories
sudo apt update && sudo apt upgrade -y

# Install system dependencies
sudo apt install -y git curl wget unzip lsb-release ca-certificates apt-transport-https

# Install Nginx
sudo apt install -y nginx

# Install PostgreSQL
sudo apt install -y postgresql postgresql-contrib

# Install PHP and extensions (PHP 8.2, 8.3, or 8.4)
sudo apt install -y php php-cli php-fpm php-pgsql php-sqlite3 php-gd php-curl \
    php-mbstring php-xml php-zip php-ldap php-soap php-opcache
```

---

### Step 2: Codebase Setup

Clone the repository directly into `/var/www/fusionpbx`:
```bash
# Clone the repository
sudo git clone https://github.com/softcents/whiteinfo-pbx1.git /var/www/fusionpbx

# Set file permissions (Web server runs as nobody:nogroup or www-data:www-data)
sudo chown -R nobody:nogroup /var/www/fusionpbx
sudo chmod -R 755 /var/www/fusionpbx

# Create runtime cache directory
sudo mkdir -p /var/cache/fusionpbx
sudo chown -R nobody:nogroup /var/cache/fusionpbx
sudo chmod -R 775 /var/cache/fusionpbx
```

---

### Step 3: PostgreSQL Database Setup

```bash
# Switch to postgres user and create user/database
sudo -u postgres psql -c "CREATE USER fusionpbx WITH PASSWORD 'YourStrongPasswordHere';"
sudo -u postgres psql -c "CREATE DATABASE fusionpbx OWNER fusionpbx;"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE fusionpbx TO fusionpbx;"
```

---

### Step 4: System Configuration File (`/etc/fusionpbx/config.conf`)

Create the configuration directory and file:
```bash
sudo mkdir -p /etc/fusionpbx
sudo nano /etc/fusionpbx/config.conf
```

Add the configuration content:
```ini
#database system settings
database.0.type = pgsql
database.0.host = 127.0.0.1
database.0.port = 5432
database.0.sslmode = prefer
database.0.name = fusionpbx
database.0.username = fusionpbx
database.0.password = YourStrongPasswordHere

#database switch settings
database.1.type = sqlite
database.1.path = /var/lib/freeswitch/db
database.1.name = core.db

#general settings
document.root = /var/www/fusionpbx
project.path =
temp.dir = /tmp
php.dir = /usr/bin
php.bin = php 

#security settings
security.header.content_security_policy.frame_ancestors =

#session settings
session.cookie_httponly = true
session.cookie_secure = true
session.cookie_samesite = Lax

#cache settings
cache.method = file
cache.location = /var/cache/fusionpbx
cache.settings = true

#switch settings
switch.conf.dir = /etc/freeswitch
switch.sounds.dir = /usr/share/freeswitch/sounds
switch.database.dir = /var/lib/freeswitch/db
switch.recordings.dir = /var/lib/freeswitch/recordings
switch.storage.dir = /var/lib/freeswitch/storage
switch.voicemail.dir = /var/lib/freeswitch/storage/voicemail
switch.scripts.dir = /usr/share/freeswitch/scripts

#switch xml handler
xml_handler.fs_path = false
xml_handler.reg_as_number_alias = false
xml_handler.number_as_presence_id = true

#error reporting options: user,dev,all
error.reporting = user
```

Secure the file permissions:
```bash
sudo chmod 640 /etc/fusionpbx/config.conf
sudo chown -R nobody:nogroup /etc/fusionpbx
```

---

### Step 5: Nginx Virtual Host Configuration

Create `/etc/nginx/sites-available/fusionpbx`:
```bash
sudo nano /etc/nginx/sites-available/fusionpbx
```

Configuration block:
```nginx
server {
    listen 80;
    server_name pbx.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name pbx.yourdomain.com;
    charset utf-8;

    # SSL configuration
    # ssl_certificate     /etc/ssl/certs/pbx.crt;
    # ssl_certificate_key /etc/ssl/private/pbx.key;

    root /var/www/fusionpbx;
    index index.php;

    client_max_body_size 80M;
    client_body_buffer_size 128k;

    # WebSocket Proxy for audio and event socket
    location /websockets/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
    }

    # REST API rewrite
    location ~* ^.*/api/.*$ {
        rewrite ^(.*)/api/(.*)$ $1/api/index.php?rewrite_uri=$2 break;
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/fusionpbx/app/api/index.php;
        fastcgi_param SCRIPT_NAME /app/api/index.php;
    }

    # Main application routing
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM execution
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_read_timeout 15m;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/fusionpbx$fastcgi_script_name;
    }

    # Extended timeout for upgrades
    location = /core/upgrade/index.php {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_read_timeout 15m;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/fusionpbx$fastcgi_script_name;
    }

    # Security: Block sensitive file access
    location ~ (\.git|\.htaccess|\.db|\.lua|\.orig|\.bak) {
        deny all;
    }
}
```

Enable the site and reload Nginx:
```bash
sudo ln -sf /etc/nginx/sites-available/fusionpbx /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

---

### Step 6: Initial Database Schema Upgrade & App Defaults

Run the schema initialization and upgrade routines:
```bash
cd /var/www/fusionpbx
sudo -u nobody php core/upgrade/upgrade_schema.php
sudo -u nobody php core/upgrade/upgrade_domains.php
```

Or visit the GUI at `https://pbx.yourdomain.com/core/upgrade/`:
* Check **App Defaults**, **Menu Defaults**, **Permission Defaults**, and **Schema**.
* Click **Upgrade**.

---

### Step 7: Verifying Branding & Theme Settings

1. Login to the PBX as `superadmin`.
2. Go to **Advanced** -> **Default Settings**.
3. Under Category **Theme**, ensure:
   * **Subcategory:** `footer`
   * **Value:** `&copy; Copyright 2026 <a href="https://www.softcents.com" class="footer" target="_blank">Softcents Systems</a>. All rights reserved.`
   * **Enabled:** `True`
4. Clear cache whenever settings or assets are updated:
   ```bash
   sudo rm -rf /var/cache/fusionpbx/*
   ```

---

## 4. Key Directories & Custom Modules

| Directory / File | Description |
|---|---|
| `/var/www/fusionpbx/` | Application root directory |
| `/etc/fusionpbx/config.conf` | Database and system configuration |
| `/var/cache/fusionpbx/` | Cache store (must be writable by web server) |
| `themes/default/` | Active UI theme template |
| `themes/default/images/` | Company logo assets & favicon |
| `themes/whiteinfo_backup/` | Backup copy of custom card layout & widgets |
| `resources/footer.php` | Global footer copyright handling |
| `core/authentication/resources/views/` | Login, passkey, totp, username templates |
| `billing_api.php` | Custom PBX billing API endpoint |
| `sso.php` | Single Sign-On bridge |
| `whiteinfopbx_whmcs_module.zip` | WHMCS PBX integration module |
| `app/device_logs/` | Device syslog & diagnostic module |
| `app/language_model/` | AI / LLM voice & text integration module |
| `app/speech/` | Speech synthesis & TTS module |
| `app/transcribe/` | Voice transcription engine |

---
*Maintained by Softcents Systems (https://www.softcents.com)*
