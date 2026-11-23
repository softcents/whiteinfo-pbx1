<?php
/**
 * FusionPBX Single Sign-On (SSO) Handler
 * Secure One-Time Token Authentication for WHMCS
 */

require_once __DIR__ . "/resources/require.php";

$token = trim($_GET["token"] ?? $_GET["key"] ?? "");
if (empty($token) || !preg_match("/^[a-f0-9]{32,64}$/", $token)) {
    header("Location: /login.php");
    exit;
}

$cache_file = "/var/cache/fusionpbx/sso_" . $token . ".json";
if (!file_exists($cache_file)) {
    header("Location: /login.php?error=invalid_token");
    exit;
}

$content = file_get_contents($cache_file);
@unlink($cache_file); // strictly one-time use!

$data = json_decode($content, true);
if (empty($data) || !is_array($data)) {
    header("Location: /login.php?error=corrupt_token");
    exit;
}

// Token expires after 120 seconds
if (time() - ($data["created"] ?? 0) > 120) {
    header("Location: /login.php?error=expired_token");
    exit;
}

// Start Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session core variables
$_SESSION["domain_uuid"] = $data["domain_uuid"];
$_SESSION["domain_name"] = $data["domain_name"];
$_SESSION["user_uuid"]   = $data["user_uuid"];
$_SESSION["username"]    = $data["username"];
$_SESSION["user_email"]  = $data["user_email"] ?? "";
$_SESSION["contact_uuid"] = $data["contact_uuid"] ?? "";
$_SESSION["context"]     = $data["domain_name"];
$_SESSION["authorized"]  = true;

$server_array = ["HTTP_USER_AGENT" => $_SERVER["HTTP_USER_AGENT"] ?? ""];
$_SESSION["user_hash"] = hash("sha256", implode($server_array));
$_SESSION["session"]["created"] = time();
$_SESSION["session"]["last_activity"] = time();

// Set domain session
$domain = new domains();
$domain->session();
$domain->set();

// User structure
$_SESSION["user"]["domain_uuid"] = $data["domain_uuid"];
$_SESSION["user"]["domain_name"] = $data["domain_name"];
$_SESSION["user"]["user_uuid"]   = $data["user_uuid"];
$_SESSION["user"]["username"]    = $data["username"];
$_SESSION["user"]["contact_uuid"] = $data["contact_uuid"] ?? null;

// Populate contact details if available
if (!empty($data["contact_uuid"])) {
    $sql_c = "SELECT contact_organization, contact_name_given, contact_name_family, contact_image FROM v_contacts WHERE contact_uuid = :c";
    $c_row = $database->select($sql_c, [":c" => $data["contact_uuid"]], "row");
    if (!empty($c_row)) {
        $_SESSION["user"]["contact_organization"] = $c_row["contact_organization"] ?? null;
        $_SESSION["user"]["contact_name"] = trim(($c_row["contact_name_given"] ?? "") . " " . ($c_row["contact_name_family"] ?? ""));
        $_SESSION["user"]["contact_name_given"] = $c_row["contact_name_given"] ?? null;
        $_SESSION["user"]["contact_name_family"] = $c_row["contact_name_family"] ?? null;
        $_SESSION["user"]["contact_image"] = !empty($c_row["contact_image"]) && is_uuid($c_row["contact_image"]) ? $c_row["contact_image"] : null;
    }
}

// Empty old permissions/groups/menu
unset($_SESSION["permissions"]);
unset($_SESSION["groups"]);
unset($_SESSION["menu"]);

// Load groups assigned to this tenant user (admin, user, etc.)
if (class_exists("groups")) {
    $group = new groups($database, $data["domain_uuid"], $data["user_uuid"]);
    $group->session();
}

// Load permissions assigned to this user through their groups
if (class_exists("permissions")) {
    $permission = new permissions($database, $data["domain_uuid"], $data["user_uuid"]);
    $permission->session();
}

// Load user settings
$settings = new settings([
    "database" => $database,
    "domain_uuid" => $data["domain_uuid"],
    "user_uuid" => $data["user_uuid"]
]);
settings::clear_cache();

// Log successful SSO login
if (class_exists("user_logs")) {
    user_logs::add([
        "domain_uuid" => $data["domain_uuid"],
        "domain_name" => $data["domain_name"],
        "username" => $data["username"],
        "user_uuid" => $data["user_uuid"],
        "authorized" => true
    ]);
}

header("Location: /core/dashboard/");
exit;
