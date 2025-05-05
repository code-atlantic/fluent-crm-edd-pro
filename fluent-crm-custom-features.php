<?php

/**
 * Plugin Name: FluentCRM - Custom events, actions and conditionals.
 * Plugin URI: https://github.com/danieliser/fluent-crm-json-events
 * Description:
 * Version:
 * Author: Code Atlantic LLC
 * Author URI: https://code-atlantic.com/
 * License:
 * License URI:

 * Minimum PHP: 7.4
 * Minimum WP: 6.2
 *
 * @package    FluentCRM\CustomFeatures
 * @author     Code Atlantic
 * @copyright  Copyright (c) 2024, Code Atlantic LLC.
 */

use function Crontrol\get_hook_callbacks;

// Register autoloader.
require_once __DIR__ . '/vendor/autoload.php';

add_action(
	'init',
	function () {
		( new \CustomCRM\JSONEventTrackingHandler() )->register();
		// ( new \CustomCRM\EDDSubscriptionRules() )->register();
		( new \CustomCRM\Actions\RandomWaitTimeAction() )->register();

		// Remove the default update contact property action (broken).
		remove_all_actions( 'fluentcrm_funnel_sequence_handle_update_contact_property' );
		// Register our custom update contact property action.
		( new \CustomCRM\Actions\UpdateContactPropertyAction() )->register();

		// Enable our custom webhook handler.
		( new \CustomCRM\Webhooks() );

		// Remove the default smart link handler.
		remove_all_actions( 'fluentcrm_smartlink_clicked' );
		remove_all_actions( 'fluentcrm_smartlink_clicked_direct' );
		// Register our custom smart link handler.
		$fixSmartLinkRedirects = new \CustomCRM\SmartLinkHandler();

		add_action( 'fluentcrm_smartlink_clicked', [ $fixSmartLinkRedirects, 'handleClick' ], 9, 1 );
		add_action( 'fluentcrm_smartlink_clicked_direct', [ $fixSmartLinkRedirects, 'handleClick' ], 9, 2 );
	},
	99
);


add_action(
	'plugins_loaded',
	function () {
		( new \CustomCRM\EDDSubscriptionRules() )->register();
	},
	99
);

// Hook to add custom dashboard metrics
// add_action( 'fluent_crm/dashboard_stats', 'add_custom_dashboard_metrics' );

function add_custom_dashboard_metrics( $data ) {
	// Example: Adding a new metric for total subscribers.
	$totalSubscribers = \FluentCrm\App\Models\Subscriber::count();

	$data['total_subscribers_metric'] = [
		'title' => __( 'Total Subscribers', 'fluent-crm' ),
		'count' => $totalSubscribers,
		'route' => [
			'name' => 'subscribers',
		],
	];

	// Add more metrics as needed
	return $data;
}

// Hook to register a custom report
add_action( 'fluent_crm/reporting/reports', 'register_custom_report' );

function register_custom_report( $reports ) {
	$reports['custom_report'] = [
		'title'    => __( 'Custom Report', 'fluent-crm' ),
		'callback' => 'render_custom_report',
	];

	return $reports;
}

function render_custom_report() {
	// Logic to render your custom report
	$subscribers = \FluentCrm\App\Models\Subscriber::all();
	// Output your report data here
	echo '<h2>' . __( 'Custom Report', 'fluent-crm' ) . '</h2>';
	echo '<ul>';
	foreach ( $subscribers as $subscriber ) {
		echo '<li>' . esc_html( $subscriber->email ) . '</li>';
	}
	echo '</ul>';
}

// Hook to register a custom REST API endpoint
add_action('rest_api_init', function () {
	register_rest_route('fluent-crm/v1', '/list-growth', [
		'methods'             => 'GET',
		'callback'            => 'get_list_growth',
		'permission_callback' => '__return_true',
	]);
});

/**
 * Get List Growth metrics
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function get_list_growth( WP_REST_Request $request ) {
	$from = $request->get_param( 'from' );
	$to   = $request->get_param( 'to' );

	// Convert dates to Carbon instances
	$fromDate = \Carbon\Carbon::parse( $from );
	$toDate   = \Carbon\Carbon::parse( $to );

	// Count new subscribers
	$newSubscribers = fluentCrmDb()->table( 'fc_subscribers' )
		->whereBetween( 'created_at', [ $fromDate->format( 'Y-m-d' ), $toDate->format( 'Y-m-d' ) ] )
		->where( 'status', 'subscribed' )
		->count();

	// Count unsubscribed
	$unsubscribed = fluentCrmDb()->table( 'fc_subscriber_meta' )
		->whereBetween( 'created_at', [ $fromDate->format( 'Y-m-d' ), $toDate->format( 'Y-m-d' ) ] )
		->where( 'key', 'unsubscribe_reason' )
		->count();

	// Calculate net growth
	$netGrowth = $newSubscribers - $unsubscribed;

	return new WP_REST_Response([
		'new_subscribers' => $newSubscribers,
		'unsubscribed'    => $unsubscribed,
		'net_growth'      => $netGrowth,
	], 200);
}


// Hook to add custom metrics to the dashboard.
add_filter( 'fluent_crm/dashboard_data', 'add_custom_dashboard_metrics_for_list_growth' );

/**
 * Add custom dashboard metrics for list growth.
 *
 * @param array $data Existing dashboard data.
 * @return array Modified dashboard data with list growth metrics.
 */
function add_custom_dashboard_metrics_for_list_growth( $data ) {
	// Get the date range from the request or set default values.
	$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y-m-01' );
	$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : gmdate( 'Y-m-t' );

	// Calculate new subscribers and unsubscribes.
	$new_subscribers = fluentCrmDb()->table( 'fc_subscribers' )
		->whereBetween( 'created_at', [ $from, $to ] )
		->where( 'status', 'subscribed' )
		->count();

	$unsubscribed = fluentCrmDb()->table( 'fc_subscriber_meta' )
		->whereBetween( 'created_at', [ $from, $to ] )
		->where( 'key', 'unsubscribe_reason' )
		->count();

	// Calculate net growth.
	$net_growth = $new_subscribers - $unsubscribed;

	// Add the new metrics to the dashboard data.
	$data['list_growth'] = [
		'title'           => __( 'List Growth', 'bricks-child' ),
		'new_subscribers' => $new_subscribers,
		'unsubscribed'    => $unsubscribed,
		'net_growth'      => $net_growth,
	];

	return $data;
}
