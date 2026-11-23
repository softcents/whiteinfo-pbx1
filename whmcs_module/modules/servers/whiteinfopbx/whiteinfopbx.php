<?php
/**
 * WHMCS Provisioning & Management Module for WhiteInfo PBX (FusionPBX / FreeSWITCH)
 * 
 * Module Name: whiteinfopbx
 * Version: 1.5.0 (Multi-Server Enterprise Edition)
 * Copyright: (c) 2026 Softcents Systems (https://www.softcents.com). All rights reserved.
 * 
 * Features:
 * - Compatible with WHMCS 7.x & 8.x (PHP 7.4 - PHP 8.4)
 * - Flexible Deployment: Works via WHMCS Server Groups OR Direct Product Module Settings
 * - Automatic Company-based Subdomain Generation ({slug}-pbx.whiteinfobd.com)
 * - Automated Provisioning of Package Extensions (e.g. 101, 102, 103)
 * - Automatic Extension 999 Creation (FREE / UNBILLED / ALWAYS ACTIVE)
 * - Main Ring Group 6000 & Outbound Gateway Automation
 * - Non-Payment Suspension: Package extensions disabled, Extension 999 REMAINS ACTIVE
 * - Payment Reactivation: Package extensions instantly restored
 * - Strictly Enforced No-Termination Policy ("terminet hobe na"): Soft lock, zero data loss
 * - Automated Package Upgrade / Downgrade (ChangePackage)
 * - Native 1-Click Single Sign-On (SSO) for Client and Admin
 * - Native WHMCS Server Connection Test (TestConnection)
 * - Full WHMCS Module Logging (logModuleCall)
 * - Rich Client Area Dashboard with Extension Passwords, MicroSIP Guide, and Self-Service IP Unban
 * - Admin Area Extension Password Management Tab
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Module Metadata
 */
function whiteinfopbx_MetaData() {
    return [
        'DisplayName' => 'WhiteInfo PBX Multi-Server Billing & Provisioning',
        'APIVersion' => '1.5',
        'RequiresServer' => false,
        'DefaultNonSSLPort' => '80',
        'DefaultSSLPort' => '443',
    ];
}

/**
 * Module Configuration Options
 */
function whiteinfopbx_ConfigOptions() {
    return [
        'Package Extensions Count' => [
            'Type' => 'text',
            'Size' => '5',
            'Default' => '3',
            'Description' => 'Default package extensions (e.g. 3 creates 101, 102, 103 + Free Ext 999).',
        ],
        'Domain Suffix' => [
            'Type' => 'text',
            'Size' => '35',
            'Default' => 'whiteinfobd.com',
            'Description' => 'Base domain suffix for auto-generated subdomains (e.g. whiteinfobd.com).',
        ],
        'Default Inbound DID' => [
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Default Inbound DID / Caller ID (can also be set via custom field "DID").',
        ],
        'PBX API URL' => [
            'Type' => 'text',
            'Size' => '60',
            'Default' => 'https://pbx1.whiteinfobd.com/billing_api.php',
            'Description' => 'PBX API endpoint (used if no WHMCS Server is assigned).',
        ],
        'PBX API Key' => [
            'Type' => 'password',
            'Size' => '60',
            'Default' => 'wipbx_557bc41210f5caef05087a48b883fb6f5bf4b1dc7503429f',
            'Description' => 'PBX API Key (used if no Server Access Hash is configured).',
        ],
        'Auto Cloudflare DNS' => [
            'Type' => 'yesno',
            'Default' => 'no',
            'Description' => 'Optional: Update Cloudflare DNS (default no, wildcard DNS covers *.whiteinfobd.com).',
        ],
        'Cloudflare API Token' => [
            'Type' => 'password',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Cloudflare API Token (optional, only needed if Auto Cloudflare DNS is enabled).',
        ],
        'Cloudflare Zone ID' => [
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Cloudflare Zone ID (optional).',
        ],
    ];
}

/**
 * Optional Cloudflare DNS Helper
 */
function whiteinfopbx_EnsureCloudflareDns($subdomain, $targetIp, $params = []) {
    $cfToken = !empty($params['configoption7']) ? trim($params['configoption7']) : (!empty($params['configoption5']) ? trim($params['configoption5']) : '');
    $zoneId  = !empty($params['configoption8']) ? trim($params['configoption8']) : (!empty($params['configoption6']) ? trim($params['configoption6']) : '');
    
    if (empty($cfToken) || empty($zoneId) || empty($subdomain) || empty($targetIp)) {
        return false;
    }
    
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records?name=" . urlencode($subdomain) . "&type=A");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$cfToken}",
        "Content-Type: application/json"
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    $recordId   = $res['result'][0]['id'] ?? null;
    $existingIp = $res['result'][0]['content'] ?? null;
    
    if ($recordId && $existingIp === $targetIp) {
        return true;
    }
    
    $payload = json_encode([
        "type" => "A",
        "name" => $subdomain,
        "content" => $targetIp,
        "ttl" => 120,
        "proxied" => false
    ]);
    
    if ($recordId) {
        $ch = curl_init("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records/{$recordId}");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    } else {
        $ch = curl_init("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records");
        curl_setopt($ch, CURLOPT_POST, true);
    }
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$cfToken}",
        "Content-Type: application/json"
    ]);
    $out = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    return ($out['success'] ?? false) === true;
}

/**
 * Multi-Server Dynamic API Caller
 */
