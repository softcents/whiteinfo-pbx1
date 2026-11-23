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

// Normalize key for matching
	$clean_name = strtolower(trim($widget_name ?? ''));

// Check role permission
	if (function_exists('dashboard_widget_has_permission') && !dashboard_widget_has_permission($widget_name, 'dashboard/icon', $widget_url)) {
		return;
	}

// Map of squircle gradients and SVG icons matching the mockup
	$icon_map = [
		'call detail records' => [
			'gradient' => 'linear-gradient(135deg, #1d72fe 0%, #0052cc 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="9" y1="6" x2="20" y2="6"></line><line x1="9" y1="12" x2="20" y2="12"></line><line x1="9" y1="18" x2="20" y2="18"></line><rect x="4" y="4.5" width="2.8" height="2.8" rx="0.8" fill="#ffffff"></rect><rect x="4" y="10.5" width="2.8" height="2.8" rx="0.8" fill="#ffffff"></rect><rect x="4" y="16.5" width="2.8" height="2.8" rx="0.8" fill="#ffffff"></rect></svg>'
		],
		'conference centers' => [
			'gradient' => 'linear-gradient(135deg, #a855f7 0%, #7c3aed 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>'
		],
		'conferences' => [
			'gradient' => 'linear-gradient(135deg, #fb923c 0%, #ea580c 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 9a2 2 0 0 1-2 2H6l-3 3V4a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v5z" fill="#ffffff"></path><path d="M18 9h-2v3a2 2 0 0 1-2 2h-3v1a2 2 0 0 0 2 2h5l3 3v-6a2 2 0 0 0-1-1.73V11a2 2 0 0 0-2-2z" fill="#ffffff" fill-opacity="0.85"></path></svg>'
		],
		'contacts' => [
			'gradient' => 'linear-gradient(135deg, #10b981 0%, #059669 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"></rect><circle cx="9" cy="10" r="2"></circle><path d="M15 8h2"></path><path d="M15 12h2"></path><path d="M7 16h4"></path></svg>'
		],
		'destinations' => [
			'gradient' => 'linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line><path d="M14 5h5a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-5"></path></svg>'
		],
		'devices' => [
			'gradient' => 'linear-gradient(135deg, #06b6d4 0%, #0891b2 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line><line x1="9" y1="6" x2="15" y2="6"></line><circle cx="9" cy="10" r="0.8" fill="#fff"></circle><circle cx="12" cy="10" r="0.8" fill="#fff"></circle><circle cx="15" cy="10" r="0.8" fill="#fff"></circle><circle cx="9" cy="13" r="0.8" fill="#fff"></circle><circle cx="12" cy="13" r="0.8" fill="#fff"></circle><circle cx="15" cy="13" r="0.8" fill="#fff"></circle></svg>'
		],
		'event guard' => [
			'gradient' => 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><polyline points="9 12 11 14 15 10"></polyline></svg>'
		],
		'extensions' => [
			'gradient' => 'linear-gradient(135deg, #6366f1 0%, #4f46e5 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>'
		],
		'follow me' => [
			'gradient' => 'linear-gradient(135deg, #ec4899 0%, #db2777 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="5 9 2 12 5 15"></polyline><polyline points="9 5 12 2 15 5"></polyline><polyline points="15 19 12 22 9 19"></polyline><polyline points="19 9 22 12 19 15"></polyline><line x1="2" y1="12" x2="22" y2="12"></line><line x1="12" y1="2" x2="12" y2="22"></line></svg>'
		],
		'call forward' => [
			'gradient' => 'linear-gradient(135deg, #ec4899 0%, #db2777 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="5 9 2 12 5 15"></polyline><polyline points="9 5 12 2 15 5"></polyline><polyline points="15 19 12 22 9 19"></polyline><polyline points="19 9 22 12 19 15"></polyline><line x1="2" y1="12" x2="22" y2="12"></line><line x1="12" y1="2" x2="12" y2="22"></line></svg>'
		],
		'ivr menus' => [
			'gradient' => 'linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="5" r="1.8" fill="#fff"></circle><circle cx="12" cy="5" r="1.8" fill="#fff"></circle><circle cx="19" cy="5" r="1.8" fill="#fff"></circle><circle cx="5" cy="12" r="1.8" fill="#fff"></circle><circle cx="12" cy="12" r="1.8" fill="#fff"></circle><circle cx="19" cy="12" r="1.8" fill="#fff"></circle><circle cx="5" cy="19" r="1.8" fill="#fff"></circle><circle cx="12" cy="19" r="1.8" fill="#fff"></circle><circle cx="19" cy="19" r="1.8" fill="#fff"></circle></svg>'
		],
		'recordings' => [
			'gradient' => 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>'
		],
		'ring groups' => [
			'gradient' => 'linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>'
		],
		'time conditions' => [
			'gradient' => 'linear-gradient(135deg, #14b8a6 0%, #0d9488 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><circle cx="12" cy="15" r="3"></circle><polyline points="12 13.5 12 15 13 16"></polyline></svg>'
		],
		'user profile' => [
			'gradient' => 'linear-gradient(135deg, #10b981 0%, #059669 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle><circle cx="18" cy="17" r="2.5" fill="#10b981" stroke="#ffffff" stroke-width="1.5"></circle></svg>'
		],
		'users' => [
			'gradient' => 'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>'
		],
		'voicemails' => [
			'gradient' => 'linear-gradient(135deg, #f43f5e 0%, #e11d48 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>'
		],
		'logout' => [
			'gradient' => 'linear-gradient(135deg, #f87171 0%, #ea580c 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>'
		],
		'domains' => [
			'gradient' => 'linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%)',
			'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>'
		],
	];

	$card_config = $icon_map[$clean_name] ?? [
		'gradient' => 'linear-gradient(135deg, #6366f1 0%, #4f46e5 100%)',
		'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>'
	];

