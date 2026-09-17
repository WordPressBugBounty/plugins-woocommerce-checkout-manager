<?php

namespace QuadLayers\WOOCCM\Controller;

use QuadLayers\WOOCCM\Plugin;
use QuadLayers\WOOCCM\Upload;

/**
 * Checkout Class
 */
class My_Account {

	protected static $_instance;

	public function __construct() {
		add_action( 'show_user_profile', array( $this, 'show_files_in_user_profile' ) );

		add_action( 'wp_ajax_wooccm_customer_attachment_update', array( $this, 'ajax_delete_attachment' ) );

		// Security Fix: CVE-2026-17031 - Validate file field attachment IDs saved from the edit address form.
		add_filter( 'woocommerce_billing_fields', array( $this, 'add_file_fields_validation' ), PHP_INT_MAX );
		add_filter( 'woocommerce_shipping_fields', array( $this, 'add_file_fields_validation' ), PHP_INT_MAX );
		// Security Fix: CVE-2025-12500 - Removed nopriv hook to prevent unauthenticated file deletion
		// add_action( 'wp_ajax_nopriv_wooccm_customer_attachment_update', array( $this, 'ajax_delete_attachment' ) );

		add_action(
			'woocommerce_after_edit_address_form_billing',
			function () {
				$this->add_upload_files( 'billing' );}
		);
		add_action(
			'woocommerce_after_edit_address_form_shipping',
			function () {
				$this->add_upload_files( 'shipping' ); }
		);
	}

