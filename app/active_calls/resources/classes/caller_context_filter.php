<?php

/*
 * FusionPBX
 * Version: MPL 1.1
 *
 * The contents of this file are subject to the Mozilla Public License Version
 * 1.1 (the "License"); you may not use this file except in compliance with
 * the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS" basis,
 * WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
 * for the specific language governing rights and limitations under the
 * License.
 *
 * The Original Code is FusionPBX
 *
 * The Initial Developer of the Original Code is
 * Mark J Crane <markjcrane@fusionpbx.com>
 * Portions created by the Initial Developer are Copyright (C) 2008-2025
 * the Initial Developer. All Rights Reserved.
 *
 * Contributor(s):
 * Mark J Crane <markjcrane@fusionpbx.com>
 * Tim Fry <tim@fusionpbx.com>
 */

/**
 * Description of caller_context_filter
 *
 * Multi-domain aware filter ensuring active calls only reach subscribers of that domain
 */
class caller_context_filter implements filter {

	private $domains;

	/**
	 * Initializes the object with an array of domain names.
	 *
	 * @param array $domain_names Array of domain names to initialize the object with.
	 *
	 * @return void
	 */
	public function __construct(array $domain_names) {
		$this->domains = [];
		foreach ($domain_names as $name) {
			if (!empty($name)) {
				$this->domains[strtolower(trim($name))] = true;
			}
		}
	}

	/**
	 * Invokes this method as a callable.
	 *
	 * @param string $key   The event key, which is used for validation and filtering.
	 * @param mixed  $value The value associated with the event key, used to determine
	 *                      whether the filter chain should drop the payload.
	 *
	 * @return bool|null Returns true if valid, null to drop payload.
	 */
	public function __invoke(string $key, $value): ?bool {
		if (empty($this->domains)) {
			return true;
		}

		// 1. Check caller_context
		if ($key === "caller_context") {
			$val = strtolower(trim((string)$value));
			if (!empty($val) && $val !== "public" && $val !== "default") {
				if (strpos($val, "@") !== false) {
					$val = explode("@", $val)[1];
				}
				if (!isset($this->domains[$val])) {
					return null; // Drop calls from other domains
				}
			}
			return true;
		}

		// 2. Check channel_presence_id (e.g. 101@domain.com)
		if ($key === "channel_presence_id" && is_string($value) && strpos($value, "@") !== false) {
			$parts = explode("@", strtolower(trim($value)));
			$domain = $parts[1] ?? "";
			if (!empty($domain) && !isset($this->domains[$domain])) {
				return null; // Drop calls for extensions in other domains
			}
			return true;
		}

		// 3. Check variable_domain_name or domain_name
		if (($key === "variable_domain_name" || $key === "domain_name") && is_string($value)) {
			$val = strtolower(trim($value));
			if (!empty($val) && !isset($this->domains[$val])) {
				return null; // Drop calls explicitly tagged with other domain
			}
			return true;
		}

		return true;
	}

}
