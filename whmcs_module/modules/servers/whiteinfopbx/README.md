# WhiteInfo PBX Multi-Server Provisioning & Billing Module for WHMCS

**Module Version:** 1.5.0 (Multi-Server Enterprise Edition)  
**Supported PBX:** FusionPBX 5.x / FreeSWITCH 1.10.x / Debian 12  
**Supported WHMCS:** WHMCS 7.x & WHMCS 8.x (PHP 7.4 - PHP 8.4)  
**Copyright:** &copy; 2026 [Softcents Systems](https://www.softcents.com). All rights reserved.

---

## Key Features

1. **Flexible Deployment Modes**:
   - **Mode A (Cluster Mode - Recommended):** Works with WHMCS Server Groups & Load Balancing (`pbx1`, `pbx2`, etc.).
   - **Mode B (Standalone Mode):** Works directly via Product Module Settings without needing a Server Group.

2. **Automated Tenant Subdomain & Web Admin Provisioning**:
   - Company Name: `{companyname}-pbx.whiteinfobd.com` (e.g., `apexlimited-pbx.whiteinfobd.com`).
   - Personal Name fallback: `{firstname}{lastname}-pbx.whiteinfobd.com`.
   - Generates tenant admin user and password (`Apexlimited@2010`).
   - Scopes `admin` and `company` permissions to the specific tenant subdomain.

3. **Package Extensions & Free Unbilled Support Extension 999**:
   - Auto-creates package extensions (e.g. 101, 102, 103).
   - Auto-creates Free Support Extension `999` (never billed, always active).
   - Configures Ring Group 6000 and connects outbound routes automatically.

4. **Non-Payment Suspension & Instant Reactivation**:
   - **On Overdue / Suspension:** All customer extensions (101, 102, 103...) are disabled and SIP registrations flushed.
   - **Extension 999 remains 100% ACTIVE** for free emergency and support calling.
   - **On Payment / Unsuspension:** All package extensions are instantly restored.

5. **Strictly Enforced No-Termination Policy ("terminet hobe na")**:
   - On service termination/cancellation, extensions are safely locked.
   - Subdomain, extensions, CDR history, and 30-day recordings are **NEVER deleted**.

6. **Package Upgrade / Downgrade (ChangePackage)**:
   - Increasing extension count creates additional extensions and updates Ring Group 6000.
   - Downsizing disables extra extensions safely.

7. **Native 1-Click Single Sign-On (SSO)**:
   - One-click login into PBX portal from both WHMCS Client Area and WHMCS Admin Area without typing passwords.

8. **Client Area Dashboard & Self-Service IP Unban**:
   - Extensions table displaying extension number, caller ID, and **SIP Passwords** with copy-to-clipboard.
   - Change extension password directly from WHMCS client area.
   - Self-service IP Unban tool for clients whose office IPs were blocked by Event Guard / Fail2ban due to wrong password attempts.
   - MicroSIP softphone setup instructions.

9. **Full WHMCS Module Logging**:
   - All API transactions are logged to WHMCS **System Logs > Module Log** (`Utilities > Logs > Module Log`).

---

## Installation Guide

### Step 1: Upload Module to WHMCS
Extract or upload the `modules` folder into your WHMCS root directory:
```bash
cd /path/to/whmcs
wget -O whiteinfopbx_whmcs_module.zip https://pbx1.whiteinfobd.com/whiteinfopbx_whmcs_module.zip
unzip -o whiteinfopbx_whmcs_module.zip
rm whiteinfopbx_whmcs_module.zip
```
Ensure directory permissions allow web server access:
`modules/servers/whiteinfopbx/whiteinfopbx.php`

---

### Step 2: Configure PBX Server in WHMCS

#### Method A: Multi-Server / Server Group Setup (Recommended)
1. Go to **Setup** ➔ **Products/Services** ➔ **Servers** (or **System Settings** ➔ **Servers** in WHMCS 8).
2. Click **Add New Server**:
   - **Name:** `PBX-01 (Primary)`
   - **Hostname:** `pbx1.whiteinfobd.com`
   - **IP Address:** `165.99.219.124`
   - **Module / Type:** `WhiteInfo PBX Multi-Server Billing & Provisioning`
   - **Access Hash / API Token:** `wipbx_557bc41210f5caef05087a48b883fb6f5bf4b1dc7503429f`
   - **Secure:** Check `Tick to use SSL for Connections` (Port: `443`)
   - Click **Test Connection** ➔ You will receive a green **Success** confirmation!
3. Click **Create New Group**:
   - **Group Name:** `PBX Production Cluster`
   - Add `PBX-01 (Primary)` to the group and save.
4. Go to **Products/Services** ➔ Select your PBX product ➔ **Module Settings**:
   - **Module Name:** `WhiteInfo PBX Multi-Server Billing & Provisioning`
   - **Server Group:** Select `PBX Production Cluster`.
   - **Package Extensions Count:** `3` (or configurable).
   - **Domain Suffix:** `whiteinfobd.com`
   - Save Changes.

#### Method B: Standalone Setup (Without Server Group)
1. Go to **Products/Services** ➔ Select your PBX product ➔ **Module Settings**:
   - **Module Name:** `WhiteInfo PBX Multi-Server Billing & Provisioning`
   - **Server Group:** `None`
   - **Package Extensions Count:** `3`
   - **Domain Suffix:** `whiteinfobd.com`
   - **PBX API URL:** `https://pbx1.whiteinfobd.com/billing_api.php`
   - **PBX API Key:** `wipbx_557bc41210f5caef05087a48b883fb6f5bf4b1dc7503429f`
   - Save Changes.

---

## Configurable Options & Custom Fields (Optional)

You can allow clients to choose extension counts and inbound DIDs during checkout:

1. **Configurable Option for Extension Count:**
   - Option Name: `Extensions` or `Number of Extensions`
   - Option Type: Dropdown
   - Options:
     - `3|3 Extensions (Starter)`
     - `5|5 Extensions (Standard)`
     - `10|10 Extensions (Enterprise)`

2. **Custom Field for Inbound DID:**
   - Field Name: `DID`
   - Field Type: Text Box
   - Description: `Enter assigned DID phone number (e.g. 09639228920)`

---

## Troubleshooting

- **Check WHMCS Module Log:** Go to **Utilities** ➔ **Logs** ➔ **Module Log**. Check every request and response.
- **Check PBX Server Log:** Run on the PBX server:
  ```bash
  tail -f /var/log/fusionpbx/billing_api.log
  ```
- **IP Firewall / Event Guard:** If a client cannot register SIP, use the **Unblock Client IP** button on the client service page, or the client can unblock themselves from the WHMCS Client Area.
