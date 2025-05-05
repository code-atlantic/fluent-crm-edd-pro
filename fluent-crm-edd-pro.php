<?php
/**
 * Plugin Name: FluentCRM - EDD Pro
 * Plugin URI: https://github.com/code-atlantic/fluent-crm-edd-pro
 * Description:
 * Version: 1.0.0
 * Author: Code Atlantic LLC
 * Author URI: https://code-atlantic.com/
 * License:
 * License URI:

 * Minimum PHP: 7.4
 * Minimum WP: 6.2
 *
 * @package   FluentCRM\EDDPro
 * @author    Code Atlantic
 * @copyright Copyright (c) 2025, Code Atlantic LLC.
 */

// Register autoloader.
require_once __DIR__ . '/vendor/autoload.php';

add_action(
	'init',
	function () {
		( new \FluentCRM\EDDPro\EDDSubscriptionRules() )->register();
	},
	99
);