function whiteinfopbx_ApiCall($params, $action, $extraData = []) {
    // 1. Resolve Server / API URL
    $apiUrl = '';
    if (!empty($params['serverhostname'])) {
        $proto = (!empty($params['serversecure']) || (isset($params['serverport']) && $params['serverport'] == 443)) ? 'https://' : 'https://';
        if (isset($params['serversecure']) && ($params['serversecure'] === 'off' || $params['serversecure'] === false)) {
            $proto = 'http://';
        }
        $portStr = (!empty($params['serverport']) && !in_array($params['serverport'], [80, 443])) ? ':' . $params['serverport'] : '';
        $apiUrl = $proto . trim($params['serverhostname']) . $portStr . '/billing_api.php';
    } elseif (!empty($params['serverip'])) {
        $proto = (!empty($params['serversecure']) || (isset($params['serverport']) && $params['serverport'] == 443)) ? 'https://' : 'https://';
        if (isset($params['serversecure']) && ($params['serversecure'] === 'off' || $params['serversecure'] === false)) {
            $proto = 'http://';
        }
        $portStr = (!empty($params['serverport']) && !in_array($params['serverport'], [80, 443])) ? ':' . $params['serverport'] : '';
        $apiUrl = $proto . trim($params['serverip']) . $portStr . '/billing_api.php';
    } elseif (!empty($params['configoption4']) && strpos($params['configoption4'], 'http') !== false) {
        $apiUrl = trim($params['configoption4']);
    } elseif (!empty($params['configoption2']) && strpos($params['configoption2'], 'http') !== false) {
        $apiUrl = trim($params['configoption2']);
    } elseif (!empty($params['configoption7']) && strpos($params['configoption7'], 'http') !== false) {
        $apiUrl = trim($params['configoption7']);
    } else {
        $apiUrl = 'https://pbx1.whiteinfobd.com/billing_api.php';
    }

    // 2. Resolve API Key
    $apiKey = '';
    if (!empty($params['serveraccesshash'])) {
        $apiKey = trim($params['serveraccesshash']);
    } elseif (!empty($params['serverpassword'])) {
        $apiKey = trim($params['serverpassword']);
    } elseif (!empty($params['configoption5']) && strpos($params['configoption5'], 'wipbx_') === 0) {
        $apiKey = trim($params['configoption5']);
    } elseif (!empty($params['configoption3']) && strpos($params['configoption3'], 'wipbx_') === 0) {
        $apiKey = trim($params['configoption3']);
    } elseif (!empty($params['configoption8'])) {
        $apiKey = trim($params['configoption8']);
    } else {
        $apiKey = 'wipbx_557bc41210f5caef05087a48b883fb6f5bf4b1dc7503429f';
    }

    // Clean domain
    $cleanDomain = trim($params['domain'] ?? '');
    $cleanDomain = preg_replace('#^https?://#i', '', $cleanDomain);
    $cleanDomain = trim(rtrim($cleanDomain, '/'));
    if (strpos($cleanDomain, ':') !== false) {
        $cleanDomain = explode(':', $cleanDomain)[0];
    }

    $payload = array_merge([
        'action' => $action,
        'domain' => $cleanDomain,
        'api_key' => $apiKey,
    ], $extraData);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-KEY: ' . $apiKey,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = is_string($response) ? json_decode($response, true) : null;

    if (function_exists('logModuleCall')) {
        logModuleCall('whiteinfopbx', $action, $payload, $response, $data, [$apiKey]);
    }

    if ($curlError) {
        return ['status' => 'error', 'message' => 'cURL Error (' . $apiUrl . '): ' . $curlError];
    }

    if (!is_array($data)) {
        return ['status' => 'error', 'message' => 'Invalid JSON from PBX (' . $apiUrl . ' - HTTP ' . $httpCode . '): ' . substr((string)$response, 0, 200)];
    }

    return $data;
}

/**
 * Helper: Resolve Extension Count
 */
function whiteinfopbx_ResolveExtensionCount($params) {
    $extCount = 3;
    if (!empty($params['configoptions']) && is_array($params['configoptions'])) {
        foreach ($params['configoptions'] as $cfgKey => $cfgVal) {
            if (stripos($cfgKey, 'ext') !== false) {
                $val = intval(explode('|', (string)$cfgVal)[0]);
                if ($val > 0) {
                    return $val;
                }
            }
        }
    }
    if (!empty($params['customfields']) && is_array($params['customfields'])) {
        foreach ($params['customfields'] as $cfKey => $cfVal) {
            if (stripos($cfKey, 'ext') !== false) {
                $val = intval($cfVal);
                if ($val > 0) {
                    return $val;
                }
            }
        }
    }
    if (!empty($params['configoption1'])) {
        $val = intval($params['configoption1']);
        if ($val > 0) {
            return $val;
        }
    }
    return $extCount;
}

/**
 * Helper: Resolve DID
 */
function whiteinfopbx_ResolveDID($params) {
    if (!empty($params['customfields']) && is_array($params['customfields'])) {
        foreach ($params['customfields'] as $cfKey => $cfVal) {
            if (stripos($cfKey, 'did') !== false || stripos($cfKey, 'caller') !== false || stripos($cfKey, 'phone') !== false) {
                if (!empty(trim((string)$cfVal))) {
                    return trim((string)$cfVal);
                }
            }
        }
    }
    if (!empty($params['configoption3']) && strpos($params['configoption3'], 'wipbx_') === false && strpos($params['configoption3'], 'http') === false) {
        return trim($params['configoption3']);
    }
    return '';
}

/**
 * Helper: Safe Password Encryption
 */
function whiteinfopbx_EncryptPassword($pass) {
    if (function_exists('encrypt')) {
        return encrypt($pass);
    }
    if (class_exists('\WHMCS\Input\Sanitize') && method_exists('\WHMCS\Input\Sanitize', 'encode')) {
        return \WHMCS\Input\Sanitize::encode($pass);
    }
    return $pass;
}

/**
 * Service Provisioning: Create Account
 */
