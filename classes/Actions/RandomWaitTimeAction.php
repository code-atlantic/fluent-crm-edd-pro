<?php
/**
 * RandomWaitTimeAction
 *
 * @package CustomCRM\Actions
 */

namespace CustomCRM\Actions;

use FluentCrm\Framework\Support\Arr;

/**
 * Class RandomWaitTimeAction
 *
 * @package CustomCRM\Actions
 */
class RandomWaitTimeAction extends \FluentCrm\App\Services\Funnel\Actions\WaitTimeAction {

	/**
	 * RandomWaitTimeAction constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct();
		$this->priority = 11;

		add_filter( 'fluent_crm/funnel_seq_delay_in_seconds', [ $this, 'setDelayInSeconds' ], 10, 4 );

		add_filter( 'fluentcrm_funnel_sequence_saving_' . $this->actionName, [ $this, 'savingAction' ], 10, 2 );
	}

	/**
	 * Get the block for the action.
	 *
	 * @return array<string,string|array>
	 */
	public function getBlock() {
		$block = parent::getBlock();

		$customizeBlock = [
			'settings' => [
				'wait_time_amount'     => 1,
				'wait_time_amount_min' => '',
				'wait_time_amount_max' => '',
			],
		];

		return array_merge_recursive( $block, $customizeBlock );
	}

	/**
	 * Save the sequence settings.
	 *
	 * @param array<string,string|array> $sequence
	 * @param array<string,string|array> $funnel
	 *
	 * @return array<string,string|array>
	 */
	public function savingAction( $sequence, $funnel ) {
		$min  = Arr::get( $sequence, 'settings.wait_time_amount_min' );
		$max  = Arr::get( $sequence, 'settings.wait_time_amount_max' );
		$unit = Arr::get( $sequence, 'settings.wait_time_unit' );

		if ( $min >= 0 & $max > 0 ) {
			$sequence['settings']['wait_time_amount'] = $max;

			$maxDelay = 0;

			if ( $unit === 'hours' ) {
				$maxDelay = $max * 60 * 60;
			} elseif ( $unit === 'minutes' ) {
				$maxDelay = $max * 60;
			} elseif ( $unit === 'days' ) {
				$maxDelay = $max * 60 * 60 * 24;
			}

			$sequence['delay'] = $maxDelay;
		}

		return $sequence;
	}

	/**
	 * Get the action settings.
	 *
	 * @param array<string,string|array> $sequence
	 * @param array<string,string|array> $funnel
	 *
	 * @return array<string,string|array>
	 */
	public function gettingAction( $sequence, $funnel ) {
		$sequence = parent::gettingAction( $sequence, $funnel );

		return $sequence;
	}

	/**
	 * Get the block fields for the action.
	 *
	 * @return array<string,string|array>
	 */
	public function getBlockFields() {
		$blockFields = parent::getBlockFields();

		$blockFields['fields']['wait_type']['options'][0]['title'] = __( 'Wait for fixed or random period', 'fluent-crm' );

		$blockFields['fields']['wait_time_unit']['options'] = array_merge(
			[
				[
					'id'    => 'months',
					'title' => __( 'Months', 'fluent-crm' ),
				],
				[
					'id'    => 'weeks',
					'title' => __( 'Weeks', 'fluent-crm' ),
				],
			],
			$blockFields['fields']['wait_time_unit']['options'],
			[
				[
					'id'    => 'seconds',
					'title' => __( 'Seconds', 'fluent-crm' ),
				],
			]
		);

		// Insert our min/max fields after the first field using array_splice
		$blockFields['fields'] = array_merge(
			array_slice( $blockFields['fields'], 0, 2 ), // First part, up to the first item inclusive
			[
				'wait_time_amount_min' => [
					'label'         => __( 'Random Delay - Min', 'fluent-crm' ),
					'type'          => 'input-number',
					'wrapper_class' => 'fc_2col_inline pad-r-20',
					'inline_help'   => __( 'Set min for random delay.', 'fluent-crm' ),
					'dependency'    => [
						'depends_on' => 'wait_type',
						'value'      => 'unit_wait',
						'operator'   => '=',
					],
				],
				'wait_time_amount_max' => [
					'label'         => __( 'Random Delay - Max', 'fluent-crm' ),
					'type'          => 'input-number',
					'wrapper_class' => 'fc_2col_inline pad-r-20',
					'inline_help'   => __( 'Max required for random delay.', 'fluent-crm' ),
					'dependency'    => [
						'depends_on' => 'wait_type',
						'value'      => 'unit_wait',
						'operator'   => '=',
					],
				],
			],
			array_slice( $blockFields['fields'], 2 ) // Remaining part, from the second item to the end
		);

		// Relabel the Wait Time field
		// $blockFields['fields']['wait_time_amount']['label'] = __( 'Delay', 'fluent-crm' );

		return $blockFields;
	}

	/**
	 * Set the delay in seconds for the sequence.
	 *
	 * @param int                                  $delayInSeconds
	 * @param array<string,string|int>             $settings
	 * @param \FluentCrm\App\Models\FunnelSequence $sequence
	 * @param int                                  $funnelSubscriberId
	 *
	 * @return int
	 */
	public function setDelayInSeconds( $delayInSeconds, $settings, $sequence, $funnelSubscriberId ) {
		$delay  = Arr::get( $settings, 'wait_time_amount', null );
		$min  = Arr::get( $settings, 'wait_time_amount_min', null );
		$max  = Arr::get( $settings, 'wait_time_amount_max', 0 );
		$unit = Arr::get( $settings, 'wait_time_unit' );

		$waitTimes = $delay;

		if ( $min >= 0 & $max > 0 ) {
			if ( 'minutes' === $unit || 'seconds' === $unit ) {
				// Crons run at minute intervals, so we need to stick with whole minutes.
				$waitTimes = rand( $min, $max );
			} else {
				// Everything else can be more granular.
				$waitTimes = rand( $min * 100, $max * 100 ) / 100;
			}
		}

		if ( $unit === 'hours' ) {
			$waitTimes = $waitTimes * 60 * 60;
		} elseif ( $unit === 'minutes' ) {
			$waitTimes = $waitTimes * 60;
		} elseif ( $unit === 'days' ) {
			$waitTimes = $waitTimes * 60 * 60 * 24;
		} elseif ( $unit === 'weeks' ) {
			$waitTimes = $waitTimes * 60 * 60 * 24 * 7;
		} elseif ( $unit === 'months' ) {
			$waitTimes = $waitTimes * 60 * 60 * 24 * (365 / 12);
		}

		if ( $waitTimes !== $delayInSeconds ) {
			// Track the random time as an event for debugging.
			\FluentCrmApi( 'event_tracker' )->track([
				'event_key' => 'random_wait_time', // Required
				'title'     => 'Randomized Wait Time', // Required
				'value'     => json_encode([
					'next_sequence' => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $waitTimes ),
					'delay'         => $waitTimes,
				]),
				'email'     => 'daniel@code-atlantic.com',
				'provider'  => 'debug', // If left empty, 'custom' will be added.
			], false);
		}

		return $waitTimes;
	}
}
