<?php
/**
 * Description: FluentCRM - EDD Filters
 * Author: Code Atlantic LLC
 * Author URI: https://code-atlantic.com/
 *
 * @package FluentCRM\CustomFeatures
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

namespace FluentCRM\EDDPro;

/**
 * Class Filters
 *
 * Adds EDD subscription-based conditional rules to FluentCRM's filtering system.
 *
 * @package CustomCRM
 */
class Filters {
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

		// Apply EDD Review condition rules.
		add_filter( 'fluentcrm_automation_conditions_assess_edd', [ $this, 'assess_review_condition' ], 11, 3 ); // Use priority 11 to run after subscription check if needed.
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
		$download_options_grouped = []; // Group options by product.

		$all_downloads = \get_posts(
			[
				'post_type'      => 'download',
				'posts_per_page' => -1, // Get all products.
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			]
		);

		foreach ( $all_downloads as $download ) {
			$download_id_str        = (string) $download->ID;
			$download_group_options = []; // Temporary options for this product.

			if ( \edd_has_variable_prices( $download->ID ) ) {
				$prices = \edd_get_variable_prices( $download->ID );
				if ( $prices ) {
					// Add "All Variants" option first for this product.
					$all_variants_key   = $download_id_str . '-all';
					$all_variants_label = sprintf(
						'%s - %s',
						$download->post_title,
						__( 'All Variants', 'fluent-crm-edd-pro' )
					);
					// Add directly to the product's group.
					$download_group_options[ $all_variants_key ] = \wp_kses_decode_entities( $all_variants_label );

					// Prepare individual variants for sorting within the product group.
					$variants_temp = [];
					foreach ( $prices as $price_id => $price_details ) {
						$key   = $download_id_str . '-' . $price_id;
						$label = sprintf(
							'%s - %s (%s)',
							$download->post_title,
							\esc_html( $price_details['name'] ),
							\edd_currency_filter( \edd_format_amount( $price_details['amount'] ) )
						);
						// Store temporarily for sorting.
						$variants_temp[ $key ] = \wp_kses_decode_entities( $label );
					}
					// Sort variants alphabetically by label within this product.
					uasort( $variants_temp, 'strcasecmp' );
					// Merge sorted variants into the product's group options.
					$download_group_options = array_merge( $download_group_options, $variants_temp );
				} else {
					// Treat as simple product if marked variable but no prices found.
					$key                            = $download_id_str . '-0';
					$label                          = sprintf(
						'%s (%s)',
						$download->post_title,
						\edd_currency_filter( \edd_format_amount( \edd_get_download_price( $download->ID ) ) )
					);
					$download_group_options[ $key ] = \wp_kses_decode_entities( $label );
				}
			} else {
				// Simple product.
				$key                            = $download_id_str . '-0'; // Use 0 for non-variable price ID.
				$label                          = sprintf(
					'%s (%s)',
					$download->post_title,
					\edd_currency_filter( \edd_format_amount( \edd_get_download_price( $download->ID ) ) )
				);
				$download_group_options[ $key ] = \wp_kses_decode_entities( $label );
			}
			// Add this product's options group to the main grouped array.
			if ( ! empty( $download_group_options ) ) {
				$download_options_grouped[ $download_id_str ] = $download_group_options;
			}
		}

		// Flatten the grouped options while preserving the internal order.
		$combined_options = [];
		foreach ( $download_options_grouped as $download_id => $options ) {
			$combined_options = array_merge( $combined_options, $options );
		}
		// Note: We are not sorting the final combined list alphabetically anymore
		// to preserve the "All Variants" first ordering within each product group.
		// If alphabetical sorting of PRODUCTS is still desired, a more complex sort
		// on $download_options_grouped would be needed before flattening.

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

		// --- EDD Review Condition ---
		foreach ( $all_downloads as $download ) {
			$review_product_options[ (string) $download->ID ] = \wp_kses_decode_entities( $download->post_title );
		}