function whiteinfopbx_CreateAccount($params) {
    try {
        $serviceId = $params['serviceid'];

        // 1. Resolve Company Name or Customer Personal Name
        $companyName = trim($params['clientsdetails']['companyname'] ?? '');
        if (empty($companyName)) {
            $firstName = trim($params['clientsdetails']['firstname'] ?? '');
            $lastName = trim($params['clientsdetails']['lastname'] ?? '');
            $displayName = trim($firstName . ' ' . $lastName);
            if (empty($displayName)) {
                $displayName = 'Client ' . $serviceId;
            }
        } else {
            $displayName = $companyName;
        }

        // 2. Generate clean alphanumeric slug
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $displayName));
        if (empty($slug)) {
            $slug = 'client' . $serviceId;
        }

        // 3. Resolve Subdomain: {slug}-pbx.whiteinfobd.com
        $domainSuffix = 'whiteinfobd.com';
        if (!empty($params['configoption2']) && strpos($params['configoption2'], 'http') === false) {
            $domainSuffix = trim($params['configoption2']);
        } elseif (!empty($params['configoption5']) && strpos($params['configoption5'], 'white') !== false) {
            $domainSuffix = trim($params['configoption5']);
        }
        $domainSuffix = ltrim($domainSuffix, '.');

        $domain = trim($params['domain'] ?? '');
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = trim(rtrim($domain, '/'));
        if (empty($domain) || strpos($domain, '.') === false) {
            $domain = $slug . '-pbx.' . $domainSuffix;
        }

        // 4. Resolve Tenant Admin Username & Password
        $adminUser = trim($params['username'] ?? '');
        if (empty($adminUser) || $adminUser === 'admin') {
            $adminUser = $slug;
        }

        $adminPass = trim($params['password'] ?? '');
        if (empty($adminPass)) {
            $adminPass = ucfirst($slug) . '@2010';
        }

        // 5. Update WHMCS Database (tblhosting)
        Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->update([
                'domain' => $domain,
                'username' => $adminUser,
                'password' => whiteinfopbx_EncryptPassword($adminPass),
            ]);

        // 6. Resolve Extension Count & DID
        $extCount = whiteinfopbx_ResolveExtensionCount($params);
        $did = whiteinfopbx_ResolveDID($params);

        // 7. Call PBX API
        $extra = [
            'domain' => $domain,
            'company_name' => $displayName,
            'admin_username' => $adminUser,
            'admin_password' => $adminPass,
            'ext_count' => $extCount,
            'did' => $did,
        ];

        $res = whiteinfopbx_ApiCall($params, 'create', $extra);

        if (isset($res['status']) && $res['status'] === 'success') {
            // Optional: Cloudflare DNS
            $targetIp = !empty($params['serverip']) ? $params['serverip'] : ($res['server_ip'] ?? '165.99.219.124');
            $autoDns = !empty($params['configoption6']) ? ($params['configoption6'] == 'on' || $params['configoption6'] == 'yes') : false;
            if ($autoDns && !empty($targetIp)) {
                whiteinfopbx_EnsureCloudflareDns($domain, $targetIp, $params);
            }

            $serverLabel = !empty($params['serverhostname']) ? $params['serverhostname'] : (!empty($params['serverip']) ? $params['serverip'] : 'pbx1.whiteinfobd.com');

            // Append detailed provisioning notes
            $note = "=== WHITEINFO PBX PROVISIONING DETAILS ===
";
            $note .= "PBX Server Node: " . $serverLabel . " (" . $targetIp . ")
";
            $note .= "Tenant Subdomain: " . $res['domain'] . "
";
            $note .= "Tenant Web Portal: https://" . $res['domain'] . "
";
            $note .= "Node Central Portal: https://" . $serverLabel . "
";
            $note .= "Tenant Admin User: " . $adminUser . "
";
            $note .= "Tenant Admin Pass: " . $adminPass . "
";
            $note .= "SIP Server: " . $serverLabel . ":5060

";
            $note .= "PACKAGE EXTENSIONS:
";
            if (!empty($res['package_extensions'])) {
                foreach ($res['package_extensions'] as $pe) {
                    $note .= "  • Ext " . $pe['extension'] . " (Pass: " . $pe['password'] . ")
";
                }
            }
            if (!empty($res['free_extension_999'])) {
                $note .= "
FREE UNBILLED SUPPORT EXTENSION:
";
                $note .= "  • Ext " . $res['free_extension_999']['extension'] . " (Pass: " . $res['free_extension_999']['password'] . ") [Always Active]
";
            }

            $existingNotes = Capsule::table('tblhosting')->where('id', $serviceId)->value('notes');
            Capsule::table('tblhosting')->where('id', $serviceId)->update([
                'notes' => trim($existingNotes . "

" . $note)
            ]);

            return 'success';
        } else {
            return $res['message'] ?? 'PBX creation failed';
        }
    } catch (\Throwable $e) {
        return 'Exception: ' . $e->getMessage();
    }
}

/**
 * Service Suspension on Non-Payment:
 * Disables package extensions, Extension 999 remains ACTIVE!
 */
function whiteinfopbx_SuspendAccount($params) {
    try {
        $res = whiteinfopbx_ApiCall($params, 'suspend');
        if (isset($res['status']) && $res['status'] === 'success') {
            $suspendedNote = "[PBX Suspended - " . date('Y-m-d H:i:s') . "] Package extensions suspended due to overdue invoice. Free Support Ext 999 remains ACTIVE.";
            $existingNotes = Capsule::table('tblhosting')->where('id', $params['serviceid'])->value('notes');
            Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
                'notes' => trim($existingNotes . "

" . $suspendedNote)
            ]);
            return 'success';
        }
        return $res['message'] ?? 'PBX suspension failed';
    } catch (\Throwable $e) {
        return 'Exception: ' . $e->getMessage();
    }
}

/**
 * Service Unsuspension on Payment:
 * Re-enables all package extensions and services!
 */
function whiteinfopbx_UnsuspendAccount($params) {
    try {
        $res = whiteinfopbx_ApiCall($params, 'unsuspend');
        if (isset($res['status']) && $res['status'] === 'success') {
            $activeNote = "[PBX Reactivated - " . date('Y-m-d H:i:s') . "] Payment received. All package extensions restored and reactivated.";
            $existingNotes = Capsule::table('tblhosting')->where('id', $params['serviceid'])->value('notes');
            Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
                'notes' => trim($existingNotes . "

" . $activeNote)
            ]);
            return 'success';
        }
        return $res['message'] ?? 'PBX unsuspension failed';
    } catch (\Throwable $e) {
        return 'Exception: ' . $e->getMessage();
    }
}

