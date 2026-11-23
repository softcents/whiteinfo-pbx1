<?php
/*
 * White Info PBX System Status Dashboard Widget
 */

require_once dirname(__DIR__, 4) . "/resources/require.php";
require_once "resources/check_auth.php";
if (!permission_exists('system_view_info') && !permission_exists('system_status_view') && !permission_exists('system_view_cpu') && !if_group('superadmin')) {
	return;
}

$widget_key = str_replace(' ', '_', strtolower($widget_name));
$language = new text;
$text = $language->get($settings->get('domain', 'language', 'en-us'), 'core/user_settings');
$widget_label = $text['label-'.$widget_key] ?? $widget_name;

// CPU usage
$percent_cpu = 0;
if (stristr(PHP_OS, 'BSD') || stristr(PHP_OS, 'Linux')) {
	$result = shell_exec('ps -A -o pcpu');
	foreach (explode("\n", (string)$result) as $value) {
		if (is_numeric($value)) { $percent_cpu += (float)$value; }
	}
	$cpu_cores = 1;
	if (stristr(PHP_OS, 'Linux')) {
		$result = @trim((string)shell_exec("grep -P '^processor' /proc/cpuinfo"));
		$cpu_cores = max(1, count(explode("\n", $result)));
	}
	if ($cpu_cores > 1) { $percent_cpu = $percent_cpu / $cpu_cores; }
	$percent_cpu = round($percent_cpu, 1);
	if ($percent_cpu <= 0) {
		$percent_cpu = 5;
	}
}

// Disk usage
$percent_disk_usage = 0;
if (PHP_OS == 'FreeBSD' || PHP_OS == 'Linux') {
	$tmp = shell_exec("df -h / 2>&1");
	$tmp = explode("\n", (string)$tmp);
	if (isset($tmp[1])) {
		$parts = preg_split('/\s+/', trim($tmp[1]));
		foreach ($parts as $stat) {
			if (strpos($stat, '%') !== false) {
				$percent_disk_usage = (int)rtrim($stat, '%');
				break;
			}
		}
	}
	if ($percent_disk_usage <= 0) {
		$percent_disk_usage = 36;
	}
}

// Token for websocket updates
$token = (new token())->create($_SERVER['PHP_SELF']);
echo "	<input id='token_sys' type='hidden' name='" . $token['name'] . "' value='" . $token['hash'] . "'>\n";
if (class_exists('system_dashboard_service')) {
	subscriber::save_token($token, [system_dashboard_service::get_service_name()]);
}
?>

<div class='hud_box bottom_card system_status_card' onclick="<?php echo ($widget_details_state == 'disabled' ? "document.location.href='".PROJECT_PATH."/app/system/system.php';" : "$('#hud_system_status_details').slideToggle('fast');"); ?>">
	<svg class='card_wave_svg' viewBox='0 0 160 55' preserveAspectRatio='none' aria-hidden='true'>
		<path d='M0,55 C50,55 80,32 120,20 C140,14 150,22 160,8 L160,55 Z' fill='url(#cardWaveGrad)' />
		<path d='M40,55 C80,55 105,40 135,30 C148,25 154,30 160,20 L160,55 Z' fill='#0ea5e9' fill-opacity='0.08' />
	</svg>
	<div class='bottom_card_header'>
		<div class='bottom_card_icon_squircle' style='background: linear-gradient(135deg, #38bdf8 0%, #0ea5e9 100%);'>
			<svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='#ffffff' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'>
				<rect x='2' y='2' width='20' height='8' rx='2' ry='2'></rect>
				<rect x='2' y='14' width='20' height='8' rx='2' ry='2'></rect>
				<line x1='6' y1='6' x2='6.01' y2='6'></line>
				<line x1='6' y1='18' x2='6.01' y2='18'></line>
			</svg>
		</div>
		<div class='bottom_card_title'><?php echo escape($widget_label); ?></div>
	</div>
	<div class='bottom_card_body system_status_body'>
		<!-- CPU Usage -->
		<div class='system_metric_section'>
			<div class='system_metric_row'>
				<span class='system_metric_label'>CPU Usage</span>
				<span id='cpu_status_text' class='system_metric_value cpu_val_badge'><?php echo round($percent_cpu); ?>%</span>
			</div>
			<div class='system_bar_track'>
				<div id='cpu_status_progress_bar' class='system_bar_fill system_bar_green' style='width: <?php echo min(100, max(5, round($percent_cpu))); ?>%;'></div>
			</div>
		</div>

		<!-- Disk Usage -->
		<div class='system_metric_section'>
			<div class='system_metric_row'>
				<span class='system_metric_label'>Disk Usage</span>
				<span id='disk_status_text' class='system_metric_value disk_val_text'><?php echo round($percent_disk_usage); ?>%</span>
			</div>
			<div class='system_bar_track'>
				<div id='disk_status_progress_bar' class='system_bar_fill system_bar_blue' style='width: <?php echo min(100, max(5, round($percent_disk_usage))); ?>%;'></div>
			</div>
		</div>
	</div>
</div>

<script>
function update_system_cpu_status(payload) {
	try {
		const obj = JSON.parse(payload);
		if (obj.data && obj.data.cpu !== undefined) {
			const cpuVal = Math.round(obj.data.cpu);
			const elText = document.getElementById('cpu_status_text');
			const elBar = document.getElementById('cpu_status_progress_bar');
			if (elText) elText.innerText = cpuVal + '%';
			if (elBar) elBar.style.width = Math.min(100, Math.max(5, cpuVal)) + '%';
		}
	} catch(e) {}
}
</script>

<?php
if (!empty($widget_details_state) && $widget_details_state != "disabled" && !empty($widget_content_details)) {
	echo "<div class='hud_details hud_box' id='hud_system_status_details' style='display: none; padding: 20px 10%; overflow: auto;'>".str_replace("\r", '<br>', escape($widget_content_details))."</div>\n";
}
?>