//dashboard icon card
	$card_click = (empty($widget_details_state) || $widget_details_state == 'disabled')
		? ($widget_target == '_blank' ? "window.open('".escape($widget_url)."', '_blank');" : "document.location.href='".escape($widget_url)."';") . " return false;"
		: "$('#hud_icon_details_".$widget_id."').slideToggle('fast');";

	echo "<div class='hud_box whiteinfo_card' onclick=\"".$card_click."\">\n";
	echo "	<svg class='card_wave_svg' viewBox='0 0 160 55' preserveAspectRatio='none' aria-hidden='true'>\n";
	echo "		<path d='M0,55 C50,55 80,32 120,20 C140,14 150,22 160,8 L160,55 Z' fill='url(#cardWaveGrad)' />\n";
	echo "		<path d='M40,55 C80,55 105,40 135,30 C148,25 154,30 160,20 L160,55 Z' fill='#818cf8' fill-opacity='0.05' />\n";
	echo "	</svg>\n";
	echo "	<div class='hud_content whiteinfo_card_content'>\n";
	echo "		<div class='whiteinfo_icon_squircle' style='background: ".$card_config['gradient'].";'>\n";
	echo "			".$card_config['svg']."\n";
	echo "		</div>\n";
	echo "		<div class='whiteinfo_card_title'>\n";
	echo "			".escape($widget_label)."\n";
	echo "		</div>\n";
	echo "		<div class='whiteinfo_card_arrow'>\n";
	echo "			<svg width='8' height='14' viewBox='0 0 8 14' fill='none' xmlns='http://www.w3.org/2000/svg'>\n";
	echo "				<path d='M1 1.5L6.5 7L1 12.5' stroke='#94A3B8' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'/>\n";
	echo "			</svg>\n";
	echo "		</div>\n";
	echo "	</div>\n";

	if (!empty($widget_details_state) && $widget_details_state != "disabled") {
		echo "	<div class='hud_details hud_box' id='hud_icon_details_".$widget_id."' style='padding: 20px; 10%; overflow: auto; ".(!empty($row['widget_detail_background_color']) ? "background: ".$row['widget_detail_background_color'].";" : null)."'>".str_replace("\r", '<br>', escape($widget_content_details))."</div>\n";
	}
	echo "</div>\n";

?>
