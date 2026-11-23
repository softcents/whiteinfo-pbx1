<?php
/**
 * WhiteInfo PBX Billing Integration API for WHMCS
 * 
 * Features:
 * - Automated Package-wise Extension Provisioning (e.g. 3, 5, 10 extensions)
 * - Automatic Company / Name based Subdomain Generation (e.g. apexlimited-pbx.whiteinfobd.com)
 * - Tenant Admin Web Login (e.g. username: apexlimited, pass: Apexlimited@2010)
 * - Admin permissions assigned to tenant user (*.whiteinfobd.com permission)
 * - Automatic Extension 999 creation (FREE / UNBILLED / ALWAYS ACTIVE)
 * - Non-Payment Suspension: Package extensions disabled, Extension 999 REMAINS ACTIVE
 * - Reactivation on Payment: Package extensions restored
 * - Strictly Enforced No-Termination Policy ("terminet hobe na")
 * - FreeSWITCH Sofia Registration Flushing & Cache Clearing
 * - 30-Day Call Recording Retention Support
 */

ini_set("display_errors", 0);
ini_set("log_errors", 1);
error_reporting(E_ALL);

function log_billing_api($code, $payload) {
    $log_dir = "/var/log/fusionpbx";
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0775, true);
        @chown($log_dir, "www-data");
    }
    $log_file = $log_dir . "/billing_api.log";
    $ip = $_SERVER["HTTP_CF_CONNECTING_IP"] ?? $_SERVER["HTTP_X_REAL_IP"] ?? $_SERVER["REMOTE_ADDR"] ?? "unknown";
    $action_name = $GLOBALS["action"] ?? "unknown";
    $domain_val = $GLOBALS["domain_name"] ?? ($GLOBALS["raw_domain"] ?? "");
    $entry = sprintf("[%s] [%s] [%s] action=%s domain=%s status=%d payload=%s\n",
        date("Y-m-d H:i:s"),
        $ip,
        $_SERVER["REQUEST_METHOD"] ?? "CLI",
        $action_name,
        $domain_val,
        $code,
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );
    @file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
}

function send_json($code, $payload) {
    log_billing_api($code, $payload);
    http_response_code($code);
    header("Content-Type: application/json; charset=UTF-8");
    header("X-Powered-By: WhiteInfo-PBX-Billing-API");
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function generate_secure_password($length = 16) {
    $chars = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#%*=";
    $pass = "";
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $pass .= $chars[random_int(0, $max)];
    }
    return $pass;
}

// Load API Key
$key_file = "/etc/fusionpbx/billing_api.key";
if (!file_exists($key_file)) {
    send_json(500, ["status" => "error", "message" => "PBX Billing API key configuration file missing"]);
}
$valid_api_key = trim(file_get_contents($key_file));

// Authenticate caller
$incoming_key = "";
if (!empty($_SERVER["HTTP_X_API_KEY"])) {
    $incoming_key = trim($_SERVER["HTTP_X_API_KEY"]);
} elseif (!empty($_SERVER["HTTP_AUTHORIZATION"])) {
    if (preg_match("/Bearer\s+(.*)$/i", $_SERVER["HTTP_AUTHORIZATION"], $matches)) {
        $incoming_key = trim($matches[1]);
    }
} elseif (!empty($_REQUEST["api_key"])) {
    $incoming_key = trim($_REQUEST["api_key"]);
}

if (empty($incoming_key) || !hash_equals($valid_api_key, $incoming_key)) {
    send_json(401, ["status" => "error", "message" => "Unauthorized: Invalid or missing API Key"]);
}

// Parse request payload
$raw_input = file_get_contents("php://input");
$post_data = json_decode($raw_input, true);
if (!is_array($post_data)) {
    $post_data = $_POST;
}

$action = strtolower(trim($post_data["action"] ?? $_GET["action"] ?? "ping"));

// Ping / Health check
if ($action === "ping") {
    send_json(200, [
        "status" => "success",
        "service" => "WhiteInfo PBX Billing Integration API",
        "version" => "1.1",
        "pbx_host" => (!empty($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : "pbx1.whiteinfobd.com"),
        "sip_port" => 5060,
        "time" => date("Y-m-d H:i:s")
    ]);
}

// Load FusionPBX framework
require_once __DIR__ . "/resources/require.php";
$database = new database;

// Validate or auto-resolve domain parameter
$raw_domain = strtolower(trim($post_data["domain"] ?? $_GET["domain"] ?? ""));
$raw_domain = preg_replace('#^https?://#i', "", $raw_domain);
$raw_domain = trim(rtrim($raw_domain, "/"));
if (strpos($raw_domain, ":") !== false) {
    $raw_domain = explode(":", $raw_domain)[0];
}
$company_name = trim($post_data["company_name"] ?? "");
if (empty($company_name)) {
    $company_name = trim($post_data["customer_name"] ?? $post_data["name"] ?? "");
}

// If domain is empty but company_name exists, auto-derive: {slug}-pbx.whiteinfobd.com
if (empty($raw_domain) && !empty($company_name)) {
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $company_name));
    if (!empty($slug)) {
        $raw_domain = $slug . "-pbx.whiteinfobd.com";
    }
} elseif (!empty($raw_domain) && strpos($raw_domain, ".") === false) {
    $raw_domain = $raw_domain . "-pbx.whiteinfobd.com";
}

$domain_name = $raw_domain;
if (empty($domain_name) && in_array($action, ["create", "suspend", "unsuspend", "terminate", "status", "list_extensions", "change_package", "sso", "create_session", "update_extension_password", "change_extension_password"])) {
    send_json(400, ["status" => "error", "message" => "Domain or company_name parameter is required"]);
}

