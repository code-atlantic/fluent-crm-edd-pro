<?php
/**
 * Description: FluentCRM - EDD Subscription Rules
 * Author: Code Atlantic LLC
 * Author URI: https://code-atlantic.com/
 *
 * @package FluentCRM\CustomFeatures
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

namespace FluentCRM\EDDPro;

/**
 * Class EDDSubscriptionRules
 *
 * Adds EDD subscription-based conditional rules to FluentCRM's filtering system.
 *
 * @package CustomCRM
 */
class EDDSubscriptionRules {
	/**
	 * Register hooks and filters.
	 *
	 * @return void
	 */
	public function register() {
		// Add custom rule types.
		add_filter( 'fluentcrm_advanced_filter_options', [ $this, 'add_edd_filter_options' ], 11, 1 );

		// Use the hook corresponding to our new custom group key.
		add_filter( 'fluentcrm_contacts_filter_edd_pro', [ $this, 'apply_edd_filters' ], 10, 2 );

		// Add event tracking filter options.
		add_filter( 'fluent_crm/event_tracking_condition_groups', [ $this, 'add_edd_filter_options' ], 11, 1 );

		// Apply conditional subscription rules.
		add_filter( 'fluentcrm_automation_conditions_assess_edd', [ $this, 'assess_subscription_condition' ], 10, 3 );
	}


	/**
	 * Add event tracking filter options.
	 *
	 * @param array<string,mixed> $groups Groups.
	 *
	 * @return array<string,mixed>
	 */
	public function add_edd_filter_options( $groups ) {
		$edd_conditions = $this->get_custom_condition_items();

		// Define our custom group key.
		$group_key = 'edd_pro';

		// Add our custom group with its conditions.
		$groups[ $group_key ] = [
			'label'    => __( 'EDD Pro', 'fluent-crm-edd-pro' ), // Use a custom label.
			'value'    => $group_key,
			'children' => $edd_conditions,
		];

		return $groups;
	}

