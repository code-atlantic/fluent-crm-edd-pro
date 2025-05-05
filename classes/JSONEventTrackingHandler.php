<?php
/**
 * Description: FluentCRM - JSON Events
 * Author: Code Atlantic LLC
 * Author URI: https://code-atlantic.com/
 *
 * @package FluentCRM\CustomFeatures
 */

namespace CustomCRM;

use FluentCrm\App\Models\EventTracker;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\ContactsQuery;

if ( defined( 'FLUENTCAMPAIGN_DIR_FILE' ) ) {
	return;
}

/**
 * Class JSONEventTrackingHandler
 *
 * @package CustomCRM
 */
class JSONEventTrackingHandler {

	/**
	 * Register.
	 *
	 * @return void
	 */
	public function register() {
		// Handle AJAX Property Name Lookups.
		add_filter( 'fluentcrm_ajax_options_event_tracking_json_props', [ $this, 'getEventTrackingPropsOptions' ], 10, 1 );

		// Apply conditional event rules.
		add_filter( 'fluentcrm_contacts_filter_event_tracking', [ $this, 'applyEventTrackingFilter' ], 10, 2 );

		// Show JSON event widget.
		add_filter( 'fluent_crm/subscriber_info_widgets', [ $this, 'addSubscriberInfoWidgets' ], 11, 2 );
		add_filter( 'fluent_crm/subscriber_info_widget_event_tracking', [ $this, 'addSubscriberInfoWidgets' ], 11, 2 );

		// Add custom rule types.
		add_filter( 'fluentcrm_advanced_filter_options', [ $this, 'addEventTrackingFilterOptions' ], 11, 1 );
		add_filter( 'fluent_crm/event_tracking_condition_groups', [ $this, 'addEventTrackingConditionOptions' ], 11, 1 );

		// Remove.
		add_filter( 'fluentcrm_automation_conditions_assess_event_tracking_objects', [ $this, 'assessEventObjectTrackingConditions' ], 10, 3 );
		add_filter( 'fluentcrm_contacts_filter_event_tracking_objects', [ $this, 'applyEventTrackingFilter' ], 10, 2 );
	}

	/**
	 * Get event tracking props options.
	 *
	 * @param array<string,mixed> $options Options.
	 *
	 * @return array<int<0,max>,array<string,string>>
	 */
	public function getEventTrackingPropsOptions( $options = [] ) {
		$event_key = Arr::get( $options, 'event_key' );

		$rows = EventTracker::select( [ 'value', 'event_key', 'title' ] )
			->groupBy( 'event_key' )
			->orderBy( 'event_key', 'DESC' )
			->get();

		$formattedItems = [];

		$unique_props = [];

		// Check each row for a value that is a json object.
		// If it is, then we need to parse it and return the propnames and types of values.
		foreach ( $rows as $row ) {
			$title     = $row->getAttribute( 'title' );
			$value     = $row->getAttribute( 'value' );
			$event_key = $row->getAttribute( 'event_key' );

			$value = json_decode( $value, true );

			if ( ! is_object( $value ) && ! is_array( $value ) ) {
				continue;
			}

			foreach ( $value as $propName => $propValue ) {
				$key = $event_key . ':' . $propName;

				if ( ! isset( $unique_props[ $key ] ) ) {
					$type = gettype( $propValue );

					if ( is_numeric( $propValue ) ) {
						$type = is_int( $propValue ) ? 'int' : 'float';
					}
					if ( is_bool( $propValue ) ) {
						$type = 'bool';
					}
					if ( is_null( $propValue ) ) {
						$type = 'null';
					}
					if ( is_string( $propValue ) ) {
						$object_test = json_decode( $propValue );
						if ( is_object( $object_test ) ) {
							$type = 'object';
						}
					}

					$unique_props[ $key ] = sprintf(
						'%s: %s (%s)',
						$title,
						$propName,
						$type
					);
				}
			}
		}

		foreach ( $unique_props as $key => $value ) {
			$formattedItems[] = [
				'id'    => $key,
				'title' => $value,
			];
		}

		return $formattedItems;
	}

	/**
	 * Assess event object tracking conditions.
	 *
	 * @param bool                $passes Whether the conditions are passed.
	 * @param array<string,mixed> $conditions Conditions.
	 * @param Subscriber          $subscriber Subscriber.
	 *
	 * @return bool
	 */
	public static function assessEventObjectTrackingConditions( $passes, $conditions, $subscriber ) {
		if ( ! Helper::isExperimentalEnabled( 'event_tracking' ) ) {
			return false;
		}

		$hasSubscriber = Subscriber::where( 'id', $subscriber->id )->where(
			function ( $q ) use ( $conditions ) {
				do_action_ref_array( 'fluentcrm_contacts_filter_event_tracking_objects', [ &$q, $conditions ] );
			}
		)->first();

		return (bool) $hasSubscriber;
	}