// Action: UNBLOCK_IP (Unblock IP from Fail2ban, iptables firewall, and FusionPBX Event Guard)
if ($action === "unblock_ip" || $action === "unban_ip") {
    $ip = trim($post_data["ip"] ?? $_REQUEST["ip"] ?? "");
    if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
        send_json(400, ["status" => "error", "message" => "Valid IPv4 or IPv6 address is required"]);
    }
    
    $results = [];
    
    // 1. Unban from Fail2ban (all active jails)
    $f2b_out = [];
    $f2b_code = 0;
    exec("sudo /usr/bin/fail2ban-client unban " . escapeshellarg($ip) . " 2>&1", $f2b_out, $f2b_code);
    $results["fail2ban"] = ($f2b_code === 0);
    $results["fail2ban_output"] = implode(" ", $f2b_out);
    
    // 2. Unban from iptables (Event Guard chains and f2b chains)
    $chains = ["sip-auth-ip", "sip-auth-fail", "f2b-nginx-404", "f2b-sshd"];
    $iptables_removed = 0;
    foreach ($chains as $chain) {
        for ($i = 0; $i < 20; $i++) {
            unset($out, $ret);
            $check_cmd = "sudo /usr/sbin/iptables -C " . escapeshellarg($chain) . " -s " . escapeshellarg($ip) . " -j DROP 2>/dev/null";
            exec($check_cmd, $out, $ret);
            if ($ret === 0) {
                exec("sudo /usr/sbin/iptables -D " . escapeshellarg($chain) . " -s " . escapeshellarg($ip) . " -j DROP 2>/dev/null");
                $iptables_removed++;
            } else {
                unset($out2, $ret2);
                $check_cmd2 = "sudo /usr/sbin/iptables -C " . escapeshellarg($chain) . " -s " . escapeshellarg($ip) . " -j REJECT 2>/dev/null";
                exec($check_cmd2, $out2, $ret2);
                if ($ret2 === 0) {
                    exec("sudo /usr/sbin/iptables -D " . escapeshellarg($chain) . " -s " . escapeshellarg($ip) . " -j REJECT 2>/dev/null");
                    $iptables_removed++;
                } else {
                    break;
                }
            }
        }
    }
    $results["iptables_rules_removed"] = $iptables_removed;
    
    // 3. FusionPBX Event Guard unblock via FreeSWITCH Event Socket
    $esl_unblocked = false;
    if (class_exists("event_socket")) {
        $esl = event_socket::create();
        if ($esl && $esl->is_connected()) {
            $cmd = "sendevent CUSTOM\nEvent-Name: CUSTOM\nEvent-Subclass: event_guard:unblock\nUnblock-Address: " . $ip . "\n";
            $switch_res = $esl->request($cmd);
            $esl_unblocked = true;
        }
    }
    $results["event_guard_socket"] = $esl_unblocked;
    
    // 4. Update/Delete from v_event_guard_logs
    $eg_updated = $database->execute("UPDATE v_event_guard_logs SET log_status = 'unblocked', update_date = now() WHERE ip_address = :ip", [":ip" => $ip]);
    $results["event_guard_logs_updated"] = $eg_updated;
    
    send_json(200, [
        "status" => "success",
        "ip" => $ip,
        "message" => "IP " . $ip . " has been successfully unblocked from Server Fail2ban, iptables firewall, and FusionPBX Event Guard",
        "details" => $results
    ]);
}

// Action: CHECK_IP (Check if IP is blocked anywhere)
if ($action === "check_ip" || $action === "check_block") {
    $ip = trim($post_data["ip"] ?? $_REQUEST["ip"] ?? "");
    if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
        send_json(400, ["status" => "error", "message" => "Valid IPv4 or IPv6 address is required"]);
    }
    
    $is_blocked = false;
    $blocked_reasons = [];
    
    // Check fail2ban
    exec("sudo /usr/bin/fail2ban-client banned 2>/dev/null", $banned_raw);
    $banned_str = implode("", $banned_raw);
    if (strpos($banned_str, $ip) !== false) {
        $is_blocked = true;
        $blocked_reasons[] = "Fail2ban Server Jail";
    }
    
    // Check iptables
    $check_out = [];
    exec("sudo /usr/sbin/iptables -S | grep -F " . escapeshellarg($ip), $check_out);
    if (!empty($check_out)) {
        $is_blocked = true;
        $blocked_reasons[] = "iptables Firewall Filter (sip-auth-ip / f2b)";
    }
    
    // Check Event Guard Logs
    $sql = "SELECT log_status, filter FROM v_event_guard_logs WHERE ip_address = :ip AND log_status = 'blocked' ORDER BY log_date DESC LIMIT 1";
    $eg_row = $database->select($sql, [":ip" => $ip], "row");
    if (!empty($eg_row)) {
        $is_blocked = true;
        $blocked_reasons[] = "FusionPBX Event Guard (" . ($eg_row["filter"] ?? "blocked") . ")";
    }
    
    send_json(200, [
        "status" => "success",
        "ip" => $ip,
        "is_blocked" => $is_blocked,
        "reasons" => $blocked_reasons
    ]);
}

// Action: UPDATE_EXTENSION_PASSWORD (Update / reset SIP extension password)
if ($action === "update_extension_password" || $action === "change_extension_password") {
    $extension = trim($post_data["extension"] ?? $_REQUEST["extension"] ?? "");
    $new_password = trim($post_data["password"] ?? $_REQUEST["password"] ?? "");
    
    if (empty($extension)) {
        send_json(400, ["status" => "error", "message" => "Extension number is required"]);
    }
    
    // Find domain
    $domain_row = $database->select("SELECT domain_uuid, domain_name FROM v_domains WHERE lower(domain_name) = lower(:d) LIMIT 1", [":d" => $domain_name], "row");
    if (empty($domain_row)) {
        send_json(404, ["status" => "error", "message" => "Domain not found: " . htmlspecialchars($domain_name)]);
    }
    $domain_uuid = $domain_row["domain_uuid"];
    $domain_name = $domain_row["domain_name"];
    
    // Find extension
    $ext_row = $database->select("SELECT extension_uuid, extension, user_context, password, enabled FROM v_extensions WHERE domain_uuid = :domain_uuid AND extension = :ext LIMIT 1", [
        ":domain_uuid" => $domain_uuid,
        ":ext" => $extension
    ], "row");
    
    if (empty($ext_row)) {
        send_json(404, ["status" => "error", "message" => "Extension " . htmlspecialchars($extension) . " not found in domain " . htmlspecialchars($domain_name)]);
    }
    
    // If password is empty or \x27generate\x27, generate a secure random password
    if (empty($new_password) || strtolower($new_password) === "generate" || strtolower($new_password) === "random") {
        $new_password = generate_secure_password(16);
    } else {
        if (strlen($new_password) < 6) {
            send_json(400, ["status" => "error", "message" => "Password must be at least 6 characters long"]);
        }
        if (strlen($new_password) > 64) {
            send_json(400, ["status" => "error", "message" => "Password must not exceed 64 characters"]);
        }
    }
    
    // Update password in database
    $updated = $database->execute("UPDATE v_extensions SET password = :password, update_date = now() WHERE domain_uuid = :domain_uuid AND extension = :ext", [
        ":password" => $new_password,
        ":domain_uuid" => $domain_uuid,
        ":ext" => $extension
    ]);
    
    // Clear FusionPBX cache for this extension
    $user_context = !empty($ext_row["user_context"]) ? $ext_row["user_context"] : $domain_name;
    if (class_exists("cache")) {
        $cache = new cache;
        $cache->delete("directory:" . $extension . "@" . $user_context);
    }
    @unlink("/var/cache/fusionpbx/directory." . $extension . "@" . $user_context);
    
    // Reload FreeSWITCH XML via Event Socket
    $esl_reloaded = false;
    if (class_exists("event_socket")) {
        $esl = event_socket::create();
        if ($esl && $esl->is_connected()) {
            $esl->request("api reloadxml");
            $esl_reloaded = true;
        }
    }
    
    send_json(200, [
        "status" => "success",
        "message" => "Password for extension " . $extension . " successfully updated",
        "domain" => $domain_name,
        "extension" => $extension,
        "password" => $new_password,
        "esl_reloaded" => $esl_reloaded
    ]);
}

