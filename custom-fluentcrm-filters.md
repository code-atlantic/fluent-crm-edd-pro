# How to Add Custom Advanced Filter Rules in FluentCRM

This guide explains how to extend FluentCRM's contact segmentation capabilities by adding your own custom filtering rules to the "Advanced Filters" section. This allows you to segment contacts based on virtually any data available in your WordPress environment.

We'll cover creating new filter groups and adding rules to existing groups, including handling dropdown options for selection-based filters.

## Prerequisites

- Basic understanding of PHP and WordPress hooks (actions and filters).
- Familiarity with FluentCRM's data structure is helpful but not strictly required.
- Access to your WordPress site's code (e.g., via a custom plugin or theme's `functions.php`). Using a custom plugin is recommended.

## Overview of Steps

1.  **Create a Class:** Organize your code within a PHP class.
2.  **Define Filter Rule(s):** Structure the data defining how your rule appears in the UI.
3.  **Add Rule(s) to Filter Options:** Hook into FluentCRM to add your rule definition(s) to the list.
4.  **Implement Filter Logic:** Hook into FluentCRM to apply your rule's logic to the contact query.
5.  **Handle Dropdown Options (if needed):** Provide data for selection-type filters.
6.  **Register Everything:** Ensure your class and its methods are loaded and hooked correctly.

---

## Step 1: Create the Class

It's best practice to encapsulate your custom logic within a class. Create a new PHP file (e.g., `includes/class-my-fluentcrm-custom-rules.php` within your custom plugin) and define your class. Using namespaces is recommended.

```php
<?php
/**
 * My FluentCRM Custom Rules
 */

namespace MyPlugin\FluentCrmFilters;

// Use necessary FluentCRM classes if needed (example)
// use FluentCrm\App\Models\Subscriber;
// use FluentCrm\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class MyFluentCrmCustomRules
 *
 * Handles adding custom filter rules to FluentCRM.
 */
class MyFluentCrmCustomRules
{
    /**
     * Register hooks.
     */
    public function register()
    {
        // We'll add hooks here in later steps
    }

    // We'll add methods here in later steps
}
```

---

## Step 2: Define the Filter Rule(s)

You need a method that returns an array (or array of arrays) defining your filter rule(s). Each rule definition is an associative array specifying its appearance and behavior.

```php
<?php

namespace MyPlugin\FluentCrmFilters;

class MyFluentCrmCustomRules
{
    // ... (register method) ...

    /**
     * Get definitions for our custom filter rules.
     *
     * @return array
     */
    protected function getCustomConditionItems(): array
    {
        // Example 1: Simple text check (on a custom meta field)
        $rule1 = [
            'value'            => 'my_custom_meta_check', // Unique key for this rule
            'label'            => __('Has Specific Meta Value', 'my-plugin-textdomain'), // UI Label
            'type'             => 'text', // Input type (text, numeric, selections, dates, etc.)
            // Define operators (optional, defaults based on type)
            'custom_operators' => [
                '='          => __('Is Equal To', 'my-plugin-textdomain'),
                '!='         => __('Is Not Equal To', 'my-plugin-textdomain'),
                'contains'   => __('Contains', 'my-plugin-textdomain'),
                'not_contains' => __('Does Not Contain', 'my-plugin-textdomain'),
            ],
            'help'             => __('Filter contacts by the value of the \'my_special_meta\' custom field.', 'my-plugin-textdomain'),
        ];

        // Example 2: Dropdown selection (e.g., custom status)
        $rule2 = [
            'value'       => 'my_custom_status', // Unique key
            'label'       => __('Has Custom Status', 'my-plugin-textdomain'),
            'type'        => 'selections', // Use 'selections' for dropdowns/multi-select
            'options'     => [ // Provide options directly (simple associative array)
                'pending'   => __('Pending Review', 'my-plugin-textdomain'),
                'approved'  => __('Approved', 'my-plugin-textdomain'),
                'rejected'  => __('Rejected', 'my-plugin-textdomain'),
            ],
            'is_multiple' => false, // Set to true for multi-select
            'help'        => __('Filter by our custom review status.', 'my-plugin-textdomain'),
        ];

        // Example 3: Simple rule for existing FluentCRM group (First Name starts with)
        $rule3 = [
            'value'            => 'first_name_starts_with', // Unique key
            'label'            => __('First Name Starts With', 'my-plugin-textdomain'),
            'type'             => 'text',
             'custom_operators' => [
                'starts_with' => __('Starts With', 'my-plugin-textdomain'),
            ],
            'help'             => __('Enter the letter or characters the first name should start with.', 'my-plugin-textdomain'),
        ];


        return [
            'rule1' => $rule1,
            'rule2' => $rule2,
            'rule3' => $rule3,
            // Add more rules here...
        ];
    }

     // ... (rest of the class) ...
}
```