		// Add EDD Review condition.
		$conditions[] = [
			'value'            => 'edd_pro_has_left_review',
			'label'            => __( 'Has Left a Review For', 'fluent-crm-edd-pro' ), // Updated Label.
			'type'             => 'selections', // Changed type.
			'options'          => array_merge(
				[
					'any' => __( 'Any Product', 'fluent-crm-edd-pro' ), // 'Any' option.
				],
				$review_product_options
			),
			// Add product options.
			'is_multiple'      => true, // Allow multiple selections.
			'disabled'         => ! function_exists( '\edd_reviews' ), // Check if EDD Reviews class exists.
			'help'             => __( 'Filter contacts based on whether they have submitted a review for specific products or any product via EDD Reviews.', 'fluent-crm-edd-pro' ), // Updated help.
			'custom_operators' => [
				'in'     => __( 'Has Reviewed', 'fluent-crm-edd-pro' ), // Updated operators.
				'not_in' => __( 'Has Not Reviewed', 'fluent-crm-edd-pro' ),
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

			// --- Handle EDD Review Check ---
			if ( 'edd_pro_has_left_review' === $property ) {
				if ( ! class_exists( '\\EDD_Reviews' ) ) {
					continue;
				} // Skip if EDD Reviews is not active.

				$selected_products = array_filter( (array) ( $filter['value'] ?? [] ) );
				$operator          = $filter['operator'] ?? 'in'; // 'in' for Has Reviewed, 'not_in' for Has Not Reviewed.

				if ( empty( $selected_products ) ) {
					continue;
				} // Skip if nothing selected.

				$check_for_any = in_array( 'any', $selected_products, true );
				$download_ids  = [];
				if ( ! $check_for_any ) {
					$download_ids = array_map( 'absint', array_diff( $selected_products, [ 'any' ] ) );
					$download_ids = array_filter( $download_ids ); // Remove any invalid IDs.
					if ( empty( $download_ids ) ) {
						continue; // Skip if only 'any' was selected but filtered out, or only invalid IDs provided.
					}
				}

				// Base subquery parts.
				$sql_select = 'SELECT 1';
				$sql_from   = "FROM {$wpdb->comments} c";
				$sql_where  = $wpdb->prepare(
					'WHERE ((c.user_id > 0 AND c.user_id = ' . $wpdb->prefix . 'fc_subscribers.user_id) OR (c.user_id = 0 AND c.comment_author_email = ' . $wpdb->prefix . 'fc_subscribers.email)) AND c.comment_approved = %s AND c.comment_type = %s',
					'1', // Approved status.
					'edd_review' // Assumed comment type.
				);

				// Add product ID condition if specific products are selected.
				if ( ! $check_for_any && ! empty( $download_ids ) ) {
					$placeholders = implode( ',', array_fill( 0, count( $download_ids ), '%d' ) );
					$sql_where   .= $wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
						" AND c.comment_post_ID IN ({$placeholders})",
						$download_ids
					);
				}

				// Combine the subquery.
				// We have to use prepare for the placeholders within the main WHERE clause,
				// but the overall structure with subscriber fields needs direct inclusion.
				// Marking the outer query as unprepared for PHPCS.
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
				$review_check_sql = "EXISTS ({$sql_select} {$sql_from} {$sql_where} LIMIT 1)";
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

				if ( 'in' === $operator ) {
					$query->whereRaw( $review_check_sql );
				} else { // 'not_in'.
					$query->whereRaw( "NOT ({$review_check_sql})" );
				}
				continue; // Processed review filter, move to next filter.
			}

			// --- Handle Active Subscription Check ---
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
				$download_id_conditions    = [];   // For `-all` matches.
				$simple_product_conditions = []; // For `-0` matches.
				$variant_pair_conditions   = []; // For specific variants.
				$prepare_args              = [];

				foreach ( $selected_options as $option_key ) { // Loop through non-'any' options.
					if ( substr( $option_key, -4 ) === '-all' ) {
						$download_id = (int) str_replace( '-all', '', $option_key );
						if ( $download_id > 0 ) {
							$key                            = "pid_{$download_id}";
							$download_id_conditions[ $key ] = 'sub.product_id = %d';
							$prepare_args[ $key ]           = $download_id;
						}
					} else {
						$parts = explode( '-', $option_key, 2 );
						if ( count( $parts ) === 2 && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) ) {
							$download_id = (int) $parts[0];
							$price_id    = (int) $parts[1];
							if ( 0 === $price_id ) {
								$key                               = "spid_{$download_id}";
								$simple_product_conditions[ $key ] = '(sub.product_id = %d AND (sub.price_id IS NULL OR sub.price_id = 0))';
								$prepare_args[ $key ]              = $download_id;
							} elseif ( $price_id > 0 ) {
								$key_base                             = "pair_{$download_id}_{$price_id}";
								$variant_pair_conditions[ $key_base ] = '(sub.product_id = %d AND sub.price_id = %d)';
								$prepare_args[ "{$key_base}_pid" ]    = $download_id;
								$prepare_args[ "{$key_base}_prid" ]   = $price_id;
							}
						}
					}
				}