// Action: STATUS
if ($action === "status" || $action === "get_account") {
    $sql = "SELECT domain_uuid, domain_name, domain_enabled, domain_description, insert_date FROM v_domains WHERE lower(domain_name) = :domain_name";
    $params = ["domain_name" => $domain_name];
    $domain_row = $database->select($sql, $params, "row");
    if (empty($domain_row)) {
        send_json(404, ["status" => "error", "message" => "Domain not found on PBX"]);
    }
    
    $domain_uuid = $domain_row["domain_uuid"];
    
    // Get extensions
    $sql_ext = "SELECT extension, password, user_context, effective_caller_id_name, enabled, description FROM v_extensions WHERE domain_uuid = :domain_uuid ORDER BY extension ASC";
    $exts = $database->select($sql_ext, ["domain_uuid" => $domain_uuid], "all");
    
    // Check call recordings in last 30 days
    $recordings_dir = "/var/lib/freeswitch/recordings/" . $domain_name . "/archive";
    $rec_count = 0;
    if (is_dir($recordings_dir)) {
        $rec_count = intval(exec("find " . escapeshellarg($recordings_dir) . " -type f \( -name '*.wav' -o -name '*.mp3' \) 2>/dev/null | wc -l"));
    }
    
    send_json(200, [
        "status" => "success",
        "domain" => $domain_name,
        "domain_uuid" => $domain_uuid,
        "domain_enabled" => $domain_row["domain_enabled"],
        "description" => $domain_row["domain_description"],
        "extension_count" => count($exts),
        "extensions" => $exts,
        "recordings_count_30d" => $rec_count
    ]);
}