	/**
	 * Get definitions for our custom filter rules.
	 *
	 * @return array
	 */
	protected function get_custom_condition_items(): array {
		$product_options_grouped = []; // Group options by product.

		$products = \get_posts(
			[
				'post_type'      => 'download',
				'posts_per_page' => -1, // Get all products.
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			]
		);

		foreach ( $products as $product ) {
			$product_id_str        = (string) $product->ID;
			$product_group_options = []; // Temporary options for this product.

			if ( \edd_has_variable_prices( $product->ID ) ) {
				$prices = \edd_get_variable_prices( $product->ID );
				if ( $prices ) {
					// Add "All Variants" option first for this product.
					$all_variants_key   = $product_id_str . '-all';
					$all_variants_label = sprintf(
						'%s - %s',
						$product->post_title,
						__( 'All Variants', 'fluent-crm-edd-pro' )
					);
					// Add directly to the product's group.
					$product_group_options[ $all_variants_key ] = \wp_kses_decode_entities( $all_variants_label );

					// Prepare individual variants for sorting within the product group.
					$variants_temp = [];
					foreach ( $prices as $price_id => $price_details ) {
						$key   = $product_id_str . '-' . $price_id;
						$label = sprintf(
							'%s - %s (%s)',
							$product->post_title,
							\esc_html( $price_details['name'] ),
							\edd_currency_filter( \edd_format_amount( $price_details['amount'] ) )
						);
						// Store temporarily for sorting.
						$variants_temp[ $key ] = \wp_kses_decode_entities( $label );
					}
					// Sort variants alphabetically by label within this product.
					uasort( $variants_temp, 'strcasecmp' );
					// Merge sorted variants into the product's group options.
					$product_group_options = array_merge( $product_group_options, $variants_temp );
				} else {
					// Treat as simple product if marked variable but no prices found.
					$key                           = $product_id_str . '-0';
					$label                         = sprintf(
						'%s (%s)',
						$product->post_title,
						\edd_currency_filter( \edd_format_amount( \edd_get_download_price( $product->ID ) ) )
					);
					$product_group_options[ $key ] = \wp_kses_decode_entities( $label );
				}
			} else {
				// Simple product.
				$key                           = $product_id_str . '-0'; // Use 0 for non-variable price ID.
				$label                         = sprintf(
					'%s (%s)',
					$product->post_title,
					\edd_currency_filter( \edd_format_amount( \edd_get_download_price( $product->ID ) ) )
				);
				$product_group_options[ $key ] = \wp_kses_decode_entities( $label );
			}
			// Add this product's options group to the main grouped array.
			if ( ! empty( $product_group_options ) ) {
				$product_options_grouped[ $product_id_str ] = $product_group_options;
			}
		}

		// Flatten the grouped options while preserving the internal order.
		$combined_options = [];
		foreach ( $product_options_grouped as $product_id => $options ) {
			$combined_options = array_merge( $combined_options, $options );
		}
		// Note: We are not sorting the final combined list alphabetically anymore
		// to preserve the "All Variants" first ordering within each product group.
		// If alphabetical sorting of PRODUCTS is still desired, a more complex sort
		// on $product_options_grouped would be needed before flattening.

		// --- Add the "Any" option to the beginning ---
		$final_options = [
			'any' => __( 'Any Active Subscription', 'fluent-crm-edd-pro' ), // Add 'Any' option.
		] + $combined_options; // Prepend 'Any' to the combined list.

		// Define the single combined rule.
		$conditions   = [];
		$conditions[] = [
			'value'            => 'edd_pro_active_subscription', // New single key.
			'label'            => __( 'Has Active Subscription', 'fluent-crm-edd-pro' ),
			'type'             => 'selections',
			'options'          => $final_options, // Use the list with 'Any' prepended.
			'is_multiple'      => true,
			'disabled'         => ! \FluentCampaign\App\Services\Commerce\Commerce::isEnabled( 'edd' ) || ! defined( 'EDD_RECURRING_VERSION' ),
			'help'             => __( 'Filter contacts who have an active subscription. Select "Any" for any active subscription, or specific products/variants.', 'fluent-crm-edd-pro' ),
			'custom_operators' => [
				'in'     => __( 'Has Active', 'fluent-crm-edd-pro' ),
				'not_in' => __( 'Does Not Have Active', 'fluent-crm-edd-pro' ),
			],
		];

		return $conditions;
	}