				// Build the WHERE clause parts.
				$where_clause_parts = [];
				$final_prepare_args = [];

				if ( ! empty( $download_id_conditions ) ) {
					$where_clause_parts[] = '( ' . implode( ' OR ', array_fill( 0, count( $download_id_conditions ), 'sub.product_id = %d' ) ) . ' )';
					foreach ( array_keys( $download_id_conditions ) as $key ) {
						$final_prepare_args[] = $prepare_args[ $key ]; }
				}
				if ( ! empty( $simple_product_conditions ) ) {
					$where_clause_parts[] = '( ' . implode( ' OR ', array_fill( 0, count( $simple_product_conditions ), '(sub.product_id = %d AND (sub.price_id IS NULL OR sub.price_id = 0))' ) ) . ' )';
					foreach ( array_keys( $simple_product_conditions ) as $key ) {
						$final_prepare_args[] = $prepare_args[ $key ]; }
				}
				if ( ! empty( $variant_pair_conditions ) ) {
					$where_clause_parts[] = '( ' . implode( ' OR ', array_fill( 0, count( $variant_pair_conditions ), '(sub.product_id = %d AND sub.price_id = %d)' ) ) . ' )';
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
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"EXISTS ( SELECT 1 FROM {$wpdb->prefix}edd_subscriptions AS sub JOIN {$wpdb->prefix}edd_customers AS cust ON cust.id = sub.customer_id WHERE cust.id = (SELECT provider_id FROM {$wpdb->prefix}fc_contact_relations WHERE subscriber_id = {$wpdb->prefix}fc_subscribers.id AND provider = 'edd' LIMIT 1) AND ({$combined_where_clause}) AND sub.status = %s )",
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
						$download_id = (int) str_replace( '-all', '', $option_key );
						if ( $download_id > 0 ) {
							$check_product_ids[ $download_id ] = $download_id; }
					} else {
						$parts = explode( '-', $option_key, 2 );
						if ( count( $parts ) === 2 && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) ) {
							$download_id = (int) $parts[0];
							$price_id    = (int) $parts[1];
							if ( 0 === $price_id ) {
								$check_product_ids[ $download_id ] = $download_id; // Treat simple product like -all.
							} elseif ( $price_id > 0 ) {
								$variant_key                         = "{$download_id}-{$price_id}";
								$check_variant_pairs[ $variant_key ] = [
									'product_id' => $download_id,
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
	 * @param  array                            $download_ids Product IDs.
	 * @param  int|null                         $price_id Price ID.
	 * @return bool
	 */
	protected function has_active_subscription( $subscriber, $download_ids, $price_id = null ) {
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

		$download_placeholders = implode( ',', array_fill( 0, count( $download_ids ), '%d' ) );
		$args                  = array_merge( [ $customer->id ], $download_ids );

		$sql = "SELECT id FROM {$wpdb->prefix}edd_subscriptions
                WHERE customer_id = %d
                AND product_id IN ($download_placeholders)";

		// Optional: Add price_id check if needed for specific scenarios.
		if ( ! is_null( $price_id ) && is_numeric( $price_id ) ) {
			$sql   .= ' AND price_id = %d';
			$args[] = (int) $price_id;
		}

		$sql   .= ' AND status = %s LIMIT 1';
		$args[] = 'active';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$subscription = $wpdb->get_row( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

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

		$subscription = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$wpdb->prefix}edd_subscriptions WHERE customer_id = %d AND ({$variant_where_clause}) AND status = %s LIMIT 1",
				$prepare_args
			)
		);

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

		$subscription = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}edd_subscriptions
              WHERE customer_id = %d AND status = %s LIMIT 1",
				$customer->id,
				'active'
			)
		);

		return (bool) $subscription;
	}

	/**
	 * Assess review condition for automation.
	 *
	 * @param  bool                             $result     Previous result.
	 * @param  array                            $conditions Condition data.
	 * @param  \FluentCrm\App\Models\Subscriber $subscriber Subscriber instance.
	 * @return bool Whether the condition is met.
	 */
	public function assess_review_condition( $result, $conditions, $subscriber ) {
		// If a previous condition in the same hook call already returned false, respect it.
		if ( ! $result ) {
			return false;
		}

		if ( ! class_exists( '\\EDD_Reviews' ) ) {
			return $result; // Can't check if plugin isn't active, maintain previous result.
		}

		foreach ( $conditions as $condition ) {
			$data_key   = $condition['data_key'] ?? '';
			$data_value = $condition['data_value'] ?? null;
			$operator   = $condition['operator'] ?? 'in'; // Default to 'in'.

			if ( 'edd_pro_has_left_review' !== $data_key ) {
				continue; // Skip conditions not related to reviews.
			}

			$selected_products = array_filter( (array) $data_value );
			if ( empty( $selected_products ) ) {
				continue; // Skip if no products selected for this rule.
			}

			// Determine if the condition expects the user to have left a review.
			$expects_review = ( 'in' === $operator );

			// Extract specific product IDs, excluding 'any'.
			$check_product_ids = [];
			$check_for_any     = in_array( 'any', $selected_products, true );
			if ( ! $check_for_any ) {
				$check_product_ids = array_map( 'absint', array_diff( $selected_products, [ 'any' ] ) );
				$check_product_ids = array_filter( $check_product_ids );
				if ( empty( $check_product_ids ) ) {
					continue; // No valid specific products selected.
				}
			}

			// Check if the user actually left a review for the specified products (or any).
			// Pass $check_product_ids (empty array implies check for 'any').
			$has_left_review = $this->subscriber_has_left_review( $subscriber, $check_product_ids );

			// Evaluate the condition based on expectation and reality.
			if ( $expects_review && ! $has_left_review ) {
				return false; // Expected review, but none found for the criteria.
			}
			if ( ! $expects_review && $has_left_review ) {
				return false; // Expected no review, but one was found for the criteria.
			}
		}

		// If loop completes without returning false, the review conditions (if any) are met.
		return $result; // Maintain the result from previous conditions or initial true state.
	}

	/**
	 * Check if subscriber has left an EDD review.
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber Subscriber instance.
	 * @param array                            $download_ids Product IDs to check against (empty means any).
	 * @return bool
	 */
	protected function subscriber_has_left_review( $subscriber, $download_ids = [] ): bool {
		global $wpdb;

		if ( ! class_exists( '\\EDD_Reviews' ) ) {
			return false;
		}

		$user_id = $subscriber->user_id;
		$email   = $subscriber->email;

		if ( ! $user_id && ! $email ) {
			return false; // Cannot identify the user.
		}

		// Prepare WHERE clauses based on available identifiers.
		$where_clauses = [];
		$args          = [];
		if ( $user_id > 0 ) {
			$where_clauses[] = 'c.user_id = %d';
			$args[]          = $user_id;
		}
		if ( $email ) {
			// For non-logged-in users, check email.
			// Include user_id = 0 to potentially avoid matching logged-in users by email if their user_id is already checked.
			$where_clauses[] = '(c.user_id = 0 AND c.comment_author_email = %s)';
			$args[]          = $email;
		}

		if ( empty( $where_clauses ) ) {
			return false;
		}

		// Combine user WHERE clauses with OR.
		$user_where_clause = '( ' . implode( ' OR ', $where_clauses ) . ' )';

		// Add base conditions for comment type and approval status.
		$sql = "SELECT COUNT(comment_ID) FROM {$wpdb->comments} c WHERE {$user_where_clause} AND c.comment_type = %s AND c.comment_approved = %s";
		array_push( $args, 'edd_review', '1' ); // Add type and status to args.

		// Add product ID condition if specific products are selected.
		if ( ! empty( $download_ids ) ) {
			$download_placeholders = implode( ',', array_fill( 0, count( $download_ids ), '%d' ) );
			$sql                  .= " AND c.comment_post_ID IN ({$download_placeholders})";
			$args                  = array_merge( $args, $download_ids ); // Add product IDs to args.
		}

		// Prepare the final SQL query.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$review_count = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return $review_count > 0;
	}
}
