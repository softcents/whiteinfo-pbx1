<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2022-2026
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";

//if config.conf file does not exist then redirect to the install page
	if (file_exists("/usr/local/etc/fusionpbx/config.conf")){
		//BSD
	}
	elseif (file_exists("/etc/fusionpbx/config.conf")){
		//Linux
	}
	elseif (file_exists(getenv('SystemDrive') . DIRECTORY_SEPARATOR . 'ProgramData' . DIRECTORY_SEPARATOR . 'fusionpbx' . DIRECTORY_SEPARATOR . 'config.conf')) {
		// Windows
	}
	else {
		header("Location: /core/install/install.php");
		exit;
	}

//additional includes
	require_once "resources/check_auth.php";

//disable login message
	if (isset($_GET['msg']) && $_GET['msg'] == 'dismiss') {
		unset($_SESSION['login']['message']);

		$sql = "update v_default_settings ";
		$sql .= "set default_setting_enabled = false ";
		$sql .= "where ";
		$sql .= "default_setting_category = 'login' ";
		$sql .= "and default_setting_subcategory = 'message' ";
		$sql .= "and default_setting_name = 'text' ";
		$database->execute($sql);
		unset($sql);
	}

//build a list of groups the user is a member of to be used in a SQL in
	if (is_array($_SESSION['user']['groups'])) {
		foreach ($_SESSION['user']['groups'] as $group) {
			$group_uuids[] =  $group['group_uuid'];
		}
	}
	if (is_array($group_uuids)) {
		$group_uuids_in = "'".implode("','", $group_uuids)."'";
	}

//get the dashboard uuid
	$sql = "select dashboard_uuid ";
	$sql .= "from v_dashboards ";
	$sql .= "where dashboard_enabled = true ";
	$sql .= "and (";
	$sql .= "	domain_uuid = :domain_uuid ";
	$sql .= "	or domain_uuid is null ";
	$sql .= ") ";
	if (!empty($_GET['name'])) {
		$sql .= "and dashboard_name = :dashboard_name ";
		$parameters['dashboard_name'] = $_GET['name'];
	}
	$sql .= "order by case when domain_uuid = :domain_uuid then 0 else 1 end ";
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$dashboard_uuid = $database->select($sql, $parameters ?? null, 'column');
	unset($sql, $parameters);

//get the list
	$sql = "select ";
	$sql .= "dashboard_uuid, ";
	$sql .= "dashboard_widget_uuid, ";
	$sql .= "widget_name, ";
	$sql .= "widget_path, ";
	$sql .= "widget_icon, ";
	$sql .= "widget_icon_color, ";
	$sql .= "widget_url, ";
	$sql .= "widget_target, ";
	$sql .= "widget_width, ";
	$sql .= "widget_height, ";
	$sql .= "widget_content, ";
	$sql .= "widget_content_text_align, ";
	$sql .= "widget_content_details, ";
	$sql .= "widget_chart_type, ";
	$sql .= "widget_label_enabled, ";
	$sql .= "widget_label_text_color, ";
	$sql .= "widget_label_text_color_hover, ";
	$sql .= "widget_label_background_color, ";
	$sql .= "widget_label_background_color_hover, ";
	$sql .= "widget_number_text_color, ";
	$sql .= "widget_number_text_color_hover, ";
	$sql .= "widget_number_background_color, ";
	$sql .= "widget_background_color, ";
	$sql .= "widget_background_color_hover, ";
	$sql .= "widget_detail_background_color, ";
	$sql .= "widget_background_gradient_style, ";
	$sql .= "widget_background_gradient_angle, ";
	$sql .= "widget_column_span, ";
	$sql .= "widget_row_span, ";
	$sql .= "widget_details_state, ";
	$sql .= "dashboard_widget_parent_uuid, ";
	$sql .= "widget_order, ";
	$sql .= "cast(widget_enabled as text), ";
	$sql .= "widget_description ";
	$sql .= "from v_dashboard_widgets as d ";
	$sql .= "where widget_enabled = true ";
	$sql .= "and dashboard_widget_uuid in ( ";
	$sql .= "	select dashboard_widget_uuid from v_dashboard_widget_groups where group_uuid in ( ";
	$sql .= "		".$group_uuids_in." ";
	$sql .= "	) ";
	$sql .= ") ";
	$sql .= "and dashboard_uuid = :dashboard_uuid ";
	$sql .= "order by widget_order, widget_name asc ";
	$parameters['dashboard_uuid'] = $dashboard_uuid;
	$widgets = $database->select($sql, $parameters ?? null, 'all');
	unset($sql, $parameters);

//get the list of widget uuids
	$widget_uuid_list = [];
	if (!empty($widgets)) {
		foreach ($widgets as $row) {
			$widget_uuid_list[] = $row['dashboard_widget_uuid'];
		}
	}

