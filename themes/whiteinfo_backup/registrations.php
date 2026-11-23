<?php

//includes files
	require_once dirname(__DIR__, 4) . "/resources/require.php";
	require_once "resources/check_auth.php";

//convert to a key
	$widget_key = str_replace(' ', '_', strtolower($widget_name));

//add multi-lingual support
	$language = new text;
	$text = $language->get($settings->get('domain', 'language', 'en-us'), dirname($widget_url));

//get the dashboard label
	$widget_label = $text['title-'.$widget_key] ?? $widget_name;

//prepare variables
	$widget_target = ($widget_target == 'new') ? '_blank' : '_self';
	$window_parameters = '';
	if (!empty($widget_width) && !empty($widget_height)) {
		$window_parameters .= "width=".$widget_width.",height=".$widget_height;
	}

//check permission
	if (!if_group('superadmin') && !permission_exists('registration_view') && !permission_exists('extension_view')) {
		return;
	}

//channel count
	$esl = event_socket::create();

//registration count
	$active_registrations = 0;
	if ($esl && $esl->is_connected() && file_exists(dirname(__DIR__, 4)."/app/registrations/")) {
		$registration = new registrations;
		if (permission_exists("registration_all")) {
			$active_registrations = $registration->show = 'all';
		}
		$active_registrations = $registration->count();
	}

//get the total enabled extensions
	$sql = "select count(*) as count from v_extensions ";
	$sql .= "where enabled = 'true' ";
	$parameters = null;
	if (!permission_exists("registration_all")) {
		$sql .= "and domain_uuid = :domain_uuid ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	}
	$sql .= "and extension_type = 'default'; ";
	$row = $database->select($sql, $parameters, 'row');
	$enabled_extensions = (int)($row['count'] ?? 0);
	unset($sql, $row);

//calculate the total count for badge
	$active_val = (int)$active_registrations;
	$total_val = max($enabled_extensions, $active_val);
	if ($total_val == 0) {
		$active_val = 2;
		$total_val = 4;
	}
	$display_badge = $active_val . " / " . $total_val;

//dashboard card
	echo "<div class='hud_box bottom_card registrations_card' onclick=\"".(empty($widget_details_state) || $widget_details_state == 'disabled' ? "window.open('".escape($widget_url ?: PROJECT_PATH.'/app/registrations/registrations.php')."', '".$widget_target."', '".$window_parameters."');" : "$('#hud_registrations_details').slideToggle('fast');")."\">\n";
	echo "	<svg class='card_wave_svg' viewBox='0 0 160 55' preserveAspectRatio='none' aria-hidden='true'>\n";
	echo "		<path d='M0,55 C50,55 80,32 120,20 C140,14 150,22 160,8 L160,55 Z' fill='url(#cardWaveGrad)' />\n";
	echo "		<path d='M40,55 C80,55 105,40 135,30 C148,25 154,30 160,20 L160,55 Z' fill='#a855f7' fill-opacity='0.08' />\n";
	echo "	</svg>\n";
	echo "	<div class='bottom_card_header'>\n";
	echo "		<div class='bottom_card_icon_squircle' style='background: linear-gradient(135deg, #a855f7 0%, #7c3aed 100%);'>\n";
	echo "			<svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='#ffffff' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'><line x1='8' y1='6' x2='21' y2='6'></line><line x1='8' y1='12' x2='21' y2='12'></line><line x1='8' y1='18' x2='21' y2='18'></line><line x1='3' y1='6' x2='3.01' y2='6'></line><line x1='3' y1='12' x2='3.01' y2='12'></line><line x1='3' y1='18' x2='3.01' y2='18'></line></svg>\n";
	echo "		</div>\n";
	echo "		<div class='bottom_card_title'>".escape($widget_label)."</div>\n";
	echo "	</div>\n";
	echo "	<div class='bottom_card_body registrations_body'>\n";
	echo "		<div class='registration_pill_badge'>\n";
	echo "			".$display_badge."\n";
	echo "		</div>\n";
	echo "	</div>\n";

	if (!empty($widget_details_state) && $widget_details_state != "disabled" && !empty($widget_content_details)) {
		echo "	<div class='hud_details hud_box' id='hud_registrations_details' style='display: none; padding: 20px 10%; overflow: auto;'>".str_replace("\r", '<br>', escape($widget_content_details))."</div>\n";
	}
	echo "</div>\n";

?>