// Action: CREATE (Package-wise Extension Provisioning)
if ($action === "create") {
    if (empty($company_name)) {
        $company_name = $domain_name;
    }
    
    $ext_count = intval($post_data["ext_count"] ?? 3);
    if ($ext_count < 1) $ext_count = 1;
    if ($ext_count > 100) $ext_count = 100;
    
    $did = trim($post_data["did"] ?? "");
    
    // Derive slug for username and default password
    // e.g. "apexlimited-pbx.whiteinfobd.com" -> slug: "apexlimited"
    $slug = "";
    if (preg_match('/^([a-z0-9]+)-pbx\./i', $domain_name, $m)) {
        $slug = strtolower($m[1]);
    } else {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('.', $domain_name)[0]));
    }
    if (empty($slug)) $slug = "admin";
    
    $admin_user = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $post_data["admin_username"] ?? "")));
    if (empty($admin_user)) {
        $admin_user = $slug;
    }
    
    // Default password pattern: Apexlimited@2010
    $default_admin_pass = ucfirst($slug) . "@2010";
    $admin_pass = trim($post_data["admin_password"] ?? "");
    if (empty($admin_pass)) {
        $admin_pass = $default_admin_pass;
    }
    
    // 1. Ensure Domain exists in v_domains
    $sql = "SELECT domain_uuid FROM v_domains WHERE lower(domain_name) = :domain_name";
    $domain_uuid = $database->select($sql, ["domain_name" => $domain_name], "column");
    
    if (empty($domain_uuid)) {
        $domain_uuid = uuid();
        $ins_domain_sql = "INSERT INTO v_domains (domain_uuid, domain_name, domain_enabled, domain_description, insert_date) VALUES (:d_uuid, :d_name, true, :desc, now())";
        $database->execute($ins_domain_sql, [
            "d_uuid" => $domain_uuid,
            "d_name" => $domain_name,
            "desc" => $company_name
        ]);
        
        // Clone default dialplans for new domain
        $template_domain_uuid = "94e85c8a-668c-4a22-aa59-78a550bfedd9"; // radius
        $clone_dp_sql = "INSERT INTO v_dialplans (dialplan_uuid, domain_uuid, app_uuid, dialplan_name, dialplan_number, dialplan_destination, dialplan_context, dialplan_global, dialplan_type, dialplan_xml, dialplan_order, dialplan_enabled, dialplan_description, insert_date) "
                      . "SELECT gen_random_uuid(), :new_domain_uuid, app_uuid, dialplan_name, dialplan_number, dialplan_destination, :new_domain_name, false, dialplan_type, replace(dialplan_xml, 'radius.whiteinfobd.com', :new_domain_name), dialplan_order, dialplan_enabled, dialplan_description, now() "
                      . "FROM v_dialplans WHERE domain_uuid = :tpl_uuid AND dialplan_name NOT IN ('09639228920.88d11', 'Ring Group', 'call_direction-outbound.88d11')";
        $database->execute($clone_dp_sql, [
            "new_domain_uuid" => $domain_uuid,
            "new_domain_name" => $domain_name,
            "tpl_uuid" => $template_domain_uuid
        ]);
        
        // Ensure recordings directory exists
        @mkdir("/var/lib/freeswitch/recordings/" . $domain_name . "/archive", 0770, true);
        @chown("/var/lib/freeswitch/recordings/" . $domain_name, "www-data");
        @chown("/var/lib/freeswitch/recordings/" . $domain_name . "/archive", "www-data");

        // Outbound & Inbound Route Setup for new domain
        $gw_row = null;
        if (!empty($did)) {
            $gw_sql = "SELECT gateway_uuid, gateway FROM v_gateways WHERE (gateway = :did OR username = :did) AND enabled = 't' LIMIT 1";
            $gw_row = $database->select($gw_sql, ["did" => $did], "row");
        }
        if (empty($gw_row)) {
            $gw_sql = "SELECT gateway_uuid, gateway FROM v_gateways WHERE enabled = 't' ORDER BY insert_date ASC LIMIT 1";
            $gw_row = $database->select($gw_sql, [], "row");
        }

        if (!empty($gw_row)) {
            $gw_uuid = $gw_row["gateway_uuid"];
            $gw_name = $gw_row["gateway"];
            $dp_dir_uuid = uuid();
            $dp_out_uuid = uuid();

            $dir_xml = "<extension name=\"call_direction-outbound.88d11\" continue=\"1\" uuid=\"" . $dp_dir_uuid . "\">\n"
                     . "    <condition field=\"${user_exists}\" expression=\"false\"/>\n"
                     . "    <condition field=\"${call_direction}\" expression=\"^$\"/>\n"
                     . "    <condition field=\"destination_number\" expression=\"^(?:#)?(?:\\+?88)?(\\d{11})$\">\n"
                     . "        <action application=\"export\" data=\"call_direction=outbound\" inline=\"true\"/>\n"
                     . "    </condition>\n"
                     . "</extension>";
            $database->execute("INSERT INTO v_dialplans (dialplan_uuid, domain_uuid, app_uuid, dialplan_name, dialplan_number, dialplan_context, dialplan_xml, dialplan_order, dialplan_enabled, insert_date) VALUES (:uuid, :d_uuid, '8c914ec3-9120-4100-b6cf-785d470b10f5', 'call_direction-outbound.88d11', 'bd_mobile_direction', :domain, :xml, 333, true, now())", [
                "uuid" => $dp_dir_uuid,
                "d_uuid" => $domain_uuid,
                "domain" => $domain_name,
                "xml" => $dir_xml
            ]);

            $out_xml = "<extension name=\"" . $gw_name . ".88d11\" continue=\"\" uuid=\"" . $dp_out_uuid . "\">\n"
                     . "    <condition field=\"${user_exists}\" expression=\"false\"/>\n"
                     . "    <condition field=\"destination_number\" expression=\"^(?:#)?(?:\\+?88)?(\\d{11})$\">\n"
                     . "        <action application=\"export\" data=\"call_direction=outbound\" inline=\"true\"/>\n"
                     . "        <action application=\"unset\" data=\"call_timeout\"/>\n"
                     . "        <action application=\"set\" data=\"hangup_after_bridge=true\"/>\n"
                     . "        <action application=\"set\" data=\"effective_caller_id_name=${outbound_caller_id_name}\"/>\n"
                     . "        <action application=\"set\" data=\"effective_caller_id_number=${outbound_caller_id_number}\"/>\n"
                     . "        <action application=\"set\" data=\"inherit_codec=true\"/>\n"
                     . "        <action application=\"set\" data=\"ignore_display_updates=true\"/>\n"
                     . "        <action application=\"set\" data=\"callee_id_number=$1\"/>\n"
                     . "        <action application=\"set\" data=\"continue_on_fail=1,2,3,6,18,21,27,28,31,34,38,41,42,44,58,88,111,403,501,602,607,809\"/>\n"
                     . "        <action application=\"bridge\" data=\"sofia/gateway/" . $gw_uuid . "/$1\"/>\n"
                     . "    </condition>\n"
                     . "</extension>";
            $database->execute("INSERT INTO v_dialplans (dialplan_uuid, domain_uuid, app_uuid, dialplan_name, dialplan_number, dialplan_context, dialplan_xml, dialplan_order, dialplan_enabled, insert_date) VALUES (:uuid, :d_uuid, '8c914ec3-9120-4100-b6cf-785d470b10f5', :dp_name, 'bd_mobile', :domain, :xml, 334, true, now())", [
                "uuid" => $dp_out_uuid,
                "d_uuid" => $domain_uuid,
                "dp_name" => $gw_name . ".88d11",
                "domain" => $domain_name,
                "xml" => $out_xml
            ]);

            if (!empty($did)) {
                $in_dp_uuid = uuid();
                $in_xml = "<extension name=\"" . $did . "\" continue=\"\" uuid=\"" . $in_dp_uuid . "\">\n"
                        . "    <condition field=\"destination_number\" expression=\"^(" . preg_quote($did) . ")$\">\n"
                        . "        <action application=\"transfer\" data=\"6000 XML " . $domain_name . "\"/>\n"
                        . "    </condition>\n"
                        . "</extension>";
                $exist_in = $database->select("SELECT dialplan_uuid FROM v_dialplans WHERE dialplan_context = 'public' AND dialplan_name = :did", ["did" => $did], "column");
                if (empty($exist_in)) {
                    $database->execute("INSERT INTO v_dialplans (dialplan_uuid, domain_uuid, app_uuid, dialplan_name, dialplan_number, dialplan_context, dialplan_xml, dialplan_order, dialplan_enabled, insert_date) VALUES (:uuid, :d_uuid, 'c03b422e-13a8-bd1b-e42b-b6b9f447c4e6', :did, :did, 'public', :xml, 100, true, now())", [
                        "uuid" => $in_dp_uuid,
                        "d_uuid" => $domain_uuid,
                        "did" => $did,
                        "xml" => $in_xml
                    ]);
                }
            }
        }
    } else {
        $database->execute("UPDATE v_domains SET domain_enabled = true WHERE domain_uuid = :domain_uuid", ["domain_uuid" => $domain_uuid]);
    }
    
    // 2. Tenant Company Web User (Strictly 1 clean user per tenant domain, prevent duplicates)
    $sql_user = "SELECT user_uuid, username FROM v_users WHERE domain_uuid = :domain_uuid ORDER BY insert_date ASC LIMIT 1";
    $existing_user_row = $database->select($sql_user, ["domain_uuid" => $domain_uuid], "row");
    
    if (empty($existing_user_row)) {
        $new_user_uuid = uuid();
        $hashed_pass = password_hash($admin_pass, PASSWORD_DEFAULT);
        $user_sql = "INSERT INTO v_users (user_uuid, domain_uuid, username, password, user_status, user_type, user_enabled, add_date, insert_date) "
                  . "VALUES (:u_uuid, :d_uuid, :user, :pass, 'Available', 'default', true, now(), now())";
        $database->execute($user_sql, [
            "u_uuid" => $new_user_uuid,
            "d_uuid" => $domain_uuid,
            "user" => $admin_user,
            "pass" => $hashed_pass
        ]);
        
        // Add to company group
        $company_group_uuid = "f1c41fc2-bc2a-4363-a78d-5a513cb8ca4b";
        $ug_comp_sql = "INSERT INTO v_user_groups (user_group_uuid, domain_uuid, group_name, group_uuid, user_uuid, insert_date) VALUES (:ug_uuid, :d_uuid, 'company', :g_uuid, :u_uuid, now())";
        $database->execute($ug_comp_sql, [
            "ug_uuid" => uuid(),
            "d_uuid" => $domain_uuid,
            "g_uuid" => $company_group_uuid,
            "u_uuid" => $new_user_uuid
        ]);

        // Add to admin group (Tenant Administrator - extension & ring group management)
        $admin_group_uuid = "7ef559ae-39d6-44c3-9126-b50b6c425a2c";
        $ug_admin_sql = "INSERT INTO v_user_groups (user_group_uuid, domain_uuid, group_name, group_uuid, user_uuid, insert_date) VALUES (:ug_uuid, :d_uuid, 'admin', :g_uuid, :u_uuid, now())";
        $database->execute($ug_admin_sql, [
            "ug_uuid" => uuid(),
            "d_uuid" => $domain_uuid,
            "g_uuid" => $admin_group_uuid,
            "u_uuid" => $new_user_uuid
        ]);
    } else {
        $existing_user_uuid = $existing_user_row["user_uuid"];
        $admin_user = $existing_user_row["username"]; // keep clean existing username
        if (!empty($admin_pass)) {
            $database->execute("UPDATE v_users SET password = :pass, user_status = 'Available', user_type = 'default', user_enabled = true, update_date = now() WHERE user_uuid = :u_uuid", [
                "pass" => password_hash($admin_pass, PASSWORD_DEFAULT),
                "u_uuid" => $existing_user_uuid
            ]);
        }
        // Ensure company and admin groups are assigned
        $company_group_uuid = "f1c41fc2-bc2a-4363-a78d-5a513cb8ca4b";
        $admin_group_uuid = "7ef559ae-39d6-44c3-9126-b50b6c425a2c";
        $has_comp = $database->select("SELECT user_group_uuid FROM v_user_groups WHERE user_uuid = :u AND group_name = 'company'", [":u" => $existing_user_uuid], "column");
        if (empty($has_comp)) {
            $database->execute("INSERT INTO v_user_groups (user_group_uuid, domain_uuid, group_name, group_uuid, user_uuid, insert_date) VALUES (:ug_uuid, :d_uuid, 'company', :g_uuid, :u_uuid, now())", [
                ":ug_uuid" => uuid(),
                ":d_uuid" => $domain_uuid,
                ":g_uuid" => $company_group_uuid,
                ":u_uuid" => $existing_user_uuid
            ]);
        }
        $has_admin = $database->select("SELECT user_group_uuid FROM v_user_groups WHERE user_uuid = :u AND group_name = 'admin'", [":u" => $existing_user_uuid], "column");
        if (empty($has_admin)) {
            $database->execute("INSERT INTO v_user_groups (user_group_uuid, domain_uuid, group_name, group_uuid, user_uuid, insert_date) VALUES (:ug_uuid, :d_uuid, 'admin', :g_uuid, :u_uuid, now())", [
                ":ug_uuid" => uuid(),
                ":d_uuid" => $domain_uuid,
                ":g_uuid" => $admin_group_uuid,
                ":u_uuid" => $existing_user_uuid
            ]);
        }
    }
    
    // 3. Package Extensions (101 .. 100+N)
    $created_package_extensions = [];
    $start_ext = 101;
    for ($i = 0; $i < $ext_count; $i++) {
        $ext_num = strval($start_ext + $i);
        
        $sql_e = "SELECT extension_uuid, password FROM v_extensions WHERE domain_uuid = :domain_uuid AND extension = :ext";
        $row_e = $database->select($sql_e, ["domain_uuid" => $domain_uuid, "ext" => $ext_num], "row");
        
        if (empty($row_e)) {
            $e_uuid = uuid();
            $e_pass = generate_secure_password(16);
            $ins_sql = "INSERT INTO v_extensions (
                extension_uuid, domain_uuid, extension, number_alias, password,
                accountcode, effective_caller_id_name, effective_caller_id_number,
                outbound_caller_id_name, outbound_caller_id_number, directory_first_name,
                directory_last_name, directory_visible, directory_exten_visible, limit_max,
                limit_destination, user_context, toll_allow, call_timeout, call_group,
                user_record, hold_music, enabled, description, insert_date
            ) VALUES (
                :ext_uuid, :domain_uuid, :ext, :ext, :pass,
                :domain, :cid_name, :ext,
                :out_name, :out_num, :first_name,
                :last_name, true, true, 5,
                'error/user_busy', :domain, 'domestic', 30, 'sales',
                'all', 'local_stream://default', true, :desc, now()
            )";
            $database->execute($ins_sql, [
                "ext_uuid" => $e_uuid,
                "domain_uuid" => $domain_uuid,
                "ext" => $ext_num,
                "pass" => $e_pass,
                "domain" => $domain_name,
                "cid_name" => $company_name . " Ext " . $ext_num,
                "out_name" => $company_name,
                "out_num" => (!empty($did) ? $did : $ext_num),
                "first_name" => $company_name,
                "last_name" => "Ext " . $ext_num,
                "desc" => $company_name . " Package Extension " . $ext_num
            ]);
        } else {
            $e_pass = $row_e["password"];
            $database->execute("UPDATE v_extensions SET enabled = true, user_record = 'all' WHERE domain_uuid = :domain_uuid AND extension = :ext", [
                "domain_uuid" => $domain_uuid,
                "ext" => $ext_num
            ]);
        }
        
        $created_package_extensions[] = [
            "extension" => $ext_num,
            "password" => $e_pass,
            "caller_id" => !empty($did) ? $did : $ext_num,
            "status" => "active"
        ];
    }
    
    // 4. Create Extension 999 (FREE / UNBILLED / ALWAYS ACTIVE)
    $sql_999 = "SELECT extension_uuid, password FROM v_extensions WHERE domain_uuid = :domain_uuid AND extension = '999'";
    $row_999 = $database->select($sql_999, ["domain_uuid" => $domain_uuid], "row");
    
    if (empty($row_999)) {
        $e999_uuid = uuid();
        $e999_pass = generate_secure_password(16);
        $ins_sql_999 = "INSERT INTO v_extensions (
            extension_uuid, domain_uuid, extension, number_alias, password,
            accountcode, effective_caller_id_name, effective_caller_id_number,
            outbound_caller_id_name, outbound_caller_id_number, directory_first_name,
            directory_last_name, directory_visible, directory_exten_visible, limit_max,
            limit_destination, user_context, toll_allow, call_timeout, call_group,
            user_record, hold_music, enabled, description, insert_date
        ) VALUES (
            :ext_uuid, :domain_uuid, '999', '999', :pass,
            :domain, :cid_name, '999',
            :out_name, :out_num, :first_name,
            'Support 999', true, true, 5,
            'error/user_busy', :domain, 'domestic', 30, 'support',
            'all', 'local_stream://default', true, :desc, now()
        )";
        $database->execute($ins_sql_999, [
            "ext_uuid" => $e999_uuid,
            "domain_uuid" => $domain_uuid,
            "pass" => $e999_pass,
            "domain" => $domain_name,
            "cid_name" => $company_name . " Support 999",
            "out_name" => $company_name,
            "out_num" => (!empty($did) ? $did : "999"),
            "first_name" => $company_name,
            "desc" => $company_name . " Free Support Extension 999 (Unbilled)"
        ]);
    } else {
        $e999_pass = $row_999["password"];
        $database->execute("UPDATE v_extensions SET enabled = true, user_record = 'all' WHERE domain_uuid = :domain_uuid AND extension = '999'", [
            "domain_uuid" => $domain_uuid
        ]);
    }
    
    $free_extension_999 = [
        "extension" => "999",
        "password" => $e999_pass,
        "caller_id" => !empty($did) ? $did : "999",
        "status" => "active",
        "note" => "Free / Unbilled Extension - Always Active even during suspension"
    ];
    
    // 5. Ring Group 6000 Setup
    $sql_rg = "SELECT ring_group_uuid, dialplan_uuid FROM v_ring_groups WHERE domain_uuid = :domain_uuid AND ring_group_extension = '6000'";
    $row_rg = $database->select($sql_rg, ["domain_uuid" => $domain_uuid], "row");
    if (empty($row_rg)) {
        $rg_uuid = uuid();
        $dp_uuid = uuid();
        $dp_xml = "<extension name=\"Ring Group\" continue=\"\" uuid=\"" . $dp_uuid . "\">\n"
                . "    <condition field=\"destination_number\" expression=\"^6000$\">\n"
                . "        <action application=\"ring_ready\" data=\"\"/>\n"
                . "        <action application=\"set\" data=\"ring_group_uuid=" . $rg_uuid . "\"/>\n"
                . "        <action application=\"set\" data=\"record_stereo=true\"/>\n"
                . "        <action application=\"lua\" data=\"app.lua ring_groups\"/>\n"
                . "    </condition>\n"
                . "</extension>";
                
        $dp_sql = "INSERT INTO v_dialplans (dialplan_uuid, domain_uuid, dialplan_name, dialplan_number, dialplan_context, dialplan_xml, dialplan_order, dialplan_enabled, insert_date) "
                . "VALUES (:dp_uuid, :d_uuid, 'Ring Group', '6000', :domain, :xml, 100, true, now())";
        $database->execute($dp_sql, [
            "dp_uuid" => $dp_uuid,
            "d_uuid" => $domain_uuid,
            "domain" => $domain_name,
            "xml" => $dp_xml
        ]);
        
        $rg_sql = "INSERT INTO v_ring_groups (ring_group_uuid, domain_uuid, ring_group_name, ring_group_extension, ring_group_call_timeout, ring_group_strategy, ring_group_context, ring_group_enabled, ring_group_description, dialplan_uuid, ring_group_cid_name_prefix, ring_group_cid_number_prefix, insert_date) "
                . "VALUES (:rg_uuid, :d_uuid, 'Ring Group', '6000', 60, 'enterprise', :domain, true, :desc, :dp_uuid, '', '', now())";
        $database->execute($rg_sql, [
            "rg_uuid" => $rg_uuid,
            "d_uuid" => $domain_uuid,
            "domain" => $domain_name,
            "desc" => $company_name . " Main Ring Group",
            "dp_uuid" => $dp_uuid
        ]);
    } else {
        $rg_uuid = $row_rg["ring_group_uuid"];
    }
    
    // Refresh Ring Group destinations
    $database->execute("DELETE FROM v_ring_group_destinations WHERE domain_uuid = :domain_uuid AND ring_group_uuid = :rg_uuid", [
        "domain_uuid" => $domain_uuid,
        "rg_uuid" => $rg_uuid
    ]);
    foreach ($created_package_extensions as $p_ext) {
        $rg_dest_sql = "INSERT INTO v_ring_group_destinations (ring_group_destination_uuid, domain_uuid, ring_group_uuid, destination_number, destination_delay, destination_timeout, destination_enabled, insert_date) "
                     . "VALUES (:dest_uuid, :d_uuid, :rg_uuid, :dest_num, 0, 30, true, now())";
        $database->execute($rg_dest_sql, [
            "dest_uuid" => uuid(),
            "d_uuid" => $domain_uuid,
            "rg_uuid" => $rg_uuid,
            "dest_num" => $p_ext["extension"]
        ]);
    }
    
    // Clear cache
    @unlink("/var/cache/fusionpbx/dialplan." . $domain_name);
    exec("rm -f /var/cache/fusionpbx/directory.*@" . escapeshellarg($domain_name));
    exec("rm -f /var/cache/fusionpbx/setting.*" . escapeshellarg($domain_name) . "*");
    exec("fs_cli -x reloadxml");
    
    send_json(200, [
        "status" => "success",
        "domain" => $domain_name,
        "domain_uuid" => $domain_uuid,
        "portal_url" => "https://" . $domain_name,
        "central_portal_url" => "https://" . (!empty($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : "pbx1.whiteinfobd.com"),
        "admin_username" => $admin_user,
        "admin_password" => $admin_pass,
        "sip_server" => (!empty($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : "pbx1.whiteinfobd.com"),
        "server_ip" => (!empty($_SERVER["SERVER_ADDR"]) ? $_SERVER["SERVER_ADDR"] : "165.99.219.124"),
        "sip_port" => 5060,
        "package_extension_count" => count($created_package_extensions),
        "package_extensions" => $created_package_extensions,
        "free_extension_999" => $free_extension_999,
        "ring_group" => "6000"
    ]);
}

// Action: SUSPEND (Non-Payment)
// All extensions turned off EXCEPT Extension 999
if ($action === "suspend") {
    $sql = "SELECT domain_uuid FROM v_domains WHERE lower(domain_name) = :domain_name";
    $domain_uuid = $database->select($sql, ["domain_name" => $domain_name], "column");
    if (empty($domain_uuid)) {
        send_json(404, ["status" => "error", "message" => "Domain not found on PBX"]);
    }
    
    // Disable all package extensions EXCEPT 999
    $database->execute("UPDATE v_extensions SET enabled = false, update_date = now() WHERE domain_uuid = :domain_uuid AND extension != '999'", [
        "domain_uuid" => $domain_uuid
    ]);
    
    // Ensure Extension 999 remains ENABLED
    $database->execute("UPDATE v_extensions SET enabled = true WHERE domain_uuid = :domain_uuid AND extension = '999'", [
        "domain_uuid" => $domain_uuid
    ]);
    
    // Disable destinations in Ring Groups except 999
    $database->execute("UPDATE v_ring_group_destinations SET destination_enabled = false WHERE domain_uuid = :domain_uuid AND destination_number != '999'", [
        "domain_uuid" => $domain_uuid
    ]);
    
    // Get list of suspended extensions
    $rows_s = $database->select("SELECT extension FROM v_extensions WHERE domain_uuid = :domain_uuid AND extension != '999' ORDER BY extension ASC", [
        "domain_uuid" => $domain_uuid
    ], "all");
    $suspended_exts = !empty($rows_s) ? array_column($rows_s, "extension") : [];
    
    // Flush registrations and clear cache for suspended extensions
    foreach ($suspended_exts as $s_ext) {
        @unlink("/var/cache/fusionpbx/directory." . $s_ext . "@" . $domain_name);
        @exec("fs_cli -x " . escapeshellarg("sofia profile internal flush_inbound_reg " . $s_ext . "@" . $domain_name));
    }
    
    @unlink("/var/cache/fusionpbx/dialplan." . $domain_name);
    
    send_json(200, [
        "status" => "success",
        "domain" => $domain_name,
        "state" => "suspended",
        "message" => "Package extensions suspended due to non-payment. Extension 999 remains ACTIVE and unbilled.",
        "suspended_extensions" => $suspended_exts,
        "active_free_extension" => "999"
    ]);
}

// Action: UNSUSPEND (Reactivation on Payment)
if ($action === "unsuspend") {
    $sql = "SELECT domain_uuid FROM v_domains WHERE lower(domain_name) = :domain_name";
    $domain_uuid = $database->select($sql, ["domain_name" => $domain_name], "column");
    if (empty($domain_uuid)) {
        send_json(404, ["status" => "error", "message" => "Domain not found on PBX"]);
    }
    
    // Re-enable ALL extensions
    $database->execute("UPDATE v_extensions SET enabled = true, update_date = now() WHERE domain_uuid = :domain_uuid", [
        "domain_uuid" => $domain_uuid
    ]);
    
    // Re-enable Ring Group destinations
    $database->execute("UPDATE v_ring_group_destinations SET destination_enabled = true WHERE domain_uuid = :domain_uuid", [
        "domain_uuid" => $domain_uuid
    ]);
    
    // Ensure domain is enabled
    $database->execute("UPDATE v_domains SET domain_enabled = true WHERE domain_uuid = :domain_uuid", [
        "domain_uuid" => $domain_uuid
    ]);
    
    // Clear cache
    exec("rm -f /var/cache/fusionpbx/directory.*@" . escapeshellarg($domain_name));
    @unlink("/var/cache/fusionpbx/dialplan." . $domain_name);
    
    $rows_a = $database->select("SELECT extension FROM v_extensions WHERE domain_uuid = :domain_uuid ORDER BY extension ASC", [
        "domain_uuid" => $domain_uuid
    ], "all");
    $active_exts = !empty($rows_a) ? array_column($rows_a, "extension") : [];
    
    send_json(200, [
        "status" => "success",
        "domain" => $domain_name,
        "state" => "active",
        "message" => "All extensions successfully restored and reactivated.",
        "active_extensions" => $active_exts
    ]);
}


// Action: CHANGE_PACKAGE (Upgrade / Downgrade Extension Count)
if ($action === "change_package") {
    $sql = "SELECT domain_uuid, domain_description FROM v_domains WHERE lower(domain_name) = :domain_name";
    $domain_row = $database->select($sql, ["domain_name" => $domain_name], "row");
    if (empty($domain_row)) {
        send_json(404, ["status" => "error", "message" => "Domain not found on PBX"]);
    }
    $domain_uuid = $domain_row["domain_uuid"];
    $company_name = !empty($domain_row["domain_description"]) ? $domain_row["domain_description"] : $domain_name;
    
    $new_ext_count = intval($post_data["ext_count"] ?? 3);
    if ($new_ext_count < 1) $new_ext_count = 1;
    
    // Ring group uuid
    $sql_rg = "SELECT ring_group_uuid FROM v_ring_groups WHERE domain_uuid = :domain_uuid AND ring_group_extension = '6000'";
    $rg_uuid = $database->select($sql_rg, ["domain_uuid" => $domain_uuid], "column");
    
    $created_new = [];
    $disabled_exts = [];
    
    // 1. If increasing extensions up to new_ext_count
    for ($i = 1; $i <= $new_ext_count; $i++) {
        $ext_num = strval(100 + $i);
        $sql_e = "SELECT extension_uuid, password FROM v_extensions WHERE domain_uuid = :domain_uuid AND extension = :ext";
        $row_e = $database->select($sql_e, ["domain_uuid" => $domain_uuid, "ext" => $ext_num], "row");
        
        if (empty($row_e)) {
            $e_uuid = uuid();
            $e_pass = generate_secure_password(16);
            $ins_sql = "INSERT INTO v_extensions (
                extension_uuid, domain_uuid, extension, number_alias, password,
                accountcode, effective_caller_id_name, effective_caller_id_number,
                outbound_caller_id_name, outbound_caller_id_number, directory_first_name,
                directory_last_name, directory_visible, directory_exten_visible, limit_max,
                limit_destination, user_context, toll_allow, call_timeout, call_group,
                user_record, hold_music, enabled, description, insert_date
            ) VALUES (
                :ext_uuid, :domain_uuid, :ext, :ext, :pass,
                :domain, :cid_name, :ext,
                :out_name, :ext, :first_name,
                :last_name, true, true, 5,
                'error/user_busy', :domain, 'domestic', 30, 'sales',
                'all', 'local_stream://default', true, :desc, now()
            )";
            $database->execute($ins_sql, [
                "ext_uuid" => $e_uuid,
                "domain_uuid" => $domain_uuid,
                "ext" => $ext_num,
                "pass" => $e_pass,
                "domain" => $domain_name,
                "cid_name" => $company_name . " Ext " . $ext_num,
                "out_name" => $company_name,
                "first_name" => $company_name,
                "last_name" => "Ext " . $ext_num,
                "desc" => $company_name . " Package Extension " . $ext_num
            ]);
            
            // Add to ring group destinations
            if (!empty($rg_uuid)) {
                $rgd_uuid = uuid();
                $database->execute("INSERT INTO v_ring_group_destinations (
                    ring_group_destination_uuid, ring_group_uuid, domain_uuid,
                    destination_number, destination_delay, destination_timeout,
                    destination_enabled, destination_description, insert_date
                ) VALUES (
                    :rgd_uuid, :rg_uuid, :domain_uuid,
                    :dest_num, 0, 30,
                    true, :desc, now()
                )", [
                    "rgd_uuid" => $rgd_uuid,
                    "rg_uuid" => $rg_uuid,
                    "domain_uuid" => $domain_uuid,
                    "dest_num" => $ext_num,
                    "desc" => "Destination " . $ext_num
                ]);
            }
            $created_new[] = ["extension" => $ext_num, "password" => $e_pass];
        } else {
            // Re-enable if disabled
            $database->execute("UPDATE v_extensions SET enabled = true WHERE domain_uuid = :domain_uuid AND extension = :ext", [
                "domain_uuid" => $domain_uuid,
                "ext" => $ext_num
            ]);
            if (!empty($rg_uuid)) {
                $database->execute("UPDATE v_ring_group_destinations SET destination_enabled = true WHERE domain_uuid = :domain_uuid AND destination_number = :dest_num", [
                    "domain_uuid" => $domain_uuid,
                    "dest_num" => $ext_num
                ]);
            }
        }
    }
    
    // 2. If downsizing, disable any package extensions above the new count
    $sql_above = "SELECT extension FROM v_extensions WHERE domain_uuid = :domain_uuid AND extension != '999' ORDER BY extension ASC";
    $all_pkg_rows = $database->select($sql_above, ["domain_uuid" => $domain_uuid], "all");
    foreach ($all_pkg_rows as $row) {
        $num_val = intval($row["extension"]);
        if ($num_val > (100 + $new_ext_count)) {
            $database->execute("UPDATE v_extensions SET enabled = false WHERE domain_uuid = :domain_uuid AND extension = :ext", [
                "domain_uuid" => $domain_uuid,
                "ext" => $row["extension"]
            ]);
            if (!empty($rg_uuid)) {
                $database->execute("UPDATE v_ring_group_destinations SET destination_enabled = false WHERE domain_uuid = :domain_uuid AND destination_number = :dest_num", [
                    "domain_uuid" => $domain_uuid,
                    "dest_num" => $row["extension"]
                ]);
            }
            @unlink("/var/cache/fusionpbx/directory." . $row["extension"] . "@" . $domain_name);
            @exec("fs_cli -x " . escapeshellarg("sofia profile internal flush_inbound_reg " . $row["extension"] . "@" . $domain_name));
            $disabled_exts[] = $row["extension"];
        }
    }
    
    @unlink("/var/cache/fusionpbx/dialplan." . $domain_name);
    exec("fs_cli -x reloadxml");
    
    send_json(200, [
        "status" => "success",
        "domain" => $domain_name,
        "new_extension_count" => $new_ext_count,
        "created_extensions" => $created_new,
        "disabled_extensions" => $disabled_exts
    ]);
}

