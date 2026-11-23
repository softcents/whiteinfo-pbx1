<?php
/*
 * White Info PBX Active Calls Dashboard Widget
 */

$project_root = dirname(__DIR__, 4);
require_once "$project_root/resources/require.php";
require_once "$project_root/resources/check_auth.php";
if (!permission_exists('call_active_view') && !if_group('superadmin')) {
	return;
}

$widget_key = str_replace(' ', '_', strtolower($widget_name));
$text = (new text)->get($settings->get('domain', 'language', 'en-us'), 'app/active_calls');
$widget_label = $text['label-'.$widget_key] ?? $widget_name;

$token = (new token())->create($_SERVER['PHP_SELF']);
echo "  <input id='current_context' type='hidden' name='current_context' value='" . ($_SESSION['domain_name'] ?? '') . "'>\n";
echo "	<input id='token' type='hidden' name='" . $token['name'] . "' value='" . $token['hash'] . "'>\n";

if (class_exists('subscriber')) {
	subscriber::save_token($token, ['active.calls']);
}
?>

<div class='hud_box bottom_card active_calls_card' onclick="<?php echo ($widget_details_state == 'disabled' ? "document.location.href='".PROJECT_PATH."/app/active_calls/active_calls.php';" : "$('#hud_active_calls_details').slideToggle('fast');"); ?>">
	<svg class='card_wave_svg' viewBox='0 0 160 55' preserveAspectRatio='none' aria-hidden='true'>
		<path d='M0,55 C50,55 80,32 120,20 C140,14 150,22 160,8 L160,55 Z' fill='url(#cardWaveGrad)' />
		<path d='M40,55 C80,55 105,40 135,30 C148,25 154,30 160,20 L160,55 Z' fill='#10b981' fill-opacity='0.08' />
	</svg>
	<div class='bottom_card_header'>
		<div class='bottom_card_icon_squircle' style='background: #d1fae5;'>
			<svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='#10b981' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'>
				<path d='M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z'></path>
			</svg>
		</div>
		<div class='bottom_card_title'><?php echo escape($widget_label); ?></div>
	</div>
	<div class='bottom_card_body active_calls_body' style='height: 105px;'>
		<div class='active_calls_chart_wrapper'>
			<!-- Y-Axis Labels -->
			<div class='active_calls_yaxis'>
				<span>5</span>
				<span>4</span>
				<span>3</span>
				<span>2</span>
				<span>1</span>
				<span>0</span>
			</div>
			<!-- Spline Chart -->
			<div class='active_calls_svg_container'>
				<svg class='active_calls_spline' viewBox='0 0 320 85' preserveAspectRatio='none'>
					<defs>
						<linearGradient id='greenLineGrad' x1='0%' y1='0%' x2='0%' y2='100%'>
							<stop offset='0%' stop-color='#10b981' stop-opacity='0.28' />
							<stop offset='100%' stop-color='#10b981' stop-opacity='0.0' />
						</linearGradient>
					</defs>
					<!-- Filled Area -->
					<path d='M 10 80 C 40 80, 60 65, 80 50 C 100 35, 120 70, 150 55 C 180 40, 200 15, 230 15 C 255 15, 275 45, 305 45 L 305 85 L 10 85 Z' fill='url(#greenLineGrad)' />
					<!-- Curved Line -->
					<path d='M 10 80 C 40 80, 60 65, 80 50 C 100 35, 120 70, 150 55 C 180 40, 200 15, 230 15 C 255 15, 275 45, 305 45' fill='none' stroke='#10b981' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round' />
					<!-- Glowing Coordinate Dots -->
					<circle cx='10' cy='80' r='3.8' fill='#ffffff' stroke='#10b981' stroke-width='2.2' />
					<circle cx='80' cy='50' r='3.8' fill='#ffffff' stroke='#10b981' stroke-width='2.2' />
					<circle cx='150' cy='55' r='3.8' fill='#ffffff' stroke='#10b981' stroke-width='2.2' />
					<circle cx='230' cy='15' r='4.2' fill='#ffffff' stroke='#10b981' stroke-width='2.4' />
					<circle cx='305' cy='45' r='3.8' fill='#ffffff' stroke='#10b981' stroke-width='2.2' />
				</svg>
			</div>
		</div>
		<input type='hidden' id='calls_active_count' name='calls_active_count' value='0'>
	</div>
</div>

<?php
if (!empty($widget_details_state) && $widget_details_state != "disabled" && !empty($widget_content_details)) {
	echo "<div class='hud_details hud_box' id='hud_active_calls_details' style='display: none; padding: 20px 10%; overflow: auto;'>".str_replace("\r", '<br>', escape($widget_content_details))."</div>\n";
}
?>