//get http post variables and set them to php variables
	if (count($_POST) > 0 && permission_exists('dashboard_edit')) {
		if (isset($_POST["widget_order"])) {
			$widget_order = explode(",", $_POST["widget_order"]);
			$x = 0;

			foreach ($widget_order as $widget) {
				list($widget_id, $parent_id, $order) = explode("|", $widget);
				$parent_uuid = null;

				foreach ($widgets as $row) {
					$dashboard_widget_id = 'id_'.md5(preg_replace('/[^-A-Fa-f0-9]/', '', $row['dashboard_widget_uuid']));
					if ($widget_id == $dashboard_widget_id) {
						if (!empty($parent_id)) {
							foreach ($widgets as $parent_row) {
								$parent_widget_id = 'id_'.md5(preg_replace('/[^-A-Fa-f0-9]/', '', $parent_row['dashboard_widget_uuid']));
								if ($parent_widget_id === $parent_id) {
									$parent_uuid = $parent_row['dashboard_widget_uuid'];
									break;
								}
							}
						}
						$array['dashboard_widgets'][$x]['dashboard_widget_uuid'] = $row['dashboard_widget_uuid'];
						$array['dashboard_widgets'][$x]['widget_name'] = $row['widget_name'];
						$array['dashboard_widgets'][$x]['widget_icon'] = $row['widget_icon'];
						$array['dashboard_widgets'][$x]['widget_url'] = $row['widget_url'];
						$array['dashboard_widgets'][$x]['widget_content'] = $row['widget_content'];
						$array['dashboard_widgets'][$x]['widget_content_text_align'] = $row['widget_content_text_align'];
						$array['dashboard_widgets'][$x]['widget_content_details'] = $row['widget_content_details'];
						$array['dashboard_widgets'][$x]['widget_target'] = $row['widget_target'];
						$array['dashboard_widgets'][$x]['widget_width'] = $row['widget_width'];
						$array['dashboard_widgets'][$x]['widget_height'] = $row['widget_height'];
						$array['dashboard_widgets'][$x]['widget_order'] = $order;
						$array['dashboard_widgets'][$x]['dashboard_widget_parent_uuid'] = $parent_uuid;
						$x++;
						break;
					}
				}
			}

			if (is_array($array)) {
				$database->save($array);
			}

			message::add($text['message-update']);
			header("Location: /core/dashboard/".(!empty($_GET['name']) ? "?name=".urlencode($_GET['name']) : null));
			return;
		}
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//add the settings object
	$settings = new settings(["domain_uuid" => $_SESSION['domain_uuid'], "user_uuid" => $_SESSION['user_uuid']]);

//load the header
	$document['title'] = $text['title-dashboard'];
	require_once "resources/header.php";

//include websockets
	$version = md5(file_get_contents(__DIR__ . '/resources/javascript/ws_client.js'));
	echo "<script src='/core/dashboard/resources/javascript/ws_client.js?v=$version'></script>\n";

//include sortablejs
	echo "<script src='/resources/sortablejs/sortable.min.js'></script>";

//include chart.js
	echo "<script src='/resources/chartjs/chart.min.js'></script>";
	echo "<script src='/resources/chartjs/chartjs-adapter-date-fns.bundle.min.js'></script>";
	echo "<script src='/resources/chartjs/chartjs-plugin-streaming.js'></script>";

//chart variables
	echo "<script>\n";
	echo "	var chart_text_font = '".$settings->get('theme', 'dashboard_number_text_font', 'arial')."';\n";
	echo "	var chart_text_size = '".$settings->get('theme', 'dashboard_chart_text_size', '30px')."';\n";
	echo "	Chart.overrides.doughnut.cutout = '".$settings->get('theme', 'dashboard_chart_cutout', '75%')."';\n";
	echo "	Chart.defaults.responsive = true;\n";
	echo "	Chart.defaults.maintainAspectRatio = false;\n";
	echo "	Chart.defaults.plugins.legend.display = false;\n";
	echo "</script>\n";

//determine initial state all button to display
	$expanded_all = true;
	if (!empty($widgets)) {
		foreach ($widgets as $row) {
			if ($row['widget_details_state'] == 'contracted' || $row['widget_details_state'] == 'hidden' || $row['widget_details_state'] == 'disabled') { $expanded_all = false; }
		}
	}

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading whiteinfo_dash_heading'>\n";
	echo "		<div class='dash_header_bars'>\n";
	echo "			<span class='bar bar-1'></span>\n";
	echo "			<span class='bar bar-2'></span>\n";
	echo "			<span class='bar bar-3'></span>\n";
	echo "			<span class='bar bar-4'></span>\n";
	echo "		</div>\n";
	echo "		<div class='dash_title_block'>\n";
	echo "			<div class='dash_title'>".$text['title-dashboard']."</div>\n";
	echo "			<div class='dash_subtitle'>PBX Management Console</div>\n";
	echo "		</div>\n";
	echo "	</div>\n";
	echo "	<div class='actions whiteinfo_dash_actions'>\n";
	echo "		<form id='dashboard' method='post' _onsubmit='setFormSubmitting()'>\n";
	if ($settings->get('theme', 'menu_style', '') != 'side') {
		echo "		".$text['label-welcome']." <a href='".PROJECT_PATH."/core/users/user_profile.php'>".$_SESSION["username"]."</a>&nbsp; &nbsp;";
	}
	if (permission_exists('dashboard_edit')) {
		echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','name'=>'btn_back','style'=>'display: none;','onclick'=>"edit_mode('off');"]);
		echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save','name'=>'btn_save','style'=>'display: none; margin-left: 15px;']);
	}
	echo "<span id='expand_contract'>\n";
		echo "<button type='button' id='btn_expand' class='btn_whiteinfo btn_purple' style='".($expanded_all ? 'display: none;' : null)."' onclick=\"$('.hud_details').slideDown('fast'); $(this).hide(); $('#btn_contract').show(); toggle_grid_row_span_all();\"><svg width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='15 3 21 3 21 9'></polyline><polyline points='9 21 3 21 3 15'></polyline><line x1='21' y1='3' x2='14' y2='10'></line><line x1='3' y1='21' x2='10' y2='14'></line></svg> ".$text['button-expand_all']."</button>\n";
		echo "<button type='button' id='btn_contract' class='btn_whiteinfo btn_purple' style='".(!$expanded_all ? 'display: none;' : null)."' onclick=\"$('.hud_details').slideUp('fast'); $(this).hide(); $('#btn_expand').show(); toggle_grid_row_span_all();\"><svg width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='4 14 10 14 10 20'></polyline><polyline points='20 10 14 10 14 4'></polyline><line x1='14' y1='10' x2='21' y2='3'></line><line x1='3' y1='21' x2='10' y2='14'></line></svg> ".$text['button-collapse_all']."</button>\n";
	echo "</span>\n";
	if (permission_exists('dashboard_edit')) {
		echo "<button type='button' id='btn_edit' class='btn_whiteinfo btn_blue' onclick=\"edit_mode('on');\"><svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><path d='M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7'></path><path d='M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z'></path></svg> ".$text['button-edit']."</button>\n";
		echo "<button type='button' id='btn_add' class='btn_whiteinfo btn_green' onclick=\"document.location.href='dashboard.php'\"><svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='3'></circle><path d='M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z'></path></svg> + Settings</button>\n";
	}
	echo "		<input type='hidden' id='widget_order' name='widget_order' value='' />\n";
	echo "		</form>\n";
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

?>

<!-- Shared Wave Gradient Definition -->
<svg style="position: absolute; width: 0; height: 0; overflow: hidden;" aria-hidden="true">
  <defs>
    <linearGradient id="cardWaveGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#60a5fa" stop-opacity="0.04" />
      <stop offset="50%" stop-color="#a855f7" stop-opacity="0.09" />
      <stop offset="100%" stop-color="#f43f5e" stop-opacity="0.14" />
    </linearGradient>
  </defs>
</svg>

<style>

:root {
	--row-height: 72px;
	--grid-gap: 16px;
}

body {
	background-color: #f8fafc !important;
}

/* Action Bar */
.action_bar {
	display: flex !important;
	justify-content: space-between !important;
	align-items: center !important;
	padding: 16px 8px 24px 8px !important;
	border-bottom: none !important;
	background: transparent !important;
}

.whiteinfo_dash_heading {
	display: flex !important;
	align-items: center !important;
	gap: 14px !important;
}

.dash_header_bars {
	display: flex !important;
	align-items: flex-end !important;
	gap: 4px !important;
	height: 32px !important;
}

.dash_header_bars .bar {
	width: 5px !important;
	border-radius: 4px !important;
}

.dash_header_bars .bar-1 { height: 16px !important; background: #f87171 !important; }
.dash_header_bars .bar-2 { height: 32px !important; background: #38bdf8 !important; }
.dash_header_bars .bar-3 { height: 22px !important; background: #60a5fa !important; }
.dash_header_bars .bar-4 { height: 16px !important; background: #a855f7 !important; }

.dash_title_block {
	display: flex !important;
	flex-direction: column !important;
}

.dash_title {
	font-size: 26px !important;
	font-weight: 800 !important;
	color: #0f172a !important;
	line-height: 1.15 !important;
	letter-spacing: -0.02em !important;
}

.dash_subtitle {
	font-size: 13px !important;
	font-weight: 500 !important;
	color: #64748b !important;
	margin-top: 3px !important;
}

.whiteinfo_dash_actions {
	display: flex !important;
	align-items: center !important;
}

.whiteinfo_dash_actions form {
	display: flex !important;
	align-items: center !important;
	gap: 10px !important;
}

.btn_whiteinfo {
	display: inline-flex !important;
	align-items: center !important;
	gap: 7px !important;
	padding: 9px 18px !important;
	border-radius: 9px !important;
	font-size: 13.5px !important;
	font-weight: 600 !important;
	color: #ffffff !important;
	border: none !important;
	cursor: pointer !important;
	transition: all 0.15s ease !important;
	line-height: 1 !important;
	outline: none !important;
}

.btn_whiteinfo:hover {
	transform: translateY(-1px) !important;
	filter: brightness(1.05) !important;
}

.btn_purple {
	background: #6366F1 !important;
	box-shadow: 0 4px 12px rgba(99, 102, 241, 0.28) !important;
}

.btn_blue {
	background: #0284C7 !important;
	box-shadow: 0 4px 12px rgba(2, 132, 199, 0.28) !important;
}

.btn_green {
	background: #10B981 !important;
	box-shadow: 0 4px 12px rgba(16, 185, 129, 0.28) !important;
}

/* Grid */
.widgets {
	max-width: 100% !important;
	margin: 0 auto !important;
	display: grid !important;
	grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
	grid-gap: 16px 20px !important;
	padding: 0 4px 40px 4px !important;
}

.widget {
	padding: 0 !important;
	margin: 0 !important;
	border: none !important;
	background: transparent !important;
	cursor: pointer;
}

/* 18 Navigation Shortcut Cards */
.whiteinfo_card {
	background: #ffffff !important;
	height: 72px !important;
	min-height: 72px !important;
	border-radius: 16px !important;
	border: 1px solid rgba(226, 232, 240, 0.85) !important;
	box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03), 0 1px 3px rgba(15, 23, 42, 0.02) !important;
	position: relative !important;
	overflow: hidden !important;
	display: flex !important;
	align-items: center !important;
	padding: 0 !important;
	margin: 0 !important;
	cursor: pointer !important;
	transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s ease, border-color 0.2s ease !important;
}

.whiteinfo_card:hover {
	transform: translateY(-2px) !important;
	box-shadow: 0 10px 24px rgba(108, 92, 231, 0.10), 0 2px 6px rgba(0, 0, 0, 0.04) !important;
	border-color: rgba(168, 85, 247, 0.35) !important;
}

.card_wave_svg {
	position: absolute !important;
	right: 0 !important;
	bottom: 0 !important;
	width: 160px !important;
	height: 55px !important;
	pointer-events: none !important;
	z-index: 1 !important;
	border-bottom-right-radius: 16px !important;
}

.whiteinfo_card_content {
	display: flex !important;
	flex-direction: row !important;
	align-items: center !important;
	justify-content: space-between !important;
	width: 100% !important;
	height: 100% !important;
	padding: 0 20px 0 16px !important;
	position: relative !important;
	z-index: 2 !important;
	box-sizing: border-box !important;
}

.whiteinfo_icon_squircle {
	width: 46px !important;
	height: 46px !important;
	min-width: 46px !important;
	border-radius: 12px !important;
	display: flex !important;
	align-items: center !important;
	justify-content: center !important;
	box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08) !important;
	transition: transform 0.2s ease !important;
}

.whiteinfo_card:hover .whiteinfo_icon_squircle {
	transform: scale(1.05) !important;
}

.whiteinfo_card_title {
	margin-left: 16px !important;
	flex: 1 !important;
	font-size: 15.5px !important;
	font-weight: 700 !important;
	color: #0f172a !important;
	letter-spacing: -0.01em !important;
	text-align: left !important;
	white-space: nowrap !important;
	overflow: hidden !important;
	text-overflow: ellipsis !important;
}

.whiteinfo_card_arrow {
	display: flex !important;
	align-items: center !important;
	margin-left: 12px !important;
	transition: transform 0.2s ease !important;
}

.whiteinfo_card:hover .whiteinfo_card_arrow {
	transform: translateX(3px) !important;
}

.whiteinfo_card:hover .whiteinfo_card_arrow path {
	stroke: #6366F1 !important;
}

/* Bottom Cards (Registrations, Active Calls, System Status) */
.bottom_card {
	background: #ffffff !important;
	border-radius: 18px !important;
	border: 1px solid rgba(226, 232, 240, 0.85) !important;
	box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03), 0 1px 3px rgba(15, 23, 42, 0.02) !important;
	position: relative !important;
	overflow: hidden !important;
	padding: 16px 20px !important;
	display: flex !important;
	flex-direction: column !important;
	height: 185px !important;
	min-height: 185px !important;
	box-sizing: border-box !important;
	transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s ease !important;
}

.bottom_card:hover {
	transform: translateY(-2px) !important;
	box-shadow: 0 10px 24px rgba(108, 92, 231, 0.10), 0 2px 6px rgba(0, 0, 0, 0.04) !important;
}

.bottom_card_header {
	display: flex !important;
	align-items: center !important;
	position: relative !important;
	z-index: 2 !important;
	margin-bottom: 12px !important;
}

.bottom_card_icon_squircle {
	width: 34px !important;
	height: 34px !important;
	min-width: 34px !important;
	border-radius: 10px !important;
	display: flex !important;
	align-items: center !important;
	justify-content: center !important;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08) !important;
}

.bottom_card_title {
	margin-left: 12px !important;
	font-size: 16px !important;
	font-weight: 700 !important;
	color: #0f172a !important;
	letter-spacing: -0.01em !important;
}

.bottom_card_body {
	flex: 1 !important;
	display: flex !important;
	flex-direction: column !important;
	position: relative !important;
	z-index: 2 !important;
}

/* Registrations Body */
.registrations_body {
	align-items: center !important;
	justify-content: center !important;
}

.registration_pill_badge {
	background: #6366F1 !important;
	color: #ffffff !important;
	padding: 7px 28px !important;
	border-radius: 9999px !important;
	font-size: 15px !important;
	font-weight: 700 !important;
	display: inline-block !important;
	box-shadow: 0 4px 14px rgba(99, 102, 241, 0.35) !important;
	letter-spacing: 0.02em !important;
}

/* System Status Body */
.system_status_body {
	justify-content: center !important;
}

.system_metric_section {
	margin-bottom: 14px !important;
}
.system_metric_section:last-child {
	margin-bottom: 0 !important;
}

.system_metric_row {
	display: flex !important;
	justify-content: space-between !important;
	align-items: center !important;
	margin-bottom: 6px !important;
}

.system_metric_label {
	font-size: 13px !important;
	font-weight: 600 !important;
	color: #334155 !important;
}

.system_metric_value {
	font-size: 13px !important;
	font-weight: 700 !important;
	color: #0f172a !important;
}

.system_bar_track {
	width: 100% !important;
	height: 9px !important;
	background: #f1f5f9 !important;
	border-radius: 9999px !important;
	overflow: hidden !important;
}

.system_bar_fill {
	height: 100% !important;
	border-radius: 9999px !important;
	transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1) !important;
}

