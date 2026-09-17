<?php

namespace QuadLayers\WOOCCM;

/**
 * Upload Class
 */
class Upload {

	protected static $_instance;

	/**
	 * Attachment meta key that marks a file uploaded through the plugin upload flow.
	 */
	const META_UPLOAD = '_wooccm_upload';

	/**
	 * Attachment meta key that stores the ID of the user that uploaded the file (0 for guests).
	 */
	const META_OWNER = '_wooccm_upload_owner';

	/**
	 * WooCommerce session key that stores the attachment IDs uploaded in the current session.
	 */
	const SESSION_KEY = 'wooccm_uploaded_attachments';

	/**
	 * Attachment IDs validated from the checkout posted data in the current request.
	 *
	 * @var int[]
	 */
	protected $checkout_attachment_ids = array();

	public function __construct() {
		add_action( 'wp_ajax_wooccm_order_attachment_update', array( $this, 'ajax_delete_attachment' ) );

		// Checkout
		// -----------------------------------------------------------------------.
		add_action( 'wp_ajax_wooccm_checkout_attachment_upload', array( $this, 'ajax_checkout_attachment_upload' ) );
		add_action( 'wp_ajax_nopriv_wooccm_checkout_attachment_upload', array( $this, 'ajax_checkout_attachment_upload' ) );
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'validate_checkout_attachment_ids' ), 5 );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'update_attachment_ids' ), 99 );
	}

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Parse a comma separated list (or array) of attachment IDs into unique positive integers.
	 *
	 * @param mixed $value Raw value.
	 * @return int[]
	 */
	public static function parse_attachment_ids( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ',', $value );
		}

		if ( ! is_scalar( $value ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ) ) );
	}

	/**
	 * Check that an attachment was created by the plugin upload flow.
	 *
	 * Files uploaded before provenance meta existed are recognized by their location in the wooccm_uploads directory.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_plugin_upload( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		if ( get_post_meta( $attachment_id, self::META_UPLOAD, true ) ) {
			return true;
		}

		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		return 0 === strpos( $file, 'wooccm_uploads/' );
	}

	/**
	 * Check that an attachment was created by the plugin upload flow before provenance meta existed.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_legacy_plugin_upload( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		return self::is_plugin_upload( $attachment_id ) && ! get_post_meta( $attachment_id, self::META_UPLOAD, true );
	}

	/**
	 * Check that an attachment was uploaded through the plugin by the current user or in the current WooCommerce session.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_current_user_upload( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || ! get_post_meta( $attachment_id, self::META_UPLOAD, true ) ) {
			return false;
		}

		$user_id = get_current_user_id();

		if ( $user_id && absint( get_post_meta( $attachment_id, self::META_OWNER, true ) ) === $user_id ) {
			return true;
		}

		// Files uploaded as guest and attached to an order that now belongs to the current customer.
		if ( self::is_attached_to_current_user_order( $attachment_id ) ) {
			return true;
		}

		return in_array( $attachment_id, self::get_session_attachment_ids(), true );
	}

	/**
	 * Check that a legacy plugin upload belongs to the current user.
	 *
	 * Legacy uploads have no provenance meta, so ownership is resolved from the attachment author or its parent order.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_current_user_legacy_upload( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$user_id       = get_current_user_id();

		if ( ! $user_id || ! self::is_legacy_plugin_upload( $attachment_id ) ) {
			return false;
		}

		if ( absint( get_post_field( 'post_parent', $attachment_id ) ) ) {
			return self::is_attached_to_current_user_order( $attachment_id );
		}

		return absint( get_post_field( 'post_author', $attachment_id ) ) === $user_id;
	}

	/**
	 * Check that an attachment is attached to an order that belongs to the current user.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_attached_to_current_user_order( $attachment_id ) {
		$post_parent = absint( get_post_field( 'post_parent', $attachment_id ) );
		$user_id     = get_current_user_id();

		if ( ! $post_parent || ! $user_id || ! in_array( get_post_type( $post_parent ), array( 'shop_order', 'shop_order_placehold' ), true ) ) {
			return false;
		}

		$order = wc_get_order( $post_parent );

		return $order && absint( $order->get_user_id() ) === $user_id;
	}

	/**
	 * Check that an attachment is attached to an order that does not belong to the current user.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_attached_to_foreign_object( $attachment_id ) {
		$post_parent = absint( get_post_field( 'post_parent', $attachment_id ) );

		if ( ! $post_parent ) {
			return false;
		}

		if ( ! in_array( get_post_type( $post_parent ), array( 'shop_order', 'shop_order_placehold' ), true ) ) {
			return true;
		}

		$order   = wc_get_order( $post_parent );
		$user_id = get_current_user_id();

		return ! $order || ! $user_id || absint( $order->get_user_id() ) !== $user_id;
	}

	/**
	 * Get the attachment IDs uploaded in the current WooCommerce session.
	 *
	 * @return int[]
	 */
	protected static function get_session_attachment_ids() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}

		return self::parse_attachment_ids( WC()->session->get( self::SESSION_KEY, array() ) );
	}

	/**
	 * Mark an attachment as uploaded through the plugin by the current user/session.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	protected function add_upload_provenance( $attachment_id ) {
		update_post_meta( $attachment_id, self::META_UPLOAD, 1 );
		update_post_meta( $attachment_id, self::META_OWNER, get_current_user_id() );

		if ( function_exists( 'WC' ) && WC()->session ) {
			$attachment_ids   = self::get_session_attachment_ids();
			$attachment_ids[] = absint( $attachment_id );
			WC()->session->set( self::SESSION_KEY, array_slice( array_unique( $attachment_ids ), -100 ) );
		}
	}

	protected function process_uploads( $files, $key, $post_id = 0 ) {
		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Security Fix: CVE-2025-12500 - Add upload limits
		$max_files_per_upload = apply_filters( 'wooccm_max_files_per_upload', 10 );
		$file_count           = is_array( $files['name'] ) ? count( $files['name'] ) : 0;

		if ( $file_count > $max_files_per_upload ) {
			wc_add_notice(
				sprintf(
					/* translators: %d: maximum number of files */
					esc_html__( 'You can only upload a maximum of %d files at once.', 'woocommerce-checkout-manager' ),
					$max_files_per_upload
				),
				'error'
			);
			return array();
		}

		$attachment_ids = array();

		add_filter(
			'upload_dir',
			function ( $param ) {
				$param['path'] = sprintf( '%s/wooccm_uploads', $param['basedir'] );
				$param['url']  = sprintf( '%s/wooccm_uploads', $param['baseurl'] );
				return $param;
			},
			10
		);

		foreach ( $files['name'] as $id => $value ) {

			if ( $files['name'][ $id ] ) {

				$_FILES[ $key ] = array(
					'name'     => $files['name'][ $id ],
					'type'     => $files['type'][ $id ],
					'tmp_name' => $files['tmp_name'][ $id ],
					'error'    => $files['error'][ $id ],
					'size'     => $files['size'][ $id ],
				);

				$attachment_id = media_handle_upload( $key, $post_id );

				if ( ! is_wp_error( $attachment_id ) ) {
					$this->add_upload_provenance( $attachment_id );
					$attachment_ids[] = $attachment_id;
				} else {
					wc_add_notice( $attachment_id->get_error_message(), 'error' );
					// wp_send_json_error( $attachment_id->get_error_message() );
				}
			}
		}

		return $attachment_ids;
	}

	public function ajax_delete_attachment() {
		if ( ! empty( $_REQUEST ) && check_admin_referer( 'wooccm_upload', 'nonce' ) ) {

			$array1 = self::parse_attachment_ids( isset( $_REQUEST['all_attachments_ids'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['all_attachments_ids'] ) ) : '' );
			$array2 = self::parse_attachment_ids( isset( $_REQUEST['delete_attachments_ids'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['delete_attachments_ids'] ) ) : '' );

			$attachment_ids = array_diff( $array1, $array2 );

			$current_user    = wp_get_current_user();
			$session_handler = WC()->session;

			// Security Fix: CVE-2025-13930 - Fixed inverted login check.
			$is_user_logged = 0 !== $current_user->ID;

			// phpcs:disable WordPress.WP.Capabilities.Unknown -- Capabilities registered by WooCommerce.
			$user_has_capabilities = $is_user_logged && (
				current_user_can( 'manage_options' )
				|| current_user_can( 'edit_others_shop_orders' )
				|| current_user_can( 'delete_others_shop_orders' )
			);
			// phpcs:enable WordPress.WP.Capabilities.Unknown

			// Validate every attachment before deleting any of them.
			$attachments_to_remove = array();

			foreach ( $attachment_ids as $attachtoremove ) {

				// Security Fix: CVE-2026-17031 - Customers can only delete files created by the plugin upload flow.
				if ( ! $user_has_capabilities && ! self::is_plugin_upload( $attachtoremove ) ) {
					continue;
				}

				// Check the Attachment is associated with an Order.
				$post_parent = absint( get_post_field( 'post_parent', $attachtoremove ) );

				if ( empty( $post_parent ) || ! in_array( get_post_type( $post_parent ), array( 'shop_order', 'shop_order_placehold' ), true ) ) {
					continue;
				}

				$order = wc_get_order( $post_parent );

				if ( ! $order ) {
					continue;
				}

				// For guest orders, require order key validation.
				if ( ! $is_user_logged ) {
					// Validate order key for guest orders.
					$order_key = isset( $_REQUEST['order_key'] ) ? wc_clean( wp_unslash( $_REQUEST['order_key'] ) ) : '';

					if ( empty( $order_key ) || ! hash_equals( $order->get_order_key(), $order_key ) ) {
						wp_send_json_error( esc_html__( 'Invalid order key.', 'woocommerce-checkout-manager' ) );
					}

					// Verify session email matches order email.
					$session_customer       = $session_handler ? $session_handler->get( 'customer' ) : array();
					$session_customer_email = isset( $session_customer['email'] ) ? $session_customer['email'] : '';
					$order_email            = $order->get_billing_email();

					if ( empty( $session_customer_email ) || $order_email !== $session_customer_email ) {
						wp_send_json_error( esc_html__( 'Email mismatch.', 'woocommerce-checkout-manager' ) );
					}
				} elseif ( ! $user_has_capabilities && $current_user->ID !== $order->get_user_id() ) {
					// For logged-in users, verify ownership or capabilities.
					wp_send_json_error( esc_html__( 'This is not your order.', 'woocommerce-checkout-manager' ) );
				}

				$attachments_to_remove[] = $attachtoremove;
			}

			foreach ( $attachments_to_remove as $attachtoremove ) {
				wp_delete_attachment( $attachtoremove );
			}

			wp_send_json_success( esc_html__( 'Deleted successfully.', 'woocommerce-checkout-manager' ) );
		}
	}

	public function ajax_checkout_attachment_upload() {
		// Security Fix: CVE-2025-12500 - Added proper authorization checks

		// Step 1: Verify nonce for CSRF protection
		if ( ! check_admin_referer( 'wooccm_upload', 'nonce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Security check failed.', 'woocommerce-checkout-manager' ) ) );
		}

		// Step 2: Verify files are present
		if ( ! isset( $_FILES['wooccm_checkout_attachment_upload'] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No files provided.', 'woocommerce-checkout-manager' ) ) );
		}

		// Step 3: Verify WooCommerce is available and ensure it's loaded
		if ( ! function_exists( 'WC' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'WooCommerce is not available.', 'woocommerce-checkout-manager' ) ) );
		}

		// Ensure WooCommerce is initialized
		$wc = WC();
		if ( ! $wc ) {
			wp_send_json_error( array( 'message' => esc_html__( 'WooCommerce session not initialized.', 'woocommerce-checkout-manager' ) ) );
		}

		// Step 4: Verify user is in checkout process (applies to ALL users - logged in and guests).
		// This ensures that any user (including subscribers, customers, etc.) must be actively
		// in the checkout process before they can upload files, preventing arbitrary file uploads.
		$is_in_checkout_process = false;

		// Check 1: Verify cart has items.
		$cart_count = ( $wc->cart ) ? $wc->cart->get_cart_contents_count() : 0;
		if ( $wc->cart && $cart_count > 0 ) {
			$is_in_checkout_process = true;
		}

		// Check 2: Verify WooCommerce session exists with customer data.
		if ( ! $is_in_checkout_process && $wc->session ) {
			$customer = $wc->session->get( 'customer' );
			// Customer data exists and has at least one field populated.
			if ( ! empty( $customer ) && is_array( $customer ) && count( array_filter( $customer ) ) > 0 ) {
				$is_in_checkout_process = true;
			}
		}

		// Check 3: For logged-in users, check if they have WooCommerce customer role or cart session.
		if ( ! $is_in_checkout_process && is_user_logged_in() ) {
			$current_user = wp_get_current_user();
			// Allow WooCommerce customers, shop managers, and administrators.
			if ( in_array( 'customer', $current_user->roles, true ) ||
				in_array( 'shop_manager', $current_user->roles, true ) ||
				in_array( 'administrator', $current_user->roles, true ) ) {
				// Verify they have an active WooCommerce session.
				if ( $wc->session && $wc->session->get_customer_id() ) {
					$is_in_checkout_process = true;
				}
			}
		}

		if ( ! $is_in_checkout_process ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please start checkout process before uploading files.', 'woocommerce-checkout-manager' ) ) );
		}

		// It cannot be wp_unslash becouse it has images paths.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$files = wc_clean( $_FILES['wooccm_checkout_attachment_upload'] );

		if ( empty( $files ) ) {
			wc_add_notice( esc_html__( 'No uploads were recognized. Files were not uploaded.', 'woocommerce-checkout-manager' ), 'error' );
			wp_send_json_error( array( 'message' => esc_html__( 'No uploads were recognized. Files were not uploaded.', 'woocommerce-checkout-manager' ) ) );
		}

		$attachment_ids = $this->process_uploads( $files, 'wooccm_checkout_attachment_upload' );

		if ( count( $attachment_ids ) ) {
			wp_send_json_success( $attachment_ids );
		}

		wc_add_notice( esc_html__( 'Unknown error.', 'woocommerce-checkout-manager' ), 'error' );
		wp_send_json_error( array( 'message' => esc_html__( 'Unknown error.', 'woocommerce-checkout-manager' ) ) );
	}

	/**
	 * Remove attachment IDs that the current customer is not allowed to use from the checkout file fields.
	 *
	 * Security Fix: CVE-2026-17031 - Attachment IDs submitted in checkout file fields are client controlled.
	 *
	 * @param array $data Checkout posted data.
	 * @return array
	 */
	public function validate_checkout_attachment_ids( $data ) {
		if ( ! is_array( $data ) || ! function_exists( 'WC' ) || ! WC()->checkout() ) {
			return $data;
		}

		$user_id = get_current_user_id();

		foreach ( WC()->checkout()->get_checkout_fields() as $fields ) {

			if ( ! is_array( $fields ) ) {
				continue;
			}

			foreach ( $fields as $key => $field ) {

				if ( ! isset( $field['type'] ) || 'file' !== $field['type'] || ! isset( $data[ $key ] ) ) {
					continue;
				}

				// Files previously saved in the customer address are allowed to be reused.
				$saved_ids = $user_id ? self::parse_attachment_ids( get_user_meta( $user_id, $key, true ) ) : array();

				$allowed_ids = array();

				foreach ( self::parse_attachment_ids( $data[ $key ] ) as $attachment_id ) {
					if ( self::is_current_user_upload( $attachment_id ) ) {
						$allowed_ids[] = $attachment_id;
					} elseif ( in_array( $attachment_id, $saved_ids, true ) && self::is_current_user_legacy_upload( $attachment_id ) ) {
						$allowed_ids[] = $attachment_id;
					}
				}

				$this->checkout_attachment_ids = array_merge( $this->checkout_attachment_ids, $allowed_ids );

				$data[ $key ] = implode( ',', $allowed_ids );
			}
		}

		return $data;
	}

	public function update_attachment_ids( $order_id = 0 ) {

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$checkout = WC()->checkout->get_checkout_fields();

		if ( count( $checkout ) ) {

			foreach ( $checkout as $field_type => $fields ) {

				foreach ( $fields as $key => $field ) {

					if ( isset( $field['type'] ) && 'file' === $field['type'] ) {

						$key = sprintf( '_%s', $field['key'] );

						$attachments = self::parse_attachment_ids( $order->get_meta( $key, true ) );

						foreach ( $attachments as $image_id ) {

							// Security Fix: CVE-2026-17031 - Only re-parent attachments validated for the current customer.
							if ( ! in_array( $image_id, $this->checkout_attachment_ids, true ) && ! self::is_current_user_upload( $image_id ) ) {
								continue;
							}

							if ( ! self::is_plugin_upload( $image_id ) ) {
								continue;
							}

							// Assign files uploaded as guest to the order customer, e.g. when the account is created at checkout.
							if ( get_post_meta( $image_id, self::META_UPLOAD, true ) && ! absint( get_post_meta( $image_id, self::META_OWNER, true ) ) && $order->get_user_id() ) {
								update_post_meta( $image_id, self::META_OWNER, absint( $order->get_user_id() ) );
							}

							if ( absint( get_post_field( 'post_parent', $image_id ) ) === absint( $order_id ) ) {
								continue;
							}

							wp_update_post(
								array(
									'ID'          => $image_id,
									'post_parent' => $order_id,
								)
							);

							wp_update_attachment_metadata( $image_id, wp_generate_attachment_metadata( $image_id, get_attached_file( $image_id ) ) );
						}
					}
				}
			}
		}
	}
}