**Key Definition Fields:**

- `value`: A unique internal key for your rule.
- `label`: The text displayed in the filter dropdown.
- `type`: Controls the input field type (`text`, `numeric`, `selections`, `dates`, `nullable_text`, etc.).
- `options`: _Required for `selections` type if not using AJAX._ An associative array (`value => label`) for dropdown options.
- `is_multiple`: `true` or `false` (default `false`). Makes `selections` a multi-select.
- `component`: Usually used for more complex UI components like `product_selector`, `ajax_selector`, `options_selector`. Often used with `option_key`. If providing direct `options`, typically omit this.
- `option_key`: If using `component` like `ajax_selector`, this specifies the key for the AJAX data source hook.
- `custom_operators`: An associative array (`operator_key => label`) to override default operators for the given `type`.
- `help`: Helper text displayed below the filter.
- `disabled`: `true` or `false`. Can be used to disable the rule based on conditions (e.g., if a required plugin isn't active).

---

## Step 3: Add Rules to Filter Options

Hook into the `fluentcrm_advanced_filter_options` filter to add your rule definition(s). You can add them to an existing FluentCRM group or create your own custom group.

```php
<?php

namespace MyPlugin\FluentCrmFilters;

class MyFluentCrmCustomRules
{
    public function register()
    {
        add_filter('fluentcrm_advanced_filter_options', [$this, 'addCustomFilterOptions'], 11, 1); // Priority > 10 often needed

        // ... other hooks ...
    }

    /**
     * Add our custom rules to the advanced filter options.
     *
     * @param array $groups Existing filter groups.
     * @return array Modified groups.
     */
    public function addCustomFilterOptions(array $groups): array
    {
        $custom_conditions = $this->getCustomConditionItems();

        // --- Option A: Add rules to an EXISTING FluentCRM group ---
        $segment_group_key = 'segment'; // Key for 'Contact Segment' group

        // Ensure the target group exists (optional but good practice)
        if (!isset($groups[$segment_group_key])) {
             $groups[$segment_group_key] = [
                 'label'    => __('Contact Segment', 'fluent-crm'), // Use FluentCRM's label
                 'value'    => $segment_group_key,
                 'children' => [],
             ];
        }
        // Add our specific rule(s) intended for this group
        if (isset($custom_conditions['rule3'])) {
             $groups[$segment_group_key]['children'][] = $custom_conditions['rule3'];
        }


        // --- Option B: Add rules to a NEW custom group ---
        $custom_group_key = 'my_custom_rules_group';

        // Add our custom group with its conditions
        $groups[$custom_group_key] = [
            'label'    => __('My Custom Rules', 'my-plugin-textdomain'),
            'value'    => $custom_group_key,
            'children' => [ // Add rules intended for this new group
                 $custom_conditions['rule1'],
                 $custom_conditions['rule2'],
                 // Add others here...
            ],
        ];

        // Optional: Log the final structure for debugging
        // error_log('FluentCRM Filter Groups: ' . print_r($groups, true));

        // FluentCRM expects a numerically indexed array, so reset keys if needed
        return array_values($groups);
    }

     // ... (getCustomConditionItems method etc.) ...
}
```

**Important:**

- The `$groups` array passed to the filter is keyed by the group's `value`.
- Each group has a `children` array containing the rule definitions for that group.
- Use a priority higher than 10 (like 11) if you need to ensure a group created by FluentCRM exists before you add to it.
- Using `array_values()` on the final `$groups` array is often recommended as FluentCRM sometimes expects a zero-indexed array.

---

## Step 4: Implement Filter Logic

For each group you add rules to (whether existing or new), you need to implement the logic that applies the filter to the database query. This is done using a dynamic hook: `fluentcrm_contacts_filter_{group_key}`.

The hook receives the `$query` builder instance and the `$filters` array (containing only the rules _from that specific group_ that were selected by the user).

```php
<?php

namespace MyPlugin\FluentCrmFilters;

// Make sure FluentCRM Query Builder is accessible if needed
use FluentCrm\Framework\Database\Query\Builder;


class MyFluentCrmCustomRules
{
    public function register()
    {
        add_filter('fluentcrm_advanced_filter_options', [$this, 'addCustomFilterOptions'], 11, 1);

        // Hook for our NEW custom group
        add_filter('fluentcrm_contacts_filter_my_custom_rules_group', [$this, 'applyCustomGroupFilterLogic'], 10, 2);

        // Hook for the EXISTING 'segment' group we added a rule to
        add_filter('fluentcrm_contacts_filter_segment', [$this, 'applySegmentGroupFilterLogic'], 10, 2);


        // ... other hooks ...
    }

    // ... (addCustomFilterOptions, getCustomConditionItems methods) ...

    /**
     * Apply filter logic for rules in our custom group.
     *
     * @param Builder $query Fluent Query Builder instance.
     * @param array   $filters Filters selected within this group.
     * @return Builder Modified query builder.
     */
    public function applyCustomGroupFilterLogic(Builder $query, array $filters): Builder
    {
        foreach ($filters as $filter) {
            $property = $filter['property'] ?? ''; // This matches the rule's 'value'
            $operator = $filter['operator'] ?? '';
            $value    = $filter['value'] ?? null; // The value entered/selected by the user

            if (is_null($value)) continue; // Skip if no value provided

            switch ($property) {
                case 'my_custom_meta_check':
                    // Example: Query wp_usermeta or a custom table
                    // This requires joining or subqueries, which can be complex.
                    // Simple EXISTS example (adjust table/columns):
                    $query->whereExists(function($subQuery) use ($value, $operator) {
                         $subQuery->select('meta_id')->from('usermeta')
                             ->where('user_id', '=', fluentCrmDb()->raw('wp_fc_subscribers.user_id')) // Link to outer query subscriber
                             ->where('meta_key', '=', 'my_special_meta');

                         // Apply operator logic
                         if ($operator === '=') $subQuery->where('meta_value', '=', $value);
                         if ($operator === '!=') $subQuery->where('meta_value', '!=', $value);
                         if ($operator === 'contains') $subQuery->where('meta_value', 'LIKE', '%' . fluentCrmDb()->escapeLike($value) . '%');
                         if ($operator === 'not_contains') $subQuery->where('meta_value', 'NOT LIKE', '%' . fluentCrmDb()->escapeLike($value) . '%');

                         return $subQuery;
                    });
                    break;

                case 'my_custom_status':
                     // Similar logic, perhaps checking another meta field or custom table
                     // using the selected $value ('pending', 'approved', 'rejected')
                     // ... implementation depends on where 'my_custom_status' is stored ...
                     break;

                 // Add cases for other rules in this group...
            }
        }
        return $query;
    }


    /**
     * Apply filter logic for rules added to the 'segment' group.
     *
     * @param Builder $query Fluent Query Builder instance.
     * @param array   $filters Filters selected within this group.
     * @return Builder Modified query builder.
     */
    public function applySegmentGroupFilterLogic(Builder $query, array $filters): Builder
    {
         foreach ($filters as $filter) {
            $property = $filter['property'] ?? '';
            $operator = $filter['operator'] ?? '';
            $value    = $filter['value'] ?? null;

            if (is_null($value)) continue;

            switch ($property) {
                case 'first_name_starts_with':
                    if ($operator === 'starts_with' && !empty($value)) {
                         // Assumes 'first_name' is a column on wp_fc_subscribers
                         $query->where('first_name', 'LIKE', fluentCrmDb()->escapeLike($value) . '%');
                    }
                    break;

                 // IMPORTANT: Check if other default 'segment' filters are processed
                 // by FluentCRM's own handler on this hook. If you don't handle
                 // tags, lists, status etc. here, they might be missed OR
                 // you might conflict with the default handler.
                 // It might be safer to use a higher priority (e.g., 11) for your
                 // hook here and potentially unset your processed filter from
                 // the $filters array IF you detect conflicts.
            }
        }
        return $query;
    }

     // ... (rest of the class) ...
}
```

**Key Points for Filter Logic:**

- You receive the `$query` object (an instance of `FluentCrm\Framework\Database\Query\Builder`). Use its methods (`where`, `whereIn`, `whereExists`, `whereHas`, `orWhere`, `join`, etc.) to modify the query.
- Refer to the FluentCRM documentation or source code for Query Builder methods if needed.
- `$filter['property']` matches the unique `value` you defined for the rule.
- `$filter['value']` holds the user's input or selection(s). Sanitize/validate this as needed.
- `$filter['operator']` holds the selected operator (e.g., `=`, `contains`, `in`, `not_in`).
- Use `fluentCrmDb()->raw(...)` for raw SQL expressions (like accessing the outer query table).
- Use `fluentCrmDb()->escapeLike(...)` to properly escape wildcards when using `LIKE`.
- **Important:** If adding rules to an _existing_ FluentCRM group, be aware that FluentCRM's own handler might also run on the same hook (`fluentcrm_contacts_filter_{group_key}`). This can lead to conflicts if your rule `value` clashes with a built-in one, or if the default handler expects to process all filters. Adding to a _custom_ group avoids these conflicts.

---

## Step 5: Handling Dropdown Options (for 'selections' type)

If your filter `type` is `selections`, you need to provide the dropdown options.

**Method 1: Embedded Options (Simpler)**

As shown in Step 2 (`rule2`), provide a simple associative array (`value => label`) directly in the filter definition using the `'options'` key. Remove the `'component'` key if present.

```php
// Inside getCustomConditionItems()
'rule2' => [
    'value'       => 'my_custom_status',
    'label'       => __('Has Custom Status', 'my-plugin-textdomain'),
    'type'        => 'selections',
    'options'     => [ // Simple associative array
        'pending'   => __('Pending Review', 'my-plugin-textdomain'),
        'approved'  => __('Approved', 'my-plugin-textdomain'),
        'rejected'  => __('Rejected', 'my-plugin-textdomain'),
    ],
    'is_multiple' => false,
],
```

**Method 2: AJAX Options (More Performant for Large Lists)**

This is better if you have many options (like products, posts, etc.).

1.  **Modify Rule Definition:** Add an `'option_key'` and ensure the `'component'` is set appropriately (often `'ajax_selector'` or sometimes the default `selections` works if the AJAX hook name matches expectations). _Based on our EDD experience, relying solely on the hook name derived from the group key might be necessary._

    ```php
    // Inside getCustomConditionItems() - If relying ONLY on hook name
    'rule_with_ajax_options' => [
        'value'            => 'my_cpt_selection',
        'label'            => __('Select Custom Post', 'my-plugin-textdomain'),
        'type'             => 'selections', // Or potentially keep 'component' => 'ajax_selector'
        // NOTE: No 'option_key' here if component derives hook from group key
        'is_multiple'      => true,
        // ...
    ],
    ```

2.  **Register AJAX Hook:** In your `register` method, add the `wp_ajax_` hook. The hook name _must_ match what the frontend component requests. This is often `fluentcrm_get_ajax_options_{component_name}_{group_key}` or potentially `fluentcrm_get_ajax_options_{option_key}` if specified and respected. Debugging the actual AJAX request URL is the best way to find the correct hook name.

    ```php
    // Inside register() - Hook name derived from component + group key
    add_action(
        'wp_ajax_fluentcrm_get_ajax_options_ajax_selector_my_custom_rules_group', // Example hook name
        [$this, 'getMyCptOptionsAjax']
    );
    ```

3.  **Implement Handler Method:** Create the method to fetch and return data.

    ```php
    <?php

    namespace MyPlugin\FluentCrmFilters;

    class MyFluentCrmCustomRules
    {
         // ... (register, other methods) ...

         /**
          * AJAX handler to provide Custom Post options.
          */
         public function getMyCptOptionsAjax()
         {
             // Optional: Add nonce check and capability check for security
             // check_ajax_referer('fluentcrm_ajax_nonce');
             // if (!current_user_can('manage_options')) {
             //     \wp_send_json_error(['message' => 'Permission denied']);
             // }

             $search = isset($_REQUEST['search']) ? \sanitize_text_field(\wp_unslash($_REQUEST['search'])) : '';

             $args = [
                 'post_type'      => 'my_custom_post',
                 'posts_per_page' => 50,
                 'orderby'        => 'title',
                 'order'          => 'ASC',
                 'post_status'    => 'publish',
             ];
             if (!empty($search)) {
                 $args['s'] = $search; // Or use 'post_title__like'
             }

             $posts = \get_posts($args); // Use global namespace

             $formatted_options = [];
             foreach ($posts as $post) {
                 $formatted_options[] = [ // Use the array-of-arrays format for ajax_selector
                     'id'    => (string) $post->ID,
                     'title' => $post->post_title,
                 ];
             }

             // Send JSON response expected by ajax_selector
             \wp_send_json([
                 'options' => $formatted_options
             ]); // Use global namespace
         }
         // ...
    }
    ```

**Key Points for AJAX:**

- Use `\` before global WordPress functions (`get_posts`, `wp_send_json`, `sanitize_text_field`, etc.) if your class is namespaced.
- The expected response format for `ajax_selector` is typically `['options' => [ ['id' => ..., 'title' => ...], ... ]]`.
- Finding the correct AJAX hook name can be tricky. Use browser developer tools to inspect the network request made by the dropdown.

---

## Step 6: Register Everything

In your main plugin file or `functions.php`, instantiate your class and call its `register` method. Hook this into `plugins_loaded` or `init` to ensure it runs after FluentCRM is loaded but before hooks are needed.

```php
<?php
/**
 * Plugin Name: My FluentCRM Customizations
 * Description: Adds custom rules and features to FluentCRM.
 * Version: 1.0.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Include your class file
require_once plugin_dir_path(__FILE__) . 'includes/class-my-fluentcrm-custom-rules.php';

/**
 * Initialize custom FluentCRM features.
 */
function my_plugin_initialize_fluentcrm_customizations() {
    // Check if FluentCRM core functions exist before proceeding
    if (!function_exists('FluentCrm')) {
        return;
    }

    // Instantiate and register rules
    (new \MyPlugin\FluentCrmFilters\MyFluentCrmCustomRules())->register();
}
// Use 'plugins_loaded' or 'init'. 'plugins_loaded' is generally safer for integrations.
// Use a priority > 10 if FluentCRM loads its own things on the default priority.
add_action('plugins_loaded', 'my_plugin_initialize_fluentcrm_customizations', 20);
```

---

## Step 7: Namespace Considerations

As mentioned, if your class uses a namespace, remember to prefix calls to global WordPress functions and classes with a backslash (`\`).

```php
// Inside a namespaced class:
$posts = \get_posts(...);
\add_action(...);
$wpdb = \_db_get_wpdb(); // Access global $wpdb
```

---

## Conclusion

Adding custom advanced filters requires understanding FluentCRM's hook system and data structures. By following these steps, you can create powerful, tailored segmentation rules:

1.  Define the rule structure(s).
2.  Hook into `fluentcrm_advanced_filter_options` to add the definition(s) to a new or existing group.
3.  Hook into `fluentcrm_contacts_filter_{group_key}` to implement the query logic.
4.  Provide options for `selections` type either directly (`'options'`) or via AJAX (`'option_key'` / derived hook + handler).
5.  Register your class correctly using `plugins_loaded` or `init`.

Remember to test thoroughly, especially when modifying existing filter groups, and consult the FluentCRM source code or documentation when needed. Good luck!