/**
 * Service Termination:
 * Strictly enforces "terminet hobe na" rule!
 * Soft-locks extensions; domain, recordings, and CDRs are 100% PRESERVED.
 */
function whiteinfopbx_TerminateAccount($params) {
    try {
        $res = whiteinfopbx_ApiCall($params, 'terminate');
        if (isset($res['status']) && $res['status'] === 'success') {
            $termNote = "[PBX Soft-Terminated - " . date('Y-m-d H:i:s') . "] 'terminet hobe na' policy enforced: extensions locked, CDRs and call recordings preserved.";
            $existingNotes = Capsule::table('tblhosting')->where('id', $params['serviceid'])->value('notes');
            Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
                'notes' => trim($existingNotes . "

" . $termNote)
            ]);
            return 'success';
        }
        return $res['message'] ?? 'PBX termination soft-lock failed';
    } catch (\Throwable $e) {
        return 'Exception: ' . $e->getMessage();
    }
}

/**
 * Service Package Upgrade/Downgrade:
 * Adjusts extension count automatically on the assigned PBX server node.
 */
function whiteinfopbx_ChangePackage($params) {
    try {
        $extCount = whiteinfopbx_ResolveExtensionCount($params);
        $res = whiteinfopbx_ApiCall($params, 'change_package', ['ext_count' => $extCount]);

        if (isset($res['status']) && $res['status'] === 'success') {
            if (!empty($res['created_extensions'])) {
                $note = "=== PACKAGE UPGRADE (" . date('Y-m-d H:i:s') . ") ===
";
                $note .= "Total Extensions: " . $extCount . "
";
                $note .= "Newly Added Extensions:
";
                foreach ($res['created_extensions'] as $ce) {
                    $note .= "  • Ext " . $ce['extension'] . " (Pass: " . $ce['password'] . ")
";
                }
                $existingNotes = Capsule::table('tblhosting')->where('id', $params['serviceid'])->value('notes');
                Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
                    'notes' => trim($existingNotes . "

" . $note)
                ]);
            }
            return 'success';
        }
        return $res['message'] ?? 'Change package failed on PBX';
    } catch (\Throwable $e) {
        return 'Exception: ' . $e->getMessage();
    }
}

/**
 * Admin Area Custom Buttons
 */
function whiteinfopbx_AdminCustomButtonArray() {
    return [
        'Sync Status & Extensions' => 'SyncStatus',
        'Test PBX Connection' => 'TestApiConnection',
        'Unblock Client IP' => 'UnblockClientIp',
    ];
}

/**
 * Button Action: Sync Status & Extensions
 */
function whiteinfopbx_SyncStatus($params) {
    try {
        $res = whiteinfopbx_ApiCall($params, 'status');
        if (isset($res['status']) && $res['status'] === 'success') {
            $serverHost = !empty($params['serverhostname']) ? $params['serverhostname'] : 'pbx1.whiteinfobd.com';
            $info = "[PBX Status Synced " . date('Y-m-d H:i') . "]
";
            $info .= "Node: " . $serverHost . " | Domain: " . $res['domain'] . "
";
            $info .= "Active Extensions: " . ($res['extension_count'] ?? count($res['extensions'] ?? [])) . "
";
            $info .= "30-Day Recordings: " . ($res['recordings_count_30d'] ?? 0) . "
";

            if (!empty($res['extensions'])) {
                $info .= "Extensions List:
";
                foreach ($res['extensions'] as $ext) {
                    $info .= "  • Ext " . $ext['extension'] . " (Pass: " . ($ext['password'] ?? '***') . ", " . (!empty($ext['enabled']) ? 'Active' : 'Disabled') . ")
";
                }
            }

            $existingNotes = Capsule::table('tblhosting')->where('id', $params['serviceid'])->value('notes');
            Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
                'notes' => trim($existingNotes . "

" . $info)
            ]);

            return 'success';
        }
        return $res['message'] ?? 'Failed to query PBX status';
    } catch (\Throwable $e) {
        return 'Exception: ' . $e->getMessage();
    }
}

/**
 * Button Action: Test API Connection from Service Details
 */
function whiteinfopbx_TestApiConnection($params) {
    try {
        $res = whiteinfopbx_ApiCall($params, 'ping');
        if (isset($res['status']) && $res['status'] === 'success') {
            return 'success';
        }
        return $res['message'] ?? 'PBX API connection test failed';
    } catch (\Throwable $e) {
        return 'Exception: ' . $e->getMessage();
    }
}

/**
 * Button Action: Unblock Client IP
 */
function whiteinfopbx_UnblockClientIp($params) {
    try {
        $clientIp = trim($params['clientsdetails']['ip'] ?? '');
        if (empty($clientIp) && !empty($params['serviceid'])) {
            $clientIp = Capsule::table('tblhosting')->where('id', $params['serviceid'])->value('ip');
        }
        if (empty($clientIp) || !filter_var($clientIp, FILTER_VALIDATE_IP)) {
            return 'No valid IP address registered on this client service to unblock.';
        }
        $res = whiteinfopbx_ApiCall($params, 'unblock_ip', ['ip' => $clientIp]);
        if (isset($res['status']) && $res['status'] === 'success') {
            return 'success';
        }
        return $res['message'] ?? 'Failed to unblock client IP';
    } catch (\Throwable $e) {
        return 'Exception: ' . $e->getMessage();
    }
}

/**
 * WHMCS Native Server Connection Test (System Settings -> Servers -> Test Connection)
 */