// Action: SSO (Generate One-Time Single Sign-On Token)
if ($action === "sso" || $action === "create_session") {
    $sql = "SELECT domain_uuid FROM v_domains WHERE lower(domain_name) = :domain_name";
    $domain_uuid = $database->select($sql, ["domain_name" => $domain_name], "column");
    if (empty($domain_uuid)) {
        send_json(404, ["status" => "error", "message" => "Domain not found on PBX"]);
    }
    
    // Find tenant admin user for this domain
    $sql_u = "SELECT u.user_uuid, u.username, u.user_email, u.contact_uuid 
              FROM v_users u 
              JOIN v_user_groups ug ON u.user_uuid = ug.user_uuid 
              JOIN v_groups g ON ug.group_uuid = g.group_uuid 
              WHERE u.domain_uuid = :domain_uuid AND g.group_name IN ('admin', 'company') AND u.user_enabled = true 
              ORDER BY u.insert_date ASC LIMIT 1";
    $user_row = $database->select($sql_u, ["domain_uuid" => $domain_uuid], "row");
    
    if (empty($user_row)) {
        // Fallback to any enabled user in domain
        $sql_u2 = "SELECT user_uuid, username, user_email, contact_uuid FROM v_users WHERE domain_uuid = :domain_uuid AND user_enabled = true ORDER BY insert_date ASC LIMIT 1";
        $user_row = $database->select($sql_u2, ["domain_uuid" => $domain_uuid], "row");
    }
    
    if (empty($user_row)) {
        send_json(404, ["status" => "error", "message" => "No admin user found for domain"]);
    }
    
    // Generate one-time 64-char hex token
    $token = bin2hex(random_bytes(32));
    $cache_file = "/var/cache/fusionpbx/sso_" . $token . ".json";
    
    $payload = [
        "domain_uuid" => $domain_uuid,
        "domain_name" => $domain_name,
        "user_uuid"   => $user_row["user_uuid"],
        "username"    => $user_row["username"],
        "user_email"  => $user_row["user_email"] ?? "",
        "contact_uuid"=> $user_row["contact_uuid"] ?? "",
        "created"     => time()
    ];
    
    file_put_contents($cache_file, json_encode($payload));
    chmod($cache_file, 0664);
    
    $sso_url = "https://" . $domain_name . "/sso.php?token=" . $token;
    
    send_json(200, [
        "status" => "success",
        "domain" => $domain_name,
        "username" => $user_row["username"],
        "sso_url" => $sso_url,
        "central_sso_url" => "https://pbx1.whiteinfobd.com/sso.php?token=" . $token,
        "expires_in" => 120
    ]);
}