	/**
	 * Apply event tracking filter.
	 *
	 * @param \ContactsQuery      $query Query object.
	 * @param array<string,mixed> $filters Filters.
	 *
	 * @return \ContactsQuery
	 */
	public function applyEventTrackingFilter( $query, $filters ) {
		global $wpdb;

		if ( ! Helper::isExperimentalEnabled( 'event_tracking' ) ) {
			return $query;
		}

		foreach ( $filters as $filter ) {
			if ( empty( $filter['value'] ) && '' === $filter['value'] ) {
				continue;
			}

			$relation = 'trackingEvents';

			$filterProp = $filter['property'];

			if ( 'event_tracking_json_prop' === $filterProp || 'event_tracking_object_prop' === $filterProp ) {
				$eventPropKey = Arr::get( $filter, 'extra_value' );

				$key = explode( ':', $eventPropKey );

				$eventKey = $key[0];
				$propName = $key[1];
				$propType = isset( $key[2] ) ? $key[2] : 'string';

				if ( ! $eventKey ) {
					continue;
				}

				switch ( $propType ) {
					case 'int':
						// $query->whereRaw('value', [200])
				}

				$operator = $filter['operator'];

				if ( doing_action( 'fluent_crm/event_tracked' ) ) {
					// We only care about the latest event.
					$subquery = "(
						SELECT JSON_EXTRACT(`value`, '$.{$propName}')
						FROM `{$wpdb->prefix}fc_event_tracking`
						WHERE `subscriber_id` = `{$wpdb->prefix}fc_subscribers`.`id`
							AND `event_key` = '{$eventKey}'
						ORDER BY `created_at` DESC
						LIMIT 1
					)";

					if ( '=' === $operator ) {
						$query->whereRaw( "{$subquery} = ?", [ (float) $filter['value'] ] );
					} elseif ( '!=' === $operator ) {
						$query->whereRaw( "{$subquery} != ?", [ (float) $filter['value'] ] );
					} elseif ( in_array( $operator, [ '<', '>' ], true ) ) {
						$query->whereRaw( "{$subquery} {$operator} ?", [ (float) $filter['value'] ] );
					} elseif ( 'contains' === $operator ) {
						$escapedValue = $wpdb->esc_like( $filter['value'] );
						$query->whereRaw( "{$subquery} LIKE ?", [ '%' . $escapedValue . '%' ] );
					} elseif ( 'not_contains' === $operator ) {
						$escapedValue = $wpdb->esc_like( $filter['value'] );
						$query->whereRaw( "{$subquery} NOT LIKE ?", [ '%' . $escapedValue . '%' ] );
					}
				} elseif ( '=' === $operator ) {
						$query->whereHas(
							$relation, function ( $q ) use (
								$filter,
								$eventKey,
								$propName,
							) {
								$q
									->where(
										'event_key',
										$eventKey
									)
									->whereRaw( "JSON_EXTRACT(`value`, '$.{$propName}') = ?", [ (float) $filter['value'] ] );
							}
						);
				} elseif ( '!=' === $operator ) {
					$query->whereDoesntHave(
						$relation, function ( $q ) use (
							$filter,
							$eventKey,
							$propName,
						) {
							$q
								->where(
									'event_key',
									$eventKey
								)
								->whereRaw( "JSON_EXTRACT(`value`, '$.{$propName}') = ?", [ (float) $filter['value'] ] );
						}
					);
				} elseif ( in_array( $operator, [ '<', '>' ], true ) ) {
					$query->whereHas(
						$relation, function ( $q ) use (
							$filter,
							$eventKey,
							$propName,
							$operator,
						) {
							$q
								->where( 'event_key', $eventKey )
								->whereRaw( "JSON_EXTRACT(`value`, '$.{$propName}') {$operator} ?", [ (float) $filter['value'] ] );
						}
					);
				} elseif ( 'contains' === $operator ) {
					$query->whereHas(
						$relation, function ( $q ) use (
							$filter,
							$eventKey,
							$propName,
							$wpdb,
						) {
							$escapedValue = $wpdb->esc_like( $filter['value'] );

							$q
								->where( 'event_key', $eventKey )
								->whereRaw( "JSON_EXTRACT(`value`, '$.{$propName}') LIKE '%{$escapedValue}%'" );
						}
					);
				} elseif ( 'not_contains' === $operator ) {
						$query->whereDoesntHave(
							$relation, function ( $q ) use (
								$filter,
								$eventKey,
								$propName,
								$wpdb,
							) {
								$escapedValue = $wpdb->esc_like( $filter['value'] );

								$q
									->where( 'event_key', $eventKey )
									->whereRaw( "JSON_EXTRACT(`value`, '$.{$propName}') LIKE '%{$escapedValue}%'" );
							}
						);
						break;
				}
			}
		}