.system_bar_green {
	background: #10B981 !important;
}

.system_bar_blue {
	background: #0284C7 !important;
}

/* Active Calls Spline Chart */
.active_calls_chart_wrapper {
	display: flex !important;
	align-items: stretch !important;
	width: 100% !important;
	height: 100% !important;
	padding-top: 4px !important;
}

.active_calls_yaxis {
	display: flex !important;
	flex-direction: column !important;
	justify-content: space-between !important;
	font-size: 11px !important;
	font-weight: 600 !important;
	color: #94a3b8 !important;
	padding-right: 12px !important;
	padding-bottom: 2px !important;
	text-align: right !important;
	user-select: none !important;
	line-height: 1 !important;
}

.active_calls_svg_container {
	flex: 1 !important;
	position: relative !important;
	height: 100% !important;
	display: flex !important;
	align-items: center !important;
}

.active_calls_spline {
	width: 100% !important;
	height: 85px !important;
	overflow: visible !important;
}

/* System Status Value Badges */
.cpu_val_badge {
	background: #dcfce7 !important;
	color: #15803d !important;
	padding: 2px 8px !important;
	border-radius: 9999px !important;
	font-size: 12px !important;
	font-weight: 700 !important;
}

.disk_val_text {
	color: #0284c7 !important;
	font-size: 13px !important;
	font-weight: 700 !important;
}