function whiteinfopbx_TestConnection($params) {
    try {
        $res = whiteinfopbx_ApiCall($params, 'ping');
        if (isset($res['status']) && $res['status'] === 'success') {
            return ['success' => true];
        }
        return ['error' => $res['message'] ?? 'PBX API connection test failed'];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * WHMCS Native Service Single Sign-On (SSO)
 */
function whiteinfopbx_ServiceSingleSignOn($params) {
    try {
        $res = whiteinfopbx_ApiCall($params, 'sso');
        if (isset($res['status']) && $res['status'] === 'success' && !empty($res['sso_url'])) {
            return [
                'success' => true,
                'redirectTo' => $res['sso_url'],
            ];
        }
        return [
            'success' => false,
            'errorMsg' => $res['message'] ?? 'Failed to generate SSO token',
        ];
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'errorMsg' => $e->getMessage(),
        ];
    }
}

/**
 * WHMCS Native Admin Single Sign-On
 */
function whiteinfopbx_AdminSingleSignOn($params) {
    return whiteinfopbx_ServiceSingleSignOn($params);
}

/**
 * Legacy Login Link (for older WHMCS themes)
 */
function whiteinfopbx_LoginLink($params) {
    if (!empty($params["domain"])) {
        return "<a href=\"https://" . htmlspecialchars($params["domain"]) . "/login.php\" target=\"_blank\" class=\"btn btn-default btn-sm\"><i class=\"fa fa-external-link\"></i> PBX Portal</a>";
    }
    return "";
}

/**
 * Client Area Dashboard (Multi-Server Dynamic)
 */
function whiteinfopbx_ClientArea($params) {
    $statusData = whiteinfopbx_ApiCall($params, 'status');
    $ssoData = whiteinfopbx_ApiCall($params, 'sso');
    $domain = !empty($statusData['domain']) ? $statusData['domain'] : $params['domain'];
    $ssoUrl = !empty($ssoData['sso_url']) ? $ssoData['sso_url'] : ("https://" . $domain . "/login.php");
    $isSuspended = ($params['status'] === 'Suspended');
    $username = htmlspecialchars($params['username'] ?? '');
    $password = htmlspecialchars($params['password'] ?? '');
    $extensions = !empty($statusData['extensions']) ? $statusData['extensions'] : [];
    $recCount = isset($statusData['recordings_count_30d']) ? intval($statusData['recordings_count_30d']) : 0;
    
    $serverHost = !empty($params['serverhostname']) ? $params['serverhostname'] : (!empty($params['serverip']) ? $params['serverip'] : 'pbx1.whiteinfobd.com');

    // Handle Extension Password Change Form Submission
    $extPassMsgHtml = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['whiteinfopbx_custom_action']) && $_POST['whiteinfopbx_custom_action'] === 'change_extension_password') {
        $targetExt = trim($_POST['extension'] ?? '');
        $newPass = trim($_POST['new_password'] ?? '');
        if (empty($targetExt)) {
            $extPassMsgHtml = '<div style="background:#fef2f2;border:1px solid #f87171;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:14px;"><i class="fa fa-times-circle"></i> Extension number is required.</div>';
        } else {
            $updateRes = whiteinfopbx_ApiCall($params, 'update_extension_password', [
                'extension' => $targetExt,
                'password' => $newPass
            ]);
            if (!empty($updateRes['status']) && $updateRes['status'] === 'success') {
                $effectiveNewPass = htmlspecialchars($updateRes['password'] ?? $newPass);
                $extPassMsgHtml = '<div style="background:#f0fdf4;border:1px solid #4ade80;color:#166534;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:14px;"><i class="fa fa-check-circle" style="color:#16a34a;"></i> Password for Extension <strong>' . htmlspecialchars($targetExt) . '</strong> successfully updated to: <code style="font-family:monospace;font-weight:700;background:#dcfce7;padding:3px 8px;border-radius:4px;color:#166534;">' . $effectiveNewPass . '</code>! Please update your softphone with this new password.</div>';
                $statusData = whiteinfopbx_ApiCall($params, 'status');
                $extensions = !empty($statusData['extensions']) ? $statusData['extensions'] : $extensions;
            } else {
                $errMsg = htmlspecialchars($updateRes['message'] ?? 'Failed to communicate with PBX server');
                $extPassMsgHtml = '<div style="background:#fef2f2;border:1px solid #f87171;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:14px;"><i class="fa fa-exclamation-triangle"></i> Password update failed: ' . $errMsg . '</div>';
            }
        }
    }

    // Detect visitor IP for self-service unblock
    $clientIp = '';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $clientIp = $_SERVER['HTTP_X_REAL_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $fwd = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $clientIp = trim($fwd[0]);
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $clientIp = $_SERVER['REMOTE_ADDR'];
    }
    $clientIp = trim($clientIp);

    // Handle Unblock Form Submission
    $unblockMsgHtml = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['whiteinfopbx_custom_action']) && $_POST['whiteinfopbx_custom_action'] === 'unblock_ip') {
        $targetIp = trim($_POST['ip_address'] ?? '');
        if (empty($targetIp) || !filter_var($targetIp, FILTER_VALIDATE_IP)) {
            $unblockMsgHtml = '<div style="background:#fef2f2;border:1px solid #f87171;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:14px;"><i class="fa fa-times-circle"></i> Please enter a valid IPv4 or IPv6 address.</div>';
        } else {
            $unblockRes = whiteinfopbx_ApiCall($params, 'unblock_ip', ['ip' => $targetIp]);
            if (!empty($unblockRes['status']) && $unblockRes['status'] === 'success') {
                $unblockMsgHtml = '<div style="background:#f0fdf4;border:1px solid #4ade80;color:#166534;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:14px;"><i class="fa fa-check-circle" style="color:#16a34a;"></i> IP <strong>' . htmlspecialchars($targetIp) . '</strong> has been successfully unblocked from FreeSWITCH Event Guard and Server Firewall! You can now register extensions.</div>';
            } else {
                $errMsg = htmlspecialchars($unblockRes['message'] ?? 'Failed to communicate with PBX server');
                $unblockMsgHtml = '<div style="background:#fef2f2;border:1px solid #f87171;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:14px;"><i class="fa fa-exclamation-triangle"></i> Unblock failed: ' . $errMsg . '</div>';
            }
        }
    }

    // Check if detected IP is currently blocked
    $isClientBlocked = false;
    if (!empty($clientIp) && filter_var($clientIp, FILTER_VALIDATE_IP)) {
        $checkRes = whiteinfopbx_ApiCall($params, 'check_ip', ['ip' => $clientIp]);
        $isClientBlocked = !empty($checkRes['is_blocked']);
    }

    $blockedAlertBanner = '';
    if ($isClientBlocked) {
        $blockedAlertBanner = '<div style="background:#fef2f2;border:2px solid #ef4444;border-radius:8px;padding:14px 18px;margin-bottom:20px;color:#991b1b;font-size:14px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <i class="fa fa-ban" style="font-size:24px;color:#dc2626;"></i>
                <div>
                    <h5 style="margin:0 0 4px 0;font-weight:700;color:#b91c1c;">IP Block Detected!</h5>
                    Your current IP (<strong>' . htmlspecialchars($clientIp) . '</strong>) is currently <strong>BLOCKED</strong> by PBX Event Guard due to failed password attempts. Click the unblock button below to restore access immediately.
                </div>
            </div>
        </div>';
    }

    // Build extensions table rows
    $extRows = '';
    foreach ($extensions as $ext) {
        $extNum = htmlspecialchars($ext['extension']);
        $extName = htmlspecialchars($ext['effective_caller_id_name']);
        $extPass = htmlspecialchars($ext['password'] ?? '');
        $enabled = !empty($ext['enabled']);

        $is999 = ($extNum === '999');
        $rowBadge = '';
        if ($is999) {
            $rowBadge = '<span class="label label-primary" style="background:#0284c7;color:#fff;padding:3px 8px;border-radius:4px;font-size:11px;">FREE / SUPPORT (ALWAYS ACTIVE)</span>';
        } elseif ($enabled) {
            $rowBadge = '<span class="label label-success" style="background:#16a34a;color:#fff;padding:3px 8px;border-radius:4px;font-size:11px;">ACTIVE</span>';
        } else {
            $rowBadge = '<span class="label label-danger" style="background:#dc2626;color:#fff;padding:3px 8px;border-radius:4px;font-size:11px;">OFF (OVERDUE)</span>';
        }

        $extRows .= "<tr>
            <td><strong style='font-size:15px;color:#1e293b;'>{$extNum}</strong></td>
            <td>{$extName}</td>
            <td>
                <div style='display:inline-flex;align-items:center;gap:6px;'>
                    <code id='pass_val_{$extNum}' style='font-family:monospace;font-size:13px;background:#f1f5f9;padding:3px 8px;border-radius:4px;color:#0f172a;border:1px solid #e2e8f0;'>{$extPass}</code>
                    <button type='button' onclick='navigator.clipboard.writeText(\"{$extPass}\");alert(\"Password copied to clipboard: {$extPass}\");' title='Copy Password' class='btn btn-xs btn-default' style='background:#fff;border:1px solid #cbd5e1;padding:2px 6px;border-radius:4px;cursor:pointer;'><i class='fa fa-copy'></i></button>
                </div>
            </td>
            <td>{$domain}</td>
            <td>{$rowBadge}</td>
            <td style='text-align:right;'>
                <button type='button' onclick='openExtPassModal(\"{$extNum}\", \"{$extPass}\")' class='btn btn-xs btn-primary' style='background:#2563eb;color:#fff;border:none;padding:5px 10px;border-radius:5px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:5px;cursor:pointer;'>
                    <i class='fa fa-key'></i> Change Password
                </button>
            </td>
        </tr>";
    }

    $bannerHtml = '';
    if ($isSuspended) {
        $bannerHtml = '<div style="background:#fee2e2;border:1px solid #ef4444;border-radius:8px;padding:16px 20px;margin-bottom:20px;color:#991b1b;">
            <h4 style="margin:0 0 8px 0;font-weight:700;"><i class="fa fa-exclamation-triangle"></i> Service Suspended (Invoice Overdue)</h4>
            <p style="margin:0 0 6px 0;">All package extensions have been temporarily disabled due to an overdue invoice. Please settle your invoice to instantly restore your package extensions.</p>
            <p style="margin:0;font-weight:600;"><i class="fa fa-check-circle" style="color:#16a34a;"></i> <strong>Extension 999</strong> remains ACTIVE and fully available for free emergency and support calling!</p>
        </div>';
    }

    $html = <<<HTML
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
    {$extPassMsgHtml}
    {$unblockMsgHtml}
    {$blockedAlertBanner}
    {$bannerHtml}

    <!-- PBX Tenant Overview -->
    <div style="display:flex;justify-content:space-between;align-items:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:20px 24px;margin-bottom:24px;flex-wrap:wrap;gap:15px;">
        <div>
            <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">PBX Tenant Subdomain</div>
            <div style="font-size:22px;font-weight:800;color:#0f172a;margin-top:2px;">
                <a href="https://{$domain}" target="_blank" style="color:#2563eb;text-decoration:none;">{$domain}</a>
            </div>
            <div style="font-size:13px;color:#64748b;margin-top:4px;">
                <i class="fa fa-server"></i> SIP Server: <strong>{$serverHost}:5060</strong> (UDP)
            </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="{$ssoUrl}" target="_blank" class="btn btn-primary" style="background:#2563eb;color:#fff;border-radius:6px;padding:10px 18px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
                <i class="fa fa-sign-in"></i> One-Click PBX Login
            </a>
            <a href="https://{$domain}" target="_blank" class="btn btn-default" style="border-radius:6px;padding:10px 18px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
                <i class="fa fa-external-link"></i> Open Web Portal
            </a>
        </div>
    </div>

    <!-- Web Portal Admin Credentials -->
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:18px 20px;margin-bottom:24px;color:#166534;">
        <h4 style="margin:0 0 12px 0;font-weight:700;color:#166534;"><i class="fa fa-lock"></i> PBX Web Portal Login Credentials (*.whiteinfobd.com)</h4>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;font-size:14px;">
            <div style="background:#fff;padding:12px 16px;border-radius:6px;border:1px solid #dcfce7;">
                <div style="font-size:12px;color:#64748b;font-weight:600;">Web Portal URL</div>
                <div style="margin-top:4px;"><a href="https://{$domain}" target="_blank" style="color:#2563eb;font-weight:700;">https://{$domain}</a></div>
            </div>
            <div style="background:#fff;padding:12px 16px;border-radius:6px;border:1px solid #dcfce7;">
                <div style="font-size:12px;color:#64748b;font-weight:600;">Login Username</div>
                <div style="margin-top:4px;font-family:monospace;font-size:15px;font-weight:700;color:#0f172a;">{$username}</div>
            </div>
            <div style="background:#fff;padding:12px 16px;border-radius:6px;border:1px solid #dcfce7;">
                <div style="font-size:12px;color:#64748b;font-weight:600;">Login Password</div>
                <div style="margin-top:4px;font-family:monospace;font-size:15px;font-weight:700;color:#0f172a;">{$password}</div>
            </div>
            <div style="background:#fff;padding:12px 16px;border-radius:6px;border:1px solid #dcfce7;">
                <div style="font-size:12px;color:#64748b;font-weight:600;">User Role & Permission</div>
                <div style="margin-top:4px;font-weight:700;color:#16a34a;"><i class="fa fa-shield"></i> Tenant Administrator</div>
            </div>
        </div>
    </div>

    <!-- Provisioned Extensions Table -->
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="background:#f1f5f9;padding:14px 20px;font-weight:700;font-size:15px;color:#1e293b;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
            <span><i class="fa fa-phone"></i> Provisioned Extensions</span>
            <span style="font-size:12px;font-weight:600;color:#64748b;">30-Day Call Recordings: <strong>{$recCount} calls stored</strong></span>
        </div>
        <div class="table-responsive" style="margin:0;">
            <table class="table" style="margin-bottom:0;width:100%;">
                <thead>
                    <tr style="background:#f8fafc;font-size:12px;text-transform:uppercase;color:#64748b;">
                        <th>Extension</th>
                        <th>Caller ID / Name</th>
                        <th>SIP Password</th>
                        <th>SIP Domain Context</th>
                        <th>Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {$extRows}
                </tbody>
            </table>
        </div>
    </div>

    <!-- Self-Service IP Unban Widget -->
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:24px;">
        <h4 style="margin:0 0 8px 0;font-weight:700;color:#0f172a;"><i class="fa fa-shield" style="color:#2563eb;"></i> Self-Service IP Unban Tool (PBX Event Guard & Firewall)</h4>
        <p style="margin:0 0 16px 0;font-size:13px;color:#64748b;">
            If you or your staff accidentally entered the wrong softphone password multiple times, the server firewall may temporarily block your office IP. Use this tool to instantly unban yourself.
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;">
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">
                <div style="font-size:14px;font-weight:700;color:#1e293b;margin-bottom:6px;">
                    <i class="fa fa-wifi" style="color:#16a34a;"></i> Detected Current IP
                </div>
                <div style="font-family:monospace;font-size:16px;font-weight:700;color:#0f172a;margin-bottom:12px;">
                    {$clientIp}
                </div>
                <form method="post" action="" style="margin:0;">
                    <input type="hidden" name="whiteinfopbx_custom_action" value="unblock_ip">
                    <input type="hidden" name="ip_address" value="{$clientIp}">
                    <button type="submit" class="btn btn-default" style="width:100%;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                        <i class="fa fa-unlock"></i> Unblock My Current IP
                    </button>
                </form>
            </div>
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">
                <div style="font-size:14px;font-weight:700;color:#1e293b;margin-bottom:6px;">
                    <i class="fa fa-laptop" style="color:#2563eb;"></i> Unblock Custom / Branch IP
                </div>
                <div style="font-size:12px;color:#64748b;margin-bottom:12px;">
                    Enter any branch office or remote worker IP address to unblock:
                </div>
                <form method="post" action="" style="margin:0;display:flex;gap:8px;flex-wrap:wrap;">
                    <input type="hidden" name="whiteinfopbx_custom_action" value="unblock_ip">
                    <input type="text" name="ip_address" placeholder="e.g. 103.15.140.151" class="form-control" style="flex:1;min-width:160px;font-family:monospace;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;" required>
                    <button type="submit" class="btn btn-primary" style="background:#2563eb;border:none;padding:10px 16px;font-weight:600;border-radius:6px;display:inline-flex;align-items:center;gap:6px;color:#fff;">
                        <i class="fa fa-unlock"></i> Unblock
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- MicroSIP Setup Guide -->
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:20px;margin-bottom:24px;">
        <h4 style="margin:0 0 12px 0;font-weight:700;color:#1e40af;"><i class="fa fa-laptop"></i> MicroSIP / Softphone Quick Setup Guide</h4>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;font-size:13px;color:#1e3a8a;">
            <div><strong>SIP Server / Proxy:</strong><br><span style="font-family:monospace;font-size:14px;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #dbeafe;">{$domain}</span></div>
            <div><strong>SIP Port:</strong><br><span style="font-family:monospace;font-size:14px;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #dbeafe;">5060 (UDP)</span></div>
            <div><strong>Username:</strong><br><span style="font-family:monospace;font-size:14px;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #dbeafe;">[Extension Number]</span></div>
            <div><strong>Domain / Realm:</strong><br><span style="font-family:monospace;font-size:14px;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #dbeafe;">{$domain}</span></div>
        </div>
        <p style="margin:12px 0 0 0;font-size:12px;color:#3b82f6;">
            <i class="fa fa-info-circle"></i> Password for each extension is shown above in the Provisioned Extensions table or can be updated using the <strong>Change Password</strong> button.
        </p>
    </div>

    <!-- 30-Day Recording Policy -->
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;color:#92400e;font-size:13px;margin-bottom:20px;">
        <i class="fa fa-clock-o" style="font-weight:700;"></i> <strong>Call Recordings Retention Policy:</strong> All call recordings are securely stored for <strong>30 days</strong> and automatically cleaned up thereafter. You can download important recordings anytime from your PBX portal.
    </div>

    <!-- Extension Password Change Modal -->
    <div id="extPassModal" style="display:none;position:fixed;z-index:99999;left:0;top:0;width:100%;height:100%;overflow:auto;background-color:rgba(15,23,42,0.6);backdrop-filter:blur(2px);">
        <div style="background-color:#ffffff;margin:10% auto;padding:24px 28px;border:1px solid #cbd5e1;border-radius:12px;width:90%;max-width:480px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 10px 10px -5px rgba(0,0,0,0.04);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h4 style="margin:0;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;">
                    <i class="fa fa-key" style="color:#2563eb;"></i> Change Extension Password
                </h4>
                <button type="button" onclick="closeExtPassModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:#64748b;line-height:1;">&times;</button>
            </div>
            
            <form method="post" action="" style="margin:0;">
                <input type="hidden" name="whiteinfopbx_custom_action" value="change_extension_password">
                <input type="hidden" id="modalExtInput" name="extension" value="">
                
                <div style="margin-bottom:14px;">
                    <label style="font-size:13px;font-weight:600;color:#334155;margin-bottom:4px;display:block;">Extension Number</label>
                    <div id="modalExtDisplay" style="font-size:16px;font-weight:700;color:#1e293b;background:#f8fafc;padding:8px 12px;border-radius:6px;border:1px solid #e2e8f0;"></div>
                </div>

                <div style="margin-bottom:18px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                        <label style="font-size:13px;font-weight:600;color:#334155;margin:0;">New SIP Password</label>
                        <button type="button" onclick="generateRandomPass()" style="background:none;border:none;color:#2563eb;font-size:12px;font-weight:600;cursor:pointer;padding:0;">
                            <i class="fa fa-random"></i> Generate Strong Password
                        </button>
                    </div>
                    <input type="text" id="modalNewPassInput" name="new_password" class="form-control" style="font-family:monospace;font-size:14px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;width:100%;box-sizing:border-box;" required minlength="6" maxlength="64">
                    <small style="color:#64748b;font-size:12px;margin-top:6px;display:block;">Minimum 6 characters. Click "Generate" to create a secure 16-character password.</small>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:10px;">
                    <button type="button" onclick="closeExtPassModal()" class="btn btn-default" style="padding:8px 16px;border-radius:6px;font-weight:600;">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background:#2563eb;border:none;padding:8px 18px;border-radius:6px;font-weight:600;color:#fff;">
                        <i class="fa fa-save"></i> Save New Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openExtPassModal(ext, currentPass) {
        document.getElementById('modalExtInput').value = ext;
        document.getElementById('modalExtDisplay').textContent = 'Extension ' + ext;
        document.getElementById('modalNewPassInput').value = currentPass || '';
        document.getElementById('extPassModal').style.display = 'block';
    }
    function closeExtPassModal() {
        document.getElementById('extPassModal').style.display = 'none';
    }
    function generateRandomPass() {
        const chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
        let pass = '';
        for (let i = 0; i < 16; i++) {
            pass += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.getElementById('modalNewPassInput').value = pass;
    }
    window.onclick = function(event) {
        const modal = document.getElementById('extPassModal');
        if (event.target == modal) {
            closeExtPassModal();
        }
    }
    </script>
</div>
HTML;

    return $html;
}