		return $query;
	}

	/**
	 * Add subscriber info widgets.
	 *
	 * @param array<string,mixed> $widgets Widgets.
	 * @param Subscriber          $subscriber Subscriber.
	 *
	 * @return array<string,mixed>
	 */
	public function addSubscriberInfoWidgets( $widgets, $subscriber ) {
		if ( ! Helper::isExperimentalEnabled( 'event_tracking' ) ) {
			return $widgets;
		}

		$events = EventTracker::where( 'subscriber_id', $subscriber->id )
			->orderBy( 'updated_at', 'DESC' )
			->paginate();

		if ( $events->isEmpty() ) {
			return $widgets;
		}

		$html = '<div class="fc_scrolled_lists"><ul class="fc_full_listed fc_event_tracking_lists">';

		foreach ( $events as $event ) {
			$html .= '<li>';
			$html .= '<div class="el-badge"><p class="fc_type">' . esc_attr( $event->event_key ) . '</p><sup class="el-badge__content is-fixed">' . $event->counter . '</sup></div>';
			$html .= '<p class="fl_event_title"><b>' . esc_html( $event->title ) . '</b></p>';

			if ( $event->value ) {
				$object = json_decode( $event->value );

				if ( ! is_object( $object ) ) {
					$html .= '<p class="fc_value">' . wp_kses_post( $event->value ) . '</p>';
				} else {
					// Foreach property of the object.
					foreach ( $object as $key => $value ) {
						$html .= '<p class="fc_value"><strong>' . esc_html( $key ) . ':</strong> ' . wp_kses_post( $value ) . '</p>';
					}
				}
			}
			$html .= '<span class="fc_date">' . $event->updated_at . '</span>';
			$html .= '</li>';
		}
		$html .= '</ul></div>';

		$widgets['event_tracking_json'] = [
			'title'          => __( 'Event Tracking (JSON)', 'fluent-crm' ),
			'content'        => $html,
			'has_pagination' => $events->total() > $events->perPage(),
			'total'          => $events->total(),
			'per_page'       => $events->perPage(),
			'current_page'   => $events->currentPage(),
		];

		return $widgets;
	}

	/**
	 * Add event tracking filter options.
	 *
	 * @param array<string,mixed> $groups Groups.
	 *
	 * @return array<string,mixed>
	 */
	public function addEventTrackingFilterOptions( $groups ) {
		if ( ! Helper::isExperimentalEnabled( 'event_tracking' ) ) {
			return $groups;
		}

		foreach ( $groups as $key => $group ) {
			if ( 'event_tracking' === $key ) {
				$groups[ $key ]['children'] = array_merge( $group['children'], $this->getConditionItems() );
				break;
			}
		}

		return $groups;
	}

	/**
	 * Add the event tracking condition options.
	 *
	 * @param array<int<0,max>,array<string,mixed>> $items The condition items.
	 * @return array<int<0,max>,array<string,mixed>>
	 */
	public function addEventTrackingConditionOptions( $items ) {
		if ( ! Helper::isExperimentalEnabled( 'event_tracking' ) ) {
			return $items;
		}

		foreach ( $items as $key => $item ) {
			if ( 'event_tracking' === $item['value'] ) {
				$items[ $key ]['children'] = array_merge( $item['children'], $this->getConditionItems() );
				break;
			}
		}

		return $items;
	}

	/**
	 * Get the event tracking condition items.
	 *
	 * @return array<int<0,max>,array<string,mixed>>
	 */
	private function getConditionItems() {
		return [
			[
				'label'            => __( 'Event JSON Prop', 'fluent-crm' ),
				'value'            => 'event_tracking_json_prop',
				'type'             => 'composite_optioned_compare',
				'help'             => 'The compare value will be matched with selected event & last recorded value of the selected event prop',
				'ajax_selector'    => [
					'label'              => 'For Event JSON Prop',
					'option_key'         => 'event_tracking_json_props',
					'experimental_cache' => true,
					'is_multiple'        => false,
					'placeholder'        => 'Select Event JSON Prop',
				],
				'value_config'     => [
					'label'       => 'Compare Value',
					'type'        => 'input_text',
					'placeholder' => 'Prop Value',
					'data_type'   => 'string',
				],
				'custom_operators' => [
					'='            => 'Equal',
					'!='           => 'Not equal',
					'contains'     => 'Contains',
					'not_contains' => 'Does not contain',
					'>'            => 'Greater than',
					'<'            => 'Less than',
				],
			],
		];
	}
}