.hud_details {
	display: none;
}

/* Expand / Collapse Button State Overrides */
#btn_expand[style*="none"],
#btn_contract[style*="none"],
.btn_whiteinfo[style*="none"] {
	display: none !important;
}

/* Responsive */
@media (max-width: 768px) {
	.widgets { grid-template-columns: repeat(1, minmax(0, 1fr)) !important; }
}

@media (min-width: 769px) and (max-width: 1199px) {
	.widgets { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
}

@media (min-width: 1200px) {
	.widgets { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
}

</style>

<script>

// Widget details expand button
document.addEventListener('click', function(event) {
	let hud_content = event.target.closest('.hud_content');
	let hud_expander = event.target.closest('.hud_expander');

	if (hud_content || hud_expander) {
		let widget = event.target.closest('div.widget, div.child_widget');

		if (widget && widget.classList.contains('details_disabled')) {
			return;
		}

		if (widget && widget.id) {
			toggle_grid_row_span(widget.id);
		}
	}
});

function toggle_grid_row_span(widget_id) {
	let widget = document.getElementById(widget_id);
	if (!widget) return;

	if (widget.classList.contains('details_expanded')) {
		widget.classList.remove('details_expanded');
	}
	else {
		widget.classList.add('details_expanded');
	}
}

let first_toggle = false;

function toggle_grid_row_span_all() {
	const widgets = document.querySelectorAll('div.widget');

	widgets.forEach(widget => {
		if (widget.classList.contains('details_disabled')) {
			return;
		}

		if (!first_toggle && widget.classList.contains('details_expanded')) {
			return;
		}

		if (widget.classList.contains('details_expanded') || widget.getAttribute('data-expanded-all') === 'true') {
			widget.classList.remove('details_expanded');
			widget.setAttribute('data-expanded-all', 'false');
		}
		else {
			widget.classList.add('details_expanded');
			widget.setAttribute('data-expanded-all', 'true');
		}
	});

	first_toggle = true;
}

</script>

<?php

// Define the global variables
	global $widget_chart_type;

// Function to check role permissions for dashboard widgets
	if (!function_exists('dashboard_permission_exists')) {
		function dashboard_permission_exists($perm) {
			if (if_group('superadmin')) {
				return true;
			}
			if (isset($_SESSION['permissions']) && is_array($_SESSION['permissions'])) {
				return !empty($_SESSION['permissions'][$perm]);
			}
			return permission_exists($perm);
		}
	}

	if (!function_exists('dashboard_widget_has_permission')) {
		function dashboard_widget_has_permission($widget_name, $widget_path = '', $widget_url = '') {
			$name = strtolower(trim($widget_name));
			$url = strtolower(trim($widget_url));
			$path = strtolower(trim($widget_path));

			// Superadmin has full access to everything
			if (if_group('superadmin')) {
				return true;
			}

			// Call Detail Records / Recent Calls / Missed Calls
			if (strpos($name, 'call detail') !== false || strpos($name, 'recent call') !== false || strpos($name, 'missed call') !== false || strpos($url, 'xml_cdr') !== false) {
				return dashboard_permission_exists('xml_cdr_view');
			}

			// Conference Centers
			if (strpos($name, 'conference center') !== false || strpos($url, 'conference_center') !== false) {
				return dashboard_permission_exists('conference_center_view') || dashboard_permission_exists('conference_room_view');
			}

			// Conferences
			if (strpos($name, 'conference') !== false || strpos($url, 'conference') !== false) {
				return dashboard_permission_exists('conference_view');
			}

			// Contacts
			if (strpos($name, 'contact') !== false || strpos($url, 'contact') !== false) {
				return dashboard_permission_exists('contact_view');
			}

			// Destinations
			if (strpos($name, 'destination') !== false || strpos($url, 'destination') !== false) {
				return dashboard_permission_exists('destination_view');
			}

			// Devices / Device Keys
			if (strpos($name, 'device') !== false || strpos($url, 'device') !== false) {
				return dashboard_permission_exists('device_view');
			}

			// Event Guard
			if (strpos($name, 'event guard') !== false || strpos($url, 'event_guard') !== false) {
				return dashboard_permission_exists('event_guard_log_view');
			}

			// Extensions
			if (strpos($name, 'extension') !== false || strpos($url, 'extension') !== false) {
				return dashboard_permission_exists('extension_view');
			}

			// Follow Me / Call Forward
			if (strpos($name, 'follow me') !== false || strpos($name, 'call forward') !== false || strpos($url, 'call_forward') !== false) {
				return dashboard_permission_exists('follow_me_view') || dashboard_permission_exists('call_forward_view');
			}

			// IVR Menus
			if (strpos($name, 'ivr') !== false || strpos($url, 'ivr_menu') !== false) {
				return dashboard_permission_exists('ivr_menu_view');
			}

			// Recordings
			if (strpos($name, 'recording') !== false || strpos($url, 'recording') !== false) {
				return dashboard_permission_exists('recording_view');
			}

			// Ring Groups
			if (strpos($name, 'ring group') !== false || strpos($url, 'ring_group') !== false) {
				return dashboard_permission_exists('ring_group_view');
			}

			// Time Conditions
			if (strpos($name, 'time condition') !== false || strpos($url, 'time_condition') !== false) {
				return dashboard_permission_exists('time_condition_view');
			}

			// User Profile
			if (strpos($name, 'user profile') !== false || strpos($url, 'user_profile') !== false) {
				return dashboard_permission_exists('user_profile_view') || !empty($_SESSION['username']);
			}

			// Users
			if (strpos($name, 'users') !== false || strpos($url, 'core/users/users') !== false) {
				return dashboard_permission_exists('user_view');
			}

			// Voicemails / New Messages
			if (strpos($name, 'voicemail') !== false || strpos($name, 'new message') !== false || strpos($url, 'voicemail') !== false) {
				return dashboard_permission_exists('voicemail_view') || dashboard_permission_exists('voicemail_message_view');
			}

			// Logout
			if (strpos($name, 'logout') !== false || strpos($url, 'logout') !== false) {
				return !empty($_SESSION['username']);
			}

			// Domains
			if (strpos($name, 'domain') !== false || strpos($url, 'domain') !== false) {
				return dashboard_permission_exists('domain_view');
			}

			// Registrations
			if (strpos($name, 'registration') !== false || strpos($path, 'registration') !== false) {
				return dashboard_permission_exists('registration_view') || dashboard_permission_exists('extension_view');
			}

			// Active Calls
			if (strpos($name, 'active call') !== false || strpos($path, 'active_call') !== false) {
				return dashboard_permission_exists('call_active_view');
			}

			// System Status / CPU / Disk
			if (strpos($name, 'system') !== false || strpos($path, 'system') !== false) {
				return dashboard_permission_exists('system_view_info') || dashboard_permission_exists('system_status_view') || dashboard_permission_exists('system_view_cpu');
			}

			// Fax
			if (strpos($name, 'fax') !== false || strpos($url, 'fax') !== false) {
				return dashboard_permission_exists('fax_view');
			}

			// Call Block
			if (strpos($name, 'call block') !== false || strpos($url, 'call_block') !== false) {
				return dashboard_permission_exists('call_block_view');
			}

			// Call Center
			if (strpos($name, 'call center') !== false || strpos($url, 'call_center') !== false) {
				return dashboard_permission_exists('call_center_agent_view') || dashboard_permission_exists('call_center_queue_view');
			}

			return true;
		}
	}

//include the widgets
	echo "<div class='widgets' id='widgets'>\n";
	$x = 0;
	foreach ($widgets as $row) {
		//skip child widgets unless the parent doesn't exist
		if (!empty($row['dashboard_widget_parent_uuid']) && in_array($row['dashboard_widget_parent_uuid'], $widget_uuid_list)) {
			continue;
		}

		//set the variables
		$widget_uuid = $row['dashboard_widget_uuid'] ?? '';
		$widget_name = $row['widget_name'] ?? '';
		$widget_icon = $row['widget_icon'] ?? '';
		$widget_url = $row['widget_url'] ?? '';
		$widget_target = $row['widget_target'] ?? '';
		$widget_width = $row['widget_width'] ?? '';
		$widget_height = $row['widget_height'] ?? '';
		$widget_content = $row['widget_content'] ?? '';
		$widget_content_text_align = $row['widget_content_text_align'] ?? '';
		$widget_content_details = $row['widget_content_details'] ?? '';
		$widget_chart_type = $row['widget_chart_type'] ?? '';
		$widget_label_text_color = $row['widget_label_text_color'] ?? $settings->get('theme', 'dashboard_label_text_color', '');
		$widget_number_text_color = $row['widget_number_text_color'] ?? $settings->get('theme', 'dashboard_number_text_color', '');
		$widget_number_background_color = $row['widget_number_background_color'] ?? $settings->get('theme', 'dashboard_number_background_color', '');
		$widget_details_state = $row['widget_details_state'] ?? 'disabled';
		$widget_row_span = $row['widget_row_span'] ?? '';

		//define the regex patterns
		$uuid_pattern = '/[^-A-Fa-f0-9]/';
		$number_pattern = '/[^-A-Za-z0-9()*#]/';
		$text_pattern = '/[^a-zA-Z0-9 _\-\/.\?:\=#\n]/';

		//sanitize the data
		$widget_uuid = preg_replace($uuid_pattern, '', $widget_uuid);
		$widget_id = 'id_'.md5($widget_uuid);
		$widget_name = trim(preg_replace($text_pattern, '', $widget_name));
		$widget_icon = preg_replace($text_pattern, '', $widget_icon);
		$widget_url = trim(preg_replace($text_pattern, '', $widget_url));
		$widget_target = trim(preg_replace($text_pattern, '', $widget_target));
		$widget_width = trim(preg_replace($text_pattern, '', $widget_width));
		$widget_height = trim(preg_replace($text_pattern, '', $widget_height));
		$widget_content = preg_replace($text_pattern, '', $widget_content);
		$widget_content = str_replace("\n", '<br />', $widget_content);
		$widget_content_text_align = trim(preg_replace($text_pattern, '', $widget_content_text_align));
		$widget_content_details = preg_replace($text_pattern, '', $widget_content_details);
		$widget_content_details = str_replace("\n", '<br />', $widget_content_details);
		$widget_chart_type = preg_replace($text_pattern, '', $widget_chart_type);
		$widget_label_text_color = preg_replace($text_pattern, '', $widget_label_text_color);
		$widget_number_text_color = preg_replace($text_pattern, '', $widget_number_text_color);
		$widget_number_background_color = preg_replace($text_pattern, '', $widget_number_background_color);
		$widget_details_state = preg_replace($text_pattern, '', $widget_details_state);
		$widget_row_span = preg_replace($number_pattern, '', $widget_row_span);
		$widget_path = preg_replace($text_pattern, '', strtolower($row['widget_path']));

		//find the application and widget
		$widget_path_array = explode('/', $widget_path);
		$application_name = $widget_path_array[0];
		$widget_path_name = $widget_path_array[1];
		$path_array = glob(dirname(__DIR__, 2).'/*/'.$application_name.'/resources/dashboard/'.$widget_path_name.'.php');

		//check role permissions for the widget
		if (!dashboard_widget_has_permission($widget_name, $widget_path, $widget_url)) {
			continue;
		}

		// Widget class
		$widget_class = ($widget_path == 'dashboard/parent' ? 'parent_widget' : 'widget');

		echo "<div class='".$widget_class." details_".$widget_details_state." draggable' id='".$widget_id."' draggable='false'>\n";
		if (!empty($path_array[0]) && file_exists($path_array[0])) {
			include $path_array[0];
		}
		echo "</div>\n";

		$x++;
	}
	echo "</div>\n";

//begin edit
	if (permission_exists('dashboard_edit')) {
		?>

		<style>
		[draggable] {
			-moz-user-select: none;
			-khtml-user-select: none;
			-webkit-user-select: none;
			user-select: none;
		}

		.editable {
  			position: relative;
			cursor: grab;
		}

		.hud_box.editable {
			transition: 0.2s;
			border: 1px dashed rgba(0,0,0,0.3);
		}

		.hud_box.editable:hover {
			box-shadow: 0 5px 10px rgba(0,0,0,0.2);
			border: 1px dashed rgba(0,0,0,0.3);
			transition: 0.2s;
		}

		.widget > .hud_box.editable:hover {
			transform: scale(1.03, 1.03);
		}

		.hud_box .hud_box.editable:hover {
			box-shadow: none;
			transform: none;
		}

		.ghost {
			border: 2px dashed rgba(0,0,0,1);
			border-radius: 16px;
			opacity: 0.2;
		}

		.widget_edit_actions {
			z-index: 999;
			position: absolute;
			top: 0;
			right: 0;
			background: #ffffff75;
			backdrop-filter: blur(5px);
			border-bottom-left-radius: 5px;
			overflow: hidden;
			color: #000000aa;
		}

		.widget_edit_actions i {
			padding: 8px 12px;
			background: #00000020;
			font-size: 16px;
		}
		</style>

		<script>
		const widgets = document.getElementById('widgets');
		let sortable;

		function edit_mode(state) {
			if (state == 'on') {
				$('span#expand_contract, #btn_edit, #btn_add').hide();
				$('.hud_box').addClass('editable');
				$('#btn_back, #btn_save').show();
				$('div.widget, div.parent_widget').attr('draggable',true).addClass('editable');
				$('div.child_widget').attr('draggable',true).addClass('editable');

				document.querySelectorAll('.parent_widget > .hud_box').forEach(widget => {
					const container = document.createElement('div');
					container.className = 'widget_edit_actions';
					const grip_icon = document.createElement('i');
					grip_icon.className = 'fa-solid fa-grip-lines';
					container.appendChild(grip_icon);
					widget.prepend(container);
				});

				sortable = Sortable.create(widgets, {
					group: {
						name: 'shared',
						pull: function(to, from, dragEl) {
							return !dragEl.classList.contains('parent_widget');
						},
						put: true,
					},
					animation: 150,
					draggable: '.draggable',
					preventOnFilter: true,
					ghostClass: 'ghost',
					onSort: update_widget_order,
					onAdd: function (event) {
						event.item.classList.add('widget');
						update_widget_order();
					},
					onRemove: function (event) {
						event.item.classList.remove('widget');
						update_widget_order();
					},
					onMove: function (event) {
						if (event.to !== event.from) {
							event.dragged.classList.remove('widget');
						} else {
							event.dragged.classList.add('widget');
						}
					},
				});

				function update_widget_order() {
					let widget_ids_list = [];
					let order = 10;

					widgets.querySelectorAll(':scope > div.widget[id], :scope > div.parent_widget[id]').forEach(widget => {
						const widget_id = widget.id;
						widget_ids_list.push(`${widget_id}|null|${order}`);
						order += 10;
					});

					document.getElementById('widget_order').value = widget_ids_list;
				}
			}
			else {
				$('div.widget, div.parent_widget, div.child_widget').attr('draggable',false).removeClass('editable');
				$('.hud_box').removeClass('editable');
				$('#btn_back, #btn_save').hide();
				$('span#expand_contract, #btn_edit, #btn_add').show();

				document.querySelectorAll('.widget_edit_actions').forEach(element => element.remove());

				if (sortable) {
					sortable.option('disabled', true);
				}
			}
		}
		</script>
		<?php
	}

//show the footer
	require_once "resources/footer.php";

?>
