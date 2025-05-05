<?php
/**
 * Description: FluentCRM - EDD Subscription Rules
 * Author: Code Atlantic LLC
 * Author URI: https://code-atlantic.com/
 *
 * @package FluentCRM\CustomFeatures
 */

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

namespace CustomCRM;

use FluentCrm\Framework\Support\Arr;
use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCrm\App\Models\Subscriber;

/**
 * Class EDDSubscriptionRules
 *
 * Adds EDD subscription-based conditional rules to FluentCRM's filtering system.
 *
 * @package CustomCRM
 */
class EDDSubscriptionRules
{

    /**
     * Register hooks and filters.
     *
     * @return void
     */
    public function register()
    {
        // Add custom rule types.
        add_filter('fluentcrm_advanced_filter_options', [ $this, 'addEddFilterOptions' ], 11, 1);
        // Use the hook corresponding to our new custom group key
        add_filter('fluentcrm_contacts_filter_custom_edd_subscription_rules', [ $this, 'applyEddFilters' ], 10, 2);

        add_filter('fluent_crm/event_tracking_condition_groups', [ $this, 'addEddFilterOptions' ], 11, 1);

        // Add AJAX handler for our custom product selector - hook name adjusted to match component request
        // remove_action('wp_ajax_fluentcrm_get_ajax_options_product_selector_custom_edd_subscription_rules', [ $this, 'getEddProductOptions' ]); // Remove AJAX handler

        // Apply conditional subscription rules.
        add_filter('fluentcrm_automation_conditions_assess_edd', [ $this, 'assess_subscription_condition' ], 10, 3);
    }


    /**
     * Add event tracking filter options.
     *
     * @param array<string,mixed> $groups Groups.
     *
     * @return array<string,mixed>
     */
    public function addEddFilterOptions( $groups )
    {
        $edd_conditions = $this->getConditionItems();

        // Define our custom group key
        $group_key = 'custom_edd_subscription_rules';

        // Add our custom group with its conditions.
        $groups[ $group_key ] = [
            'label'    => __('Custom EDD Rules', 'fluent-crm-custom-features'), // Use a custom label
            'value'    => $group_key,
            'children' => $edd_conditions,
        ];

        return $groups;
    }

    /**
     * Get subscription filter items.
     *
     * @return array<int<0,max>,array<string,mixed>>
     */
    public function getConditionItems()
    {
        // Fetch all EDD products directly
        $products = \get_posts(
            [
            'post_type'      => 'download',
            'posts_per_page' => -1, // Get all products
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post_status'    => 'publish',
            ]
        );

        $formatted_products = [];
        foreach ( $products as $product ) {
            // Use ID as key and Title as value
            $formatted_products[ (string) $product->ID ] = $product->post_title;
        }

        return [
            [
            'value'            => 'custom_edd_active_subscription',
            'label'            => __('Has Active Subscription', 'fluent-crm-custom-features'),
            'type'             => 'selections', // Keep as selections for multi-select UI
            // 'component'        => 'product_selector', // Remove component key
            'options'          => $formatted_products, // Add options directly
            'is_multiple'      => true,
            'disabled'         => ! \FluentCampaign\App\Services\Commerce\Commerce::isEnabled('edd') || ! defined('EDD_RECURRING_VERSION'),
            'help'             => __('Filter contacts who have an active subscription for selected products', 'fluent-crm-custom-features'),
            'custom_operators' => [
            'in'     => __('Has Active', 'fluent-crm-custom-features'),
            'not_in' => __('Does Not Have Active', 'fluent-crm-custom-features'),
            ],
            ],
        ];
    }

