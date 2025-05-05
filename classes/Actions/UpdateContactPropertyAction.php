<?php
/**
 * UpdateContactPropertyAction
 *
 * @package CustomCRM
 */

namespace CustomCRM\Actions;

use FluentCrm\App\Models\CustomContactField;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

/**
 * Class UpdateContactPropertyAction
 *
 * @package CustomCRM\Actions
 */
class UpdateContactPropertyAction extends BaseAction {

	/**
	 * UpdateContactPropertyAction constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->actionName = 'update_contact_property';
		$this->priority   = 100;
		parent::__construct();
	}

	/**
	 * Get the block
	 *
	 * @return array<string,string|array<string,mixed>>
	 */
	public function getBlock() {
		return [
			'category'    => __( 'CRM', 'fluentcampaign-pro' ),
			'title'       => __( 'Update Contact Property (Fixed)', 'fluentcampaign-pro' ),
			'description' => __( 'Update custom fields or few main property of a contact', 'fluentcampaign-pro' ),
			'icon'        => 'fc-icon-wp_user_meta', // fluentCrmMix('images/funnel_icons/contact_update.svg'),
			'settings'    => [
				'contact_properties' => [
					[
						'data_key'   => '',
						'data_value' => '',
					],
				],
			],
		];
	}

	/**
	 * Get the block fields
	 *
	 * @return array<string,string|array<string,mixed>>
	 */
	public function getBlockFields() {
		return [
			'title'     => __( 'Update Contact Property', 'fluentcampaign-pro' ),
			'sub_title' => __( 'Update custom fields or few main property of a contact', 'fluentcampaign-pro' ),
			'fields'    => [
				'contact_properties' => [
					'type'               => 'input_value_pair_properties',
					'support_operations' => 'yes',
					'label'              => __( 'Setup contact properties that you want to update', 'fluentcampaign-pro' ),
					'data_key_label'     => __( 'Contact Property', 'fluentcampaign-pro' ),
					'data_value_label'   => __( 'Property Value', 'fluentcampaign-pro' ),
					'property_options'   => $this->getContactProperties(),
				],
			],
		];
	}

	/**
	 * Handle the action
	 *
	 * @param FluentCrm\App\Models\Subscriber $subscriber
	 * @param FluentCrm\App\Models\Funnel     $sequence
	 * @param int                             $funnelSubscriberId
	 * @param mixed                           $funnelMetric
	 *
	 * @return void
	 */
	public function handle( $subscriber, $sequence, $funnelSubscriberId, $funnelMetric ) {
		$valueKeyPairs = [];
		$inputValues   = $sequence->settings['contact_properties'];

		$fieldOperations = [];
		foreach ( $inputValues as $pair ) {
			if ( empty( $pair['data_key'] ) ) {
				continue;
			}
			if ( ! empty( $pair['data_operation'] ) ) {
				$fieldOperations[ $pair['data_key'] ] = [
					'value'     => $pair['data_value'],
					'operation' => $pair['data_operation'],
				];
			} else {
				$valueKeyPairs[ $pair['data_key'] ] = $pair['data_value'];
			}
		}

		if ( ! $valueKeyPairs && ! $fieldOperations ) {
			FunnelHelper::changeFunnelSubSequenceStatus( $funnelSubscriberId, $sequence->id, 'skipped' );
			return false;
		}

		$customValues    = [];
		$customFieldKeys = [];
		$customFields    = ( new CustomContactField() )->getGlobalFields()['fields'];

		foreach ( $customFields as $field ) {
			$slug              = $field['slug'];
			$customFieldKeys[] = $slug;

			if ( $fieldOperations && isset( $fieldOperations[ $slug ] ) ) {
				// We have to validate if it's number or not
				if ( ! in_array( $field['type'], [ 'number', 'checkbox', 'select-multi' ] ) ) {
					$valueKeyPairs[ $slug ] = $fieldOperations[ $slug ]['value'];
					unset( $fieldOperations[ $slug ] );
				} elseif ( $field['type'] == 'number' ) {
						$fieldOperations[ $slug ]['data_type'] = 'number';
				} else {
					$fieldOperations[ $slug ]['data_type']     = 'array';
					$fieldOperations[ $slug ]['valid_options'] = $field['options'];
				}
			}
		}

		if ( $fieldOperations ) {
			foreach ( $fieldOperations as $key => $operation ) {
				if ( ! in_array( $key, $customFieldKeys ) ) {
					continue;
				}

				$existingValue = $subscriber->getMeta( $key, 'custom_field' );
				if ( false === $existingValue || is_null( $existingValue ) || '' === $existingValue ) {
					$valueKeyPairs[ $key ] = $fieldOperations[ $key ]['value'];
					continue;
				}

				if ( $operation['data_type'] == 'number' ) {
					if ( $operation['operation'] == 'subtract' ) {
						$newValue = $existingValue - intval( $operation['value'] );
					} else {
						$newValue = $existingValue + intval( $operation['value'] );
					}
				} else {
					$options         = (array) $operation['valid_options'];
					$providedOptions = (array) $operation['value'];
					$providedOptions = array_intersect( $options, $providedOptions );
					$existingOptions = (array) $existingValue;

					if ( $operation['operation'] == 'subtract' ) {
						$newValue = array_diff( $existingOptions, $providedOptions );
					} else {
						$newValue = array_unique( array_merge( $existingOptions, $providedOptions ) );
					}
				}

				// $subscriber->updateMeta( $key, $newValue, 'custom_field' ); // should be?
				$valueKeyPairs[ $key ] = $newValue;
			}
		}

		if ( $customFieldKeys ) {
			$customFieldsData = Arr::only( $valueKeyPairs, $customFieldKeys );
			// $customFieldsData = array_filter($customFieldsData);
			if ( $customFields ) {
				$customValues = $this->formatCustomFieldValues( $customFieldsData );
			}
		}

		$mainFields = array_filter( Arr::except( $valueKeyPairs, $customFieldKeys ) );

		$customValuesUpdates = [];
		if ( $customValues ) {
			$customValuesUpdates = $subscriber->syncCustomFieldValues( $customValues, false );
		}

		$updateFields = [];

		if ( $mainFields ) {
			$subscriber->fill( $mainFields );
			$updateFields = $subscriber->getDirty();

			if ( $updateFields ) {
				$subscriber->save();
			}
		}

		if ( $customValuesUpdates || $updateFields ) {
			do_action( 'fluentcrm_contact_updated', $subscriber, $updateFields );
			do_action( 'fluent_crm/contact_updated', $subscriber, $updateFields );
		}
	}