	public function ajax_delete_attachment() {
		// Security Fix: CVE-2025-12500 - Added proper authorization checks

		// Step 1: Verify nonce for CSRF protection
		if ( ! check_admin_referer( 'wooccm_upload', 'nonce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Security check failed.', 'woocommerce-checkout-manager' ) ) );
		}

		// Step 2: Verify user is authenticated
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in to delete attachments.', 'woocommerce-checkout-manager' ) ) );
		}

		$array1 = Upload::parse_attachment_ids( isset( $_REQUEST['all_attachments_ids'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['all_attachments_ids'] ) ) : '' );
		$array2 = Upload::parse_attachment_ids( isset( $_REQUEST['delete_attachments_ids'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['delete_attachments_ids'] ) ) : '' );

		$attachment_ids = array_diff( $array1, $array2 );
		if ( ! empty( $attachment_ids ) ) {

			$user_id = get_current_user_id();

			// Security Fix: CVE-2026-17031 - Only delete files uploaded by the current customer, or legacy plugin uploads saved in the
			// customer file fields, and never files attached to objects of other users.
			$file_fields_meta = array();
			foreach ( $this->get_file_field_keys() as $field_key ) {
				$file_fields_meta[ $field_key ] = Upload::parse_attachment_ids( get_user_meta( $user_id, $field_key, true ) );
			}

			$saved_ids = empty( $file_fields_meta ) ? array() : call_user_func_array( 'array_merge', array_values( $file_fields_meta ) );

			$attachments_to_remove = array();
			foreach ( $attachment_ids as $attachment_id ) {
				if ( Upload::is_current_user_upload( $attachment_id ) && ! Upload::is_attached_to_foreign_object( $attachment_id ) ) {
					$attachments_to_remove[] = $attachment_id;
				} elseif ( in_array( $attachment_id, $saved_ids, true ) && Upload::is_current_user_legacy_upload( $attachment_id ) ) {
					$attachments_to_remove[] = $attachment_id;
				}
			}

			foreach ( $attachments_to_remove as $attachment_id ) {
				wp_delete_attachment( $attachment_id );
			}

			// Remove the deleted files from the customer file fields.
			foreach ( $file_fields_meta as $field_key => $field_ids ) {
				$remaining_ids = array_diff( $field_ids, $attachments_to_remove );
				if ( count( $remaining_ids ) !== count( $field_ids ) ) {
					update_user_meta( $user_id, $field_key, implode( ',', $remaining_ids ) );
				}
			}

			wp_send_json_success( esc_html__( 'Deleted successfully.', 'woocommerce-checkout-manager' ) );
		}
	}

	/**
	 * Get the keys of the billing and shipping fields of type file.
	 *
	 * @return string[]
	 */
	protected function get_file_field_keys() {
		$keys   = array();
		$fields = array_merge( Plugin::instance()->billing->get_fields(), Plugin::instance()->shipping->get_fields() );

		foreach ( $fields as $field ) {
			if ( isset( $field['type'], $field['key'] ) && 'file' === $field['type'] ) {
				$keys[] = $field['key'];
			}
		}

		return array_unique( $keys );
	}

	/**
	 * Register the validation of the file fields values processed in the edit address form.
	 *
	 * @param array $fields Address fields.
	 * @return array
	 */
	public function add_file_fields_validation( $fields ) {
		if ( is_array( $fields ) ) {
			foreach ( $fields as $key => $field ) {
				if ( isset( $field['type'] ) && 'file' === $field['type'] && ! has_filter( 'woocommerce_process_myaccount_field_' . $key, array( $this, 'validate_file_field_value' ) ) ) {
					add_filter( 'woocommerce_process_myaccount_field_' . $key, array( $this, 'validate_file_field_value' ) );
				}
			}
		}

		return $fields;
	}

	/**
	 * Remove attachment IDs that the current customer is not allowed to save in a file field.
	 *
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function validate_file_field_value( $value ) {
		$user_id = get_current_user_id();
		$key     = str_replace( 'woocommerce_process_myaccount_field_', '', current_filter() );

		if ( ! $user_id ) {
			return '';
		}

		$saved_ids   = Upload::parse_attachment_ids( get_user_meta( $user_id, $key, true ) );
		$allowed_ids = array();

		foreach ( Upload::parse_attachment_ids( $value ) as $attachment_id ) {
			if ( Upload::is_current_user_upload( $attachment_id ) ) {
				$allowed_ids[] = $attachment_id;
			} elseif ( in_array( $attachment_id, $saved_ids, true ) && Upload::is_current_user_legacy_upload( $attachment_id ) ) {
				$allowed_ids[] = $attachment_id;
			}
		}

		return implode( ',', $allowed_ids );
	}

	public function add_upload_files( $page ) {
		if ( get_option( 'wooccm_order_upload_files', 'no' ) === 'yes' ) {
			$user_id = get_current_user_id();

			$fields = array();
			if ( 'billing' === $page ) {
				$fields = Plugin::instance()->billing->get_fields();
			} elseif ( 'shipping' === $page ) {
				$fields = Plugin::instance()->shipping->get_fields();
			}

			$attachments = array();
			foreach ( $fields as $field_id => $field ) {
				if ( 'file' === $field['type'] ) {
					$user_meta = get_user_meta( $user_id, $field['key'], true );
					if ( ! empty( $user_meta ) ) {
						$attachments[ $field['key'] ] = array(
							'field_label'   => $field['label'],
							'attachment_id' => $user_meta,
						);
					}
				}
			}

			wc_get_template(
				'templates/my-account/form-edit-address-images.php',
				array(
					'user_id'     => $user_id,
					'attachments' => $attachments,
				),
				'',
				WOOCCM_PLUGIN_DIR
			);
		}
	}

	public function show_files_in_user_profile() {
		if ( get_option( 'wooccm_order_upload_files', 'no' ) === 'yes' ) {
			$user_id = get_current_user_id();

			$fields = array_merge( Plugin::instance()->billing->get_fields(), Plugin::instance()->shipping->get_fields() );

			$attachments = array();
			foreach ( $fields as $field_id => $field ) {
				if ( 'file' === $field['type'] ) {
					$user_meta = get_user_meta( $user_id, $field['key'], true );
					if ( ! empty( $user_meta ) ) {
						$attachments[ $field['key'] ] = array(
							'field_label'   => $field['label'],
							'attachment_id' => $user_meta,
						);
					}
				}
			}

			wc_get_template(
				'lib/view/backend/admin-menu/user-files.php',
				array(
					'user_id'     => $user_id,
					'attachments' => $attachments,
				),
				'',
				WOOCCM_PLUGIN_DIR
			);
		}
	}

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}
}