    /**
     * Apply subscription filter to the query.
     *
     * @param  \FluentCrm\Framework\Database\Query\Builder $query   Query builder instance.
     * @param  array                                       $filters Array of filter conditions.
     * @return \FluentCrm\Framework\Database\Query\Builder Modified query builder instance.
     */
    public function applyEddFilters( $query, $filters )
    {
        global $wpdb;

        // No need to track processed indices here as we are using a custom hook
        // $processed_indices = [];

        foreach ( $filters as $index => $filter ) { // Get index
            $property = $filter['property'] ?? '';

            if (! $property ) {
                continue;
            }

            // Use the renamed key here
            if ('custom_edd_active_subscription' === $property && ! defined('EDD_RECURRING_VERSION') ) {
                // $processed_indices[] = $index;
                continue;
            }

            switch ( $filter['property'] ) {
            // Use the renamed key here
            case 'custom_edd_active_subscription':
                $product_ids = (array) $filter['value'];
                $operator    = $filter['operator'];

                // No need to apply filter if product IDs are empty.
                if (empty($product_ids) ) {
                    // $processed_indices[] = $index;
                    break;
                }

                $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

                if ('in' === $operator ) {
                    $query->whereRaw(
                        $wpdb->prepare(
                            "EXISTS (
									SELECT 1
									FROM {$wpdb->prefix}edd_subscriptions AS sub
									JOIN {$wpdb->prefix}edd_customers AS cust ON cust.id = sub.customer_id
									WHERE cust.id = (SELECT provider_id FROM {$wpdb->prefix}fc_contact_relations WHERE subscriber_id = {$wpdb->prefix}fc_subscribers.id AND provider = 'edd' LIMIT 1)
									AND sub.product_id IN ($placeholders)
									AND sub.status = %s
								)",
                            array_merge($product_ids, [ 'active' ])
                        )
                    );
                } else { // 'not_in' operator
                    $query->where(
                        function ($subQuery) use ($wpdb, $product_ids, $placeholders) {
                            $subQuery->whereRaw(
                                $wpdb->prepare(
                                    "NOT EXISTS (
                                        SELECT 1
                                        FROM {$wpdb->prefix}edd_subscriptions AS sub
                                        JOIN {$wpdb->prefix}edd_customers AS cust ON cust.id = sub.customer_id
                                        WHERE cust.id = (SELECT provider_id FROM {$wpdb->prefix}fc_contact_relations WHERE subscriber_id = {$wpdb->prefix}fc_subscribers.id AND provider = 'edd' LIMIT 1)
                                        AND sub.product_id IN ($placeholders)
                                        AND sub.status = %s
                                    )",
                                    array_merge($product_ids, [ 'active' ])
                                )
                                // Also need to handle cases where the user has no EDD customer record at all
                            )->orWhereDoesntHave(
                                'contact_commerce', function ($q) {
                                    $q->where('provider', 'edd');
                                }
                            );
                        }
                    );
                }
                // $processed_indices[] = $index;
                break;
            }
        }

        // Remove the filters we've already processed so default handlers don't process them again
        // No longer needed as we are using a custom hook
        // foreach ($processed_indices as $index) {
        //    unset($filters[$index]);
        // }

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
    public function assess_subscription_condition( $result, $conditions, $subscriber )
    {
        foreach ( $conditions as $condition ) {
            if (empty($condition['data_key']) || 'custom_edd_active_subscription' !== $condition['data_key'] ) {
                continue;
            }

            $product_ids = (array) $condition['data_value'];
            $operator    = $condition['operator'];

            if (! defined('EDD_RECURRING_VERSION') || empty($product_ids) ) {
                return $result;
            }

            $has_subscription = $this->has_active_subscription($subscriber, $product_ids);

            if (( 'in' === $operator && ! $has_subscription ) || ( 'not_in' === $operator && $has_subscription ) ) {
                return false;
            }
        }

        return $result;
    }

    /**
     * Check if subscriber has active subscription.
     *
     * @param  \FluentCrm\App\Models\Subscriber $subscriber  Subscriber instance.
     * @param  array                            $product_ids Product IDs to check.
     * @return bool Whether subscriber has active subscription.
     */
    protected function has_active_subscription( $subscriber, $product_ids )
    {
        global $wpdb;

        $user_id = $subscriber->user_id;
        $email   = $subscriber->email;

        if (! $user_id && ! $email ) {
            return false;
        }

        $customer = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'edd_customers WHERE user_id = %d OR email = %s LIMIT 1',
                $user_id,
                $email
            )
        );

        if (! $customer ) {
            return false;
        }

        $subscription = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'edd_subscriptions WHERE customer_id = %d AND product_id IN (' . implode(',', array_fill(0, count($product_ids), '%d')) . ') AND status = %s LIMIT 1',
                array_merge([ $customer->id ], $product_ids, [ 'active' ])
            )
        );

        return (bool) $subscription;
    }
}