	/**
	 * Apply subscription filter to the query.
	 *
	 * @param  \FluentCrm\Framework\Database\Query\Builder $query   Query builder instance.
	 * @param  array                                       $filters Array of filter conditions.
	 * @return \FluentCrm\Framework\Database\Query\Builder Modified query builder instance.
	 */
	public function apply_edd_filters( $query, $filters ) {
		global $wpdb;

		foreach ( $filters as $index => $filter ) {
			$property = $filter['property'] ?? '';
			if ( ! $property ) {
				continue; }

			if ( 'edd_pro_active_subscription' !== $property ) {
				continue; }
			if ( ! defined( 'EDD_RECURRING_VERSION' ) ) {
				continue; }

			$selected_options = array_filter( (array) ( $filter['value'] ?? [] ) );
			$operator         = $filter['operator'] ?? 'in';

			if ( empty( $selected_options ) ) {
				break;
			} // Break if nothing selected.

			// --- Check if 'Any' option is selected ---
			$check_for_any = in_array( 'any', $selected_options, true );

			if ( $check_for_any ) {
				// Build simplified SQL check for ANY active subscription.
				$sql_check = $wpdb->prepare(
					"EXISTS (
                       SELECT 1 FROM {$wpdb->prefix}edd_subscriptions AS sub
                       JOIN {$wpdb->prefix}edd_customers AS cust ON cust.id = sub.customer_id
                       WHERE cust.id = (SELECT provider_id FROM {$wpdb->prefix}fc_contact_relations WHERE subscriber_id = {$wpdb->prefix}fc_subscribers.id AND provider = 'edd' LIMIT 1)
                       AND sub.status = %s
                    )",
					'active'
				);
			} else {
				// --- Build detailed SQL check for specific products/variants ---
				$product_id_conditions     = [];   // For `-all` matches.
				$simple_product_conditions = []; // For `-0` matches.
				$variant_pair_conditions   = []; // For specific variants.
				$prepare_args              = [];

				foreach ( $selected_options as $option_key ) { // Loop through non-'any' options.
					if ( substr( $option_key, -4 ) === '-all' ) {
						$product_id = (int) str_replace( '-all', '', $option_key );
						if ( $product_id > 0 ) {
							$key                           = "pid_$product_id";
							$product_id_conditions[ $key ] = 'sub.product_id = %d';
							$prepare_args[ $key ]          = $product_id;
						}
					} else {
						$parts = explode( '-', $option_key, 2 );
						if ( count( $parts ) === 2 && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) ) {
							$product_id = (int) $parts[0];
							$price_id   = (int) $parts[1];
							if ( 0 === $price_id ) {
								$key                               = "spid_$product_id";
								$simple_product_conditions[ $key ] = '(sub.product_id = %d AND (sub.price_id IS NULL OR sub.price_id = 0))';
								$prepare_args[ $key ]              = $product_id;
							} elseif ( $price_id > 0 ) {
								$key_base                             = "pair_$product_id_$price_id";
								$variant_pair_conditions[ $key_base ] = '(sub.product_id = %d AND sub.price_id = %d)';
								$prepare_args[ "{$key_base}_pid" ]    = $product_id;
								$prepare_args[ "{$key_base}_prid" ]   = $price_id;
							}
						}
					}
				}

				// Build the WHERE clause parts.
				$where_clause_parts = [];
				$final_prepare_args = [];

				if ( ! empty( $product_id_conditions ) ) {
					$where_clause_parts[] = '( ' . implode( ' OR ', $product_id_conditions ) . ' )';
					foreach ( array_keys( $product_id_conditions ) as $key ) {
						$final_prepare_args[] = $prepare_args[ $key ]; }
				}
				if ( ! empty( $simple_product_conditions ) ) {
					$where_clause_parts[] = '( ' . implode( ' OR ', $simple_product_conditions ) . ' )';
					foreach ( array_keys( $simple_product_conditions ) as $key ) {
						$final_prepare_args[] = $prepare_args[ $key ]; }
				}
				if ( ! empty( $variant_pair_conditions ) ) {
					$where_clause_parts[] = '( ' . implode( ' OR ', $variant_pair_conditions ) . ' )';
					foreach ( array_keys( $variant_pair_conditions ) as $key_base ) {
						$final_prepare_args[] = $prepare_args[ "{$key_base}_pid" ];
						$final_prepare_args[] = $prepare_args[ "{$key_base}_prid" ];
					}
				}

				if ( empty( $where_clause_parts ) ) {
					break; } // Break if only invalid options were selected.

				$combined_where_clause = implode( ' OR ', $where_clause_parts );
				$final_prepare_args[]  = 'active'; // Add status.

				$sql_check = $wpdb->prepare(
					"EXISTS (
                       SELECT 1 FROM {$wpdb->prefix}edd_subscriptions AS sub
                       JOIN {$wpdb->prefix}edd_customers AS cust ON cust.id = sub.customer_id
                       WHERE cust.id = (SELECT provider_id FROM {$wpdb->prefix}fc_contact_relations WHERE subscriber_id = {$wpdb->prefix}fc_subscribers.id AND provider = 'edd' LIMIT 1)
                       AND ({$combined_where_clause})
                       AND sub.status = %s
                    )",
					$final_prepare_args
				);
				// --- End of detailed SQL check ---
			}

			// Apply the filter based on operator.
			if ( 'in' === $operator ) {
				$query->whereRaw( $sql_check );
			} else { // 'not_in'
				// The NOT IN logic needs to consider the 'any' case too.
				$query->where(function ( $sub_query ) use ( $sql_check, $check_for_any ) {
					if ( $check_for_any ) {
						// If checking for 'NOT IN Any', simply check NOT EXISTS plus the commerce relation check.
						$sub_query->whereRaw( "NOT ({$sql_check})" )
								->orWhere(function ( $or_query ) {
									$or_query->whereDoesntHave('contact_commerce', function ( $q ) {
										$q->where( 'provider', 'edd' );
									});
								});
					} else {
						// If checking for 'NOT IN specific products/variants', use the original complex check.
						$sub_query->whereRaw( "NOT ({$sql_check})" )
								->orWhere(function ( $or_query ) {
									$or_query->whereDoesntHave('contact_commerce', function ( $q ) {
										$q->where( 'provider', 'edd' );
									});
								});
					}
				});
			}

			break; // Processed our rule, exit filter loop.

		} // End foreach $filters.

		return $query;
	}

	/**
	 * Assess subscription condition.
	 *
	 * @param  bool                             $result     Previous result.
	 * @param  array                            $conditions Condition data.
	 * @param  \FluentCrm\App\Models\Subscriber $subscriber Subscriber instance.
	 * @return bool Whether the condition is met.
	 */
	public function assess_subscription_condition( $result, $conditions, $subscriber ) {
		if ( ! defined( 'EDD_RECURRING_VERSION' ) ) {
			return $result; }

		foreach ( $conditions as $condition ) {
			$data_key   = $condition['data_key'] ?? '';
			$data_value = $condition['data_value'] ?? null;
			$operator   = $condition['operator'] ?? 'in';

			if ( 'edd_pro_active_subscription' !== $data_key || is_null( $data_value ) ) {
				continue; }

			$selected_options = array_filter( (array) $data_value );
			if ( empty( $selected_options ) ) {
				continue; }

			// --- Check if 'Any' option is selected ---
			$check_for_any = in_array( 'any', $selected_options, true );
			$has_match     = false;

			if ( $check_for_any ) {
				$has_match = $this->has_any_active_subscription( $subscriber );
			} else {
				// --- Check specific products/variants ---
				$check_product_ids   = [];
				$check_variant_pairs = [];

				foreach ( $selected_options as $option_key ) { // Loop non-'any' options.
					if ( substr( $option_key, -4 ) === '-all' ) {
						$product_id = (int) str_replace( '-all', '', $option_key );
						if ( $product_id > 0 ) {
							$check_product_ids[ $product_id ] = $product_id; }
					} else {
						$parts = explode( '-', $option_key, 2 );
						if ( count( $parts ) === 2 && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) ) {
							$product_id = (int) $parts[0];
							$price_id   = (int) $parts[1];
							if ( 0 === $price_id ) {
								$check_product_ids[ $product_id ] = $product_id; // Treat simple product like -all.
							} elseif ( $price_id > 0 ) {
								$variant_key                         = "{$product_id}-{$price_id}";
								$check_variant_pairs[ $variant_key ] = [
									'product_id' => $product_id,
									'price_id'   => $price_id,
								];
							}
						}
					}
				}

				// Check if *any* selected condition matches.
				if ( ! empty( $check_product_ids ) ) {
					$has_match = $this->has_active_subscription( $subscriber, array_values( $check_product_ids ) );
				}
				if ( ! $has_match && ! empty( $check_variant_pairs ) ) {
					$has_match = $this->has_active_subscription_variant( $subscriber, array_values( $check_variant_pairs ) );
				}
				// --- End specific check ---
			}

			// Assess based on operator and overall match result.
			if ( 'in' === $operator && ! $has_match ) {
				return false; // Condition failed.
			}
			if ( 'not_in' === $operator && $has_match ) {
				return false; // Condition failed.
			}

			break; // Processed our rule, exit conditions loop.
		}
		return $result;
	}

	/**
	 * Check if subscriber has active subscription for ANY variant of given product IDs.
	 * (Kept for the original rule, price_id check is optional)
	 *
	 * @param  \FluentCrm\App\Models\Subscriber $subscriber Subscriber instance.
	 * @param  array                            $product_ids Product IDs.
	 * @param  int|null                         $price_id Price ID.
	 * @return bool
	 */
	protected function has_active_subscription( $subscriber, $product_ids, $price_id = null ) {
		global $wpdb;

		$user_id = $subscriber->user_id;
		$email   = $subscriber->email;

		if ( ! $user_id && ! $email ) {
			return false;
		}

		$customer = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'edd_customers WHERE user_id = %d OR email = %s LIMIT 1',
				$user_id,
				$email
			)
		);

		if ( ! $customer ) {
			return false;
		}

		$product_placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		$args                 = array_merge( [ $customer->id ], $product_ids );

		$sql = "SELECT id FROM {$wpdb->prefix}edd_subscriptions
                WHERE customer_id = %d
                AND product_id IN ($product_placeholders)";

		// Optional: Add price_id check if needed for specific scenarios.
		if ( ! is_null( $price_id ) && is_numeric( $price_id ) ) {
			$sql   .= ' AND price_id = %d';
			$args[] = (int) $price_id;
		}

		$sql   .= ' AND status = %s LIMIT 1';
		$args[] = 'active';

		$subscription = $wpdb->get_row( $wpdb->prepare( $sql, $args ) );
		return (bool) $subscription;
	}

	/**
	 * Check if subscriber has an active subscription matching ANY of the provided product_id/price_id pairs.
	 *
	 * @param  \FluentCrm\App\Models\Subscriber $subscriber Subscriber instance.
	 * @param  array                            $variant_pairs Product ID/price ID pairs.
	 * @return bool
	 */
	protected function has_active_subscription_variant( $subscriber, $variant_pairs ) {
		global $wpdb;

		$user_id = $subscriber->user_id;
		$email   = $subscriber->email;

		if ( ! $user_id && ! $email ) {
			return false;
		}

		$customer = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'edd_customers WHERE user_id = %d OR email = %s LIMIT 1', $user_id, $email ) );
		if ( ! $customer ) {
			return false;
		}

		$variant_conditions = [];
		$prepare_args       = [ $customer->id ]; // Start with customer ID.

		foreach ( $variant_pairs as $pair ) {
			$variant_conditions[] = '(product_id = %d AND price_id = %d)';
			$prepare_args[]       = $pair['product_id'];
			$prepare_args[]       = $pair['price_id'];
		}

		if ( empty( $variant_conditions ) ) {
			return false;
		}

		$variant_where_clause = implode( ' OR ', $variant_conditions );

		// Add status argument.
		$prepare_args[] = 'active';

		$sql = $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}edd_subscriptions
              WHERE customer_id = %d
              AND ({$variant_where_clause})
              AND status = %s
              LIMIT 1",
			$prepare_args
		);

		$subscription = $wpdb->get_row( $sql );
		return (bool) $subscription;
	}

	/**
	 * Check if subscriber has ANY active subscription.
	 *
	 * @param  \FluentCrm\App\Models\Subscriber $subscriber Subscriber instance.
	 * @return bool
	 */
	protected function has_any_active_subscription( $subscriber ) {
		global $wpdb;

		// Need to get customer ID first.
		$user_id = $subscriber->user_id;
		$email   = $subscriber->email;
		if ( ! $user_id && ! $email ) {
			return false;
		}
		$customer = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'edd_customers WHERE user_id = %d OR email = %s LIMIT 1', $user_id, $email ) );
		if ( ! $customer ) {
			return false;
		}

		$sql          = $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}edd_subscriptions
              WHERE customer_id = %d AND status = %s LIMIT 1",
			$customer->id,
			'active'
		);
		$subscription = $wpdb->get_row( $sql );
		return (bool) $subscription;
	}
}
