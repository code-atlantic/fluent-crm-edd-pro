<?php
/**
 * Fix SmartLink redirects.
 *
 * @package CustomCRM
 */

namespace CustomCRM;

use FluentCampaign\App\Models\SmartLink;
use FluentCrm\Framework\Support\Arr;

/**
 * Class FixSmartLinkRedirects
 *
 * Purpose: This class is used to fix the SmartLink redirects to pass the query parameters to the target URL.
 *
 * @package CustomCRM
 */
class SmartLinkHandler extends \FluentCampaign\App\Hooks\Handlers\SmartLinkHandler {

	/**
	 * Handle the smart link click.
	 *
	 * @param string $slug The smart link slug.
	 * @param null   $contact The contact object.
	 *
	 * @return void
	 */
	public function handleClick( $slug, $contact = null ) {
		$smartLink = SmartLink::where( 'short', $slug )->first();

		if ( ! $smartLink ) {
			return;
		}

		if ( ! $contact ) {
			$contact = fluentcrm_get_current_contact();
		}

		// Increment click count.
		if ( $contact ) {
			// Here to preserve original order. If this $smartLink->save();
			// doesn't need to be done before tags/lists, then this can be merged,
			// and the save() can be done at the end.
			++$smartLink->contact_clicks;
		}

		++$smartLink->all_clicks;
		$smartLink->save();

		if ( $contact ) {
			$tags        = Arr::get( $smartLink->actions, 'tags' );
			$lists       = Arr::get( $smartLink->actions, 'lists' );
			$removeTags  = Arr::get( $smartLink->actions, 'remove_tags' );
			$removeLists = Arr::get( $smartLink->actions, 'remove_lists' );

			// Perform actions based on smart link settings.
			if ( $tags ) {
				$contact->attachTags( $tags );
			}
			if ( $lists ) {
				$contact->attachLists( $lists );
			}
			if ( $removeTags ) {
				$contact->detachTags( $removeTags );
			}
			if ( $removeLists ) {
				$contact->detachLists( $removeLists );
			}

			if ( 'yes' === Arr::get( $smartLink->actions, 'auto_login' ) ) {
				$this->makeAutoLogin( $contact );
			}
		}

		$targetUrl = $this->getTargetUrl( $smartLink, $contact );

		do_action( 'fluent_crm/smart_link_clicked_by_contact', $smartLink, $contact );
		nocache_headers();
		wp_safe_redirect( $targetUrl, 307 );
		exit();
	}

	/**
	 * Get the target URL for the smart link with query parameters preserved.
	 *
	 * @param SmartLink                     $smartLink The smart link object.
	 * @param \FluentCrm\App\Models\Contact $contact The contact object.
	 *
	 * @return string The target URL with query parameters preserved.
	 */
	public function getTargetUrl( $smartLink, $contact ) {
		$ignored_params = [ 'fluentcrm', 'route', 'slug' ]; // Define the parameters to ignore.
		$queryParams    = array_diff_key( $_GET, array_flip( $ignored_params ) ); // Filter out ignored parameters.
		$queryString    = http_build_query( $queryParams ); // Build the query string from remaining parameters.

		$targetUrl = $smartLink->target_url;

		// Apply dynamic content if needed and redirect.
		if ( strpos( $targetUrl, '{{' ) ) {
			$targetUrl = apply_filters( 'fluent_crm/parse_campaign_email_text', $targetUrl, $contact );
			$targetUrl = esc_url_raw( $targetUrl );
		}

		if ( strpos( $targetUrl, '?' ) === false ) {
			$targetUrl .= '?';
		} else {
			$targetUrl .= '&';
		}

		return $targetUrl . $queryString;
	}

	/**
	 * Make the contact auto-login.
	 *
	 * @param \FluentCrm\App\Models\Contact $contact The contact object.
	 *
	 * @return bool True if the contact was auto-logged in, false otherwise.
	 */
	private function makeAutoLogin( $contact ) {
		if ( is_user_logged_in() ) {
			return false;
		}

		$user = get_user_by( 'email', $contact->email );

		if ( ! $user ) {
			return false;
		}

		$willAllowLogin = apply_filters( 'fluent_crm/will_make_auto_login', did_action( 'fluent_crm/smart_link_verified' ), $contact );
		if ( ! $willAllowLogin ) {
			return false;
		}

		if ( $user->has_cap( 'publish_posts' ) && ! apply_filters( 'fluent_crm/enable_high_level_auto_login', false, $contact ) ) {
			return false;
		}

		$currentContact = fluentcrm_get_current_contact();

		if ( ! $currentContact || $currentContact->id != $contact->id ) {
			return false;
		}

		add_filter( 'authenticate', [ $this, 'allowProgrammaticLogin' ], 10, 3 );    // hook in earlier than other callbacks to short-circuit them
		$user = wp_signon([
			'user_login'    => $user->user_login,
			'user_password' => '',
			]
		);
		remove_filter( 'authenticate', [ $this, 'allowProgrammaticLogin' ], 10, 3 );

		if ( $user instanceof \WP_User ) {
			wp_set_current_user( $user->ID, $user->user_login );
			if ( is_user_logged_in() ) {
				return true;
			}
		}

		return false;
	}
}