/**
 * Admin Area Service Management Fields (View extensions & change passwords from admin)
 */
function whiteinfopbx_AdminServicesTabFields($params) {
    $statusData = whiteinfopbx_ApiCall($params, 'status');
    $extensions = !empty($statusData['extensions']) ? $statusData['extensions'] : [];

    $rows = '';
    foreach ($extensions as $ext) {
        $extNum = htmlspecialchars($ext['extension']);
        $extPass = htmlspecialchars($ext['password'] ?? '');
        $extName = htmlspecialchars($ext['effective_caller_id_name'] ?? '');
        $enabled = !empty($ext['enabled']) ? '<span class="label label-success" style="background:#16a34a;color:#fff;padding:2px 6px;border-radius:3px;">Active</span>' : '<span class="label label-danger" style="background:#dc2626;color:#fff;padding:2px 6px;border-radius:3px;">Disabled</span>';

        $rows .= "<tr>
            <td><strong>{$extNum}</strong></td>
            <td>{$extName}</td>
            <td><code>{$extPass}</code></td>
            <td>{$enabled}</td>
            <td>
                <input type='text' name='whiteinfopbx_new_pass_{$extNum}' placeholder='New password or leave blank' class='form-control input-sm' style='width:200px;display:inline-block;font-family:monospace;'>
            </td>
        </tr>";
    }

    $html = "<table class='table table-condensed table-striped' style='margin:0;'>
        <thead>
            <tr>
                <th>Extension</th>
                <th>Caller ID Name</th>
                <th>Current SIP Password</th>
                <th>Status</th>
                <th>Set New Password</th>
            </tr>
        </thead>
        <tbody>
            {$rows}
        </tbody>
    </table>
    <small class='text-muted' style='margin-top:5px;display:block;'>Enter a new password above and click 'Save Changes' to update the SIP password on the PBX server.</small>";

    return [
        'PBX Extensions & Passwords' => $html,
    ];
}

/**
 * Save Admin Area Service Management Fields
 */
function whiteinfopbx_AdminServicesTabFieldsSave($params) {
    foreach ($_POST as $key => $val) {
        if (strpos($key, 'whiteinfopbx_new_pass_') === 0 && !empty($val)) {
            $extNum = substr($key, strlen('whiteinfopbx_new_pass_'));
            $newPass = trim($val);
            if (!empty($extNum) && !empty($newPass)) {
                whiteinfopbx_ApiCall($params, 'update_extension_password', [
                    'extension' => $extNum,
                    'password' => $newPass
                ]);
            }
        }
    }
}