// Action: TERMINATE (Strictly Enforced 'terminet hobe na' Policy)
if ($action === "terminate") {
    $sql = "SELECT domain_uuid FROM v_domains WHERE lower(domain_name) = :domain_name";
    $domain_uuid = $database->select($sql, ["domain_name" => $domain_name], "column");
    if (empty($domain_uuid)) {
        send_json(404, ["status" => "error", "message" => "Domain not found on PBX"]);
    }
    
    // Under the 'terminet hobe na' policy, NO DATA IS DELETED.
    // Extensions are disabled to prevent unauthorized usage, but domain, extensions,
    // call history (CDRs), and 30-day recordings are 100% PRESERVED.
    $database->execute("UPDATE v_extensions SET enabled = false, update_date = now() WHERE domain_uuid = :domain_uuid AND extension != '999'", [
        "domain_uuid" => $domain_uuid
    ]);
    
    $database->execute("UPDATE v_ring_group_destinations SET destination_enabled = false WHERE domain_uuid = :domain_uuid AND destination_number != '999'", [
        "domain_uuid" => $domain_uuid
    ]);
    
    // Clear cache
    exec("rm -f /var/cache/fusionpbx/directory.*@" . escapeshellarg($domain_name));
    @unlink("/var/cache/fusionpbx/dialplan." . $domain_name);
    
    send_json(200, [
        "status" => "success",
        "domain" => $domain_name,
        "state" => "terminated_soft",
        "message" => "Account terminated safely without deleting data. 'terminet hobe na' policy enforced: domain, extensions, call recordings and CDRs are preserved."
    ]);
}

// Default fallback
send_json(400, ["status" => "error", "message" => "Invalid action requested: " . htmlspecialchars($action)]);
?>