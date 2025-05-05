<?php

/**
 * Fix SmartLink redirects.
 *
 * @package CustomCRM
 */

namespace CustomCRM;

class Webhooks {
    public function __construct() {
        add_filter('fluent_crm/incoming_webhook_data', [$this, 'incoming_webhook_data'], 10, 3);
    }

    /*
     * Customize Webhook data for webhook id: 61
     */
    public function incoming_webhook_data($postedData, $webhook, $request) {
        if ($webhook->id != 61) {
            return $postedData;
        }

        // Remapt the data to match the custom contact fields.
        $postedData['demo_magic_login_url'] = isset($postedData['magic_login']) ? $this->correct_broken_url( $postedData['magic_login'] ) : '';
        $postedData['demo_site_admin']      = isset($postedData['site_admin']) ? sanitize_user($postedData['site_admin']) : '';
        $postedData['demo_site_password']   = isset($postedData['site_password']) ? sanitize_key($postedData['site_password']) : '';
        $postedData['demo_site_url']        = isset($postedData['site_url']) ?  $postedData['site_url'] : '';
        $postedData['demo_site_template']   = isset($postedData['template_slug']) ? sanitize_key($postedData['template_slug']) : '';
        // Create new datetime field from created_date and created_time
        $postedData['demo_site_created']    = isset($postedData['created_date']) ? date('Y-m-d H:i:s', strtotime($postedData['created_date'] . ' ' . $postedData['created_time'])) : '';

        return $postedData;
    }

    public function correct_broken_url( $url ) {
        // Define the correct base and path components
        $correct_base = 'https://app.instawp.io';
        $correct_path = '/wordpress-auto-login';
    
        // Check if the URL is broken by matching the incorrect structure
        if ( preg_match( '#^https://app\.instawp\.io\?site=([^&]+)(/wordpress-auto-login&redir=/)$#', $url, $matches ) ) {
            // Reconstruct the correct URL
            $correct_url = $correct_base . $correct_path . '?site=' . $matches[1] . '&redir=/';
            return $correct_url;
        }
    
        // If the URL is not broken, return it as is
        return $url;
    }
}