	/**
	 * Get the contact properties
	 *
	 * @return array<string,string|array<string,mixed>>
	 */
	private function getContactProperties() {
		$types                 = \fluentcrm_contact_types();
		$formattedContactTypes = [];

		foreach ( $types as $type => $label ) {
			$formattedContactTypes[] = [
				'id'    => $type,
				'slug'  => $type,
				'title' => $label,
			];
		}

		$fields = [
			'contact_type' => [
				'label'   => __( 'Contact Type', 'fluentcampaign-pro' ),
				'type'    => 'select',
				'options' => $formattedContactTypes,
			],
			'source'       => [
				'label'       => __( 'Contact Source', 'fluentcampaign-pro' ),
				'type'        => 'text',
				'placeholder' => __( 'Contact Source', 'fluentcampaign-pro' ),
			],
			'country'      => [
				'label'      => __( 'Country', 'fluentcampaign-pro' ),
				'type'       => 'option_selector',
				'option_key' => 'countries',
				'multiple'   => false,
			],
		];

		$customFields = fluentcrm_get_option( 'contact_custom_fields', [] );

		$validTypes      = [ 'text', 'date', 'textarea', 'date_time', 'number', 'select-one', 'select-multi', 'radio', 'checkbox' ];
		$formattedFields = [];
		foreach ( $customFields as $customField ) {
			$customType = $customField['type'];

			if ( ! in_array( $customType, $validTypes ) ) {
				continue;
			}

			$fieldType = $customType;

			$options = [];

			if ( in_array( $customType, [ 'select-one', 'select-multi', 'radio', 'checkbox' ] ) ) {
				$fieldType = 'select';
				$options   = [];
				foreach ( $customField['options'] as $option ) {
					$options[] = [
						'id'    => $option,
						'slug'  => $option,
						'title' => $option,
					];
				}
			}

			$formattedFields[ $customField['slug'] ] = [
				'label' => $customField['label'],
				'type'  => $fieldType,
			];

			if ( $fieldType == 'select' ) {
				$formattedFields[ $customField['slug'] ]['options']  = $options;
				$formattedFields[ $customField['slug'] ]['multiple'] = $customType == 'select-multi' || $customType == 'checkbox';
			}
		}

		if ( $formattedFields ) {
			return $fields + $formattedFields;
		}

		return $fields;
	}


	/* Fixes for current version of FluentCRM instance of this method */
	protected $globalMetaName = 'contact_custom_fields';

	/**
	 * Format custom field values
	 *
	 * @param array<string,mixed> $values
	 * @param array<string,mixed> $fields
	 *
	 * @return array<string,mixed>
	 */
	public function formatCustomFieldValues( $values, $fields = [] ) {
		if ( ! $values ) {
			return $values;
		}
		if ( ! $fields ) {
			$rawFields = fluentcrm_get_option( $this->globalMetaName, [] );
			foreach ( $rawFields as $field ) {
				$fields[ $field['slug'] ] = $field;
			}
		}

		foreach ( $values as $valueKey => $value ) {
			$isArrayType = Arr::get( $fields, $valueKey . '.type' ) == 'checkbox' || Arr::get( $fields, $valueKey . '.type' ) == 'select-multi';

			if ( ! is_array( $value ) && $isArrayType ) {
				$itemValues    = explode( ',', $value );
				$trimmedvalues = [];
				foreach ( $itemValues as $itemValue ) {
					$trimmedvalues[] = trim( $itemValue );
				}
				if ( $itemValue ) {
					$values[ $valueKey ] = $trimmedvalues;
				}
			}
		}

		return array_filter($values, function ( $item ) {
			return $item !== null;
		});
	}
}
