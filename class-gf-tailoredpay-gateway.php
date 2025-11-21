<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

GFForms::include_payment_addon_framework();

class GF_TailoredPay_Gateway extends GFPaymentAddOn {

	protected $_version              = GF_TAILOREDPAY_VERSION;
	protected $_slug                 = 'tailoredpay';
	protected $_title                = 'TailoredPay Gateway';
	protected $_short_title          = 'TailoredPay';
	protected $_requires_credit_card = false;
	protected $_supports_callbacks   = false;

	protected $is_payment_page_load = false;
	protected $payment_page_form    = null;
	protected $payment_page_entry   = null;
	protected $payment_page_error   = null;

	private static $_instance = null;

	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new GF_TailoredPay_Gateway();
		}
		return self::$_instance;
	}

	public function init() {
		parent::init();
		add_action( 'wp', array( $this, 'maybe_process_tailoredpay_page' ), 5 );
		add_filter( 'the_content', array( $this, 'maybe_render_payment_page' ) );
		add_action( 'wp_ajax_tailoredpay_process_payment', array( $this, 'handle_ajax_payment' ) );
		add_action( 'wp_ajax_nopriv_tailoredpay_process_payment', array( $this, 'handle_ajax_payment' ) );
		add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
	}


	/**
	 * Registers the REST API endpoint for receiving webhooks from TailoredPay.
	 */
	public function register_webhook_endpoint() {
		register_rest_route(
			'gf-tailoredpay/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'process_webhook' ),
				'permission_callback' => array( $this, 'verify_webhook_signature' ),
			)
		);
	}

	/**
	 * Verifies the signature of an incoming webhook to ensure it's from TailoredPay.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool|WP_Error True if the signature is valid.
	 */
	public function verify_webhook_signature( $request ) {
		$settings       = $this->get_plugin_settings();
		$webhook_secret = rgar( $settings, 'webhook_signing_secret' );

		if ( empty( $webhook_secret ) ) {
			error_log( 'TailoredPay Webhook Security: Signing secret is not configured.' );
			return false;
		}

		$signature_header = $request->get_header( 'webhook_signature' );
		if ( ! $signature_header ) {
			error_log( 'TailoredPay Webhook Security: Missing webhook_signature header.' );
			return false;
		}

		$parts     = explode( ',', $signature_header );
		$timestamp = '';
		$signature = '';

		foreach ( $parts as $part ) {
			list($key, $value) = explode( '=', $part, 2 );
			if ( $key === 't' ) {
				$timestamp = $value;
			} elseif ( $key === 's' ) {
				$signature = $value;
			}
		}

		if ( empty( $timestamp ) || empty( $signature ) ) {
			error_log( 'TailoredPay Webhook Security: Malformed signature header.' );
			return false;
		}

		$raw_body           = $request->get_body();
		$signed_payload     = $timestamp . '.' . $raw_body;
		$expected_signature = hash_hmac( 'sha256', $signed_payload, $webhook_secret );

		if ( hash_equals( $expected_signature, $signature ) ) {
			return true;
		}

		error_log( 'TailoredPay Webhook Security: Invalid signature.' );
		return false;
	}

	/**
	 * Handles incoming webhook requests from TailoredPay.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response
	 */
	public function process_webhook( $request ) {
		$payload    = $request->get_json_params();
		$event_type = isset( $payload['event_type'] ) ? $payload['event_type'] : 'unknown';
		$event_body = isset( $payload['event_body'] ) ? $payload['event_body'] : null;

		if ( ! $event_body ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'Missing event body.',
				),
				400
			);
		}

		$entry_id = isset( $event_body['merchant_defined_fields']['1'] ) ? intval( $event_body['merchant_defined_fields']['1'] ) : 0;
		if ( ! $entry_id ) {
			return new WP_REST_Response(
				array(
					'status'  => 'success',
					'message' => 'Webhook ignored: Entry ID not found.',
				),
				200
			);
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( ! $entry || is_wp_error( $entry ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'success',
					'message' => 'Entry not found.',
				),
				200
			);
		}

		// --- IMPROVEMENT: Check for final status before processing ---
		if ( in_array( rgar( $entry, 'payment_status' ), array( 'Paid', 'Failed' ), true ) ) {
			try {
				$webhook_transaction_id = rgar( $event_body, 'transaction_id' );
				$payment_method_details = rgar( $event_body, 'card' );
				if ( is_array( $payment_method_details ) && ! empty( $payment_method_details ) && ! empty( $webhook_transaction_id ) ) {
					$this->save_card_digits_with_validation( $entry, $payment_method_details, $webhook_transaction_id );
				}
			} catch ( Exception $e ) {
				error_log( 'ConvesioPay: Error in paid entry card validation: ' . $e->getMessage() );
			}
			return new WP_REST_Response(
				array(
					'status'  => 'success',
					'message' => 'Entry already processed.',
				),
				200
			);
		}

		$feed = $this->get_payment_feed( $entry );
		if ( ! $feed ) {
			error_log( 'ConvesioPay: Feed not found for entry ' . $entry_id );
			return new WP_Error( 'feed_not_found', 'Feed not found', array( 'status' => 404 ) );
		}

		$this->save_card_digits_to_field_from_webhook_safe( $event_body, $entry_id );
		$action = $this->process_webhook_action( $event_body, $feed, $entry );
		if ( is_wp_error( $action ) ) {
			error_log( 'ConvesioPay: Error processing webhook action: ' . $action->get_error_message() );
			return $action;
		}
			$result = $this->convesiopay_process_callback_action( $action );
		if ( is_wp_error( $result ) ) {
			error_log( 'ConvesioPay: Error processing callback action: ' . $result->get_error_message() );
			return $result;
		}

		// $transaction_id = rgar( $event_body, 'transaction_id' );
		// $amount         = rgar( $event_body['action'], 'amount', rgar( $entry, 'payment_amount' ) );
		// $response_text  = rgar( $event_body['action'], 'response_text' );

		// switch ( $event_type ) {
		// 	case 'transaction.sale.success':
		// 		$this->complete_payment(
		// 			$entry,
		// 			array(
		// 				'type'           => 'complete_payment',
		// 				'transaction_id' => $transaction_id,
		// 				'amount'         => $amount,
		// 				'payment_date'   => gmdate( 'Y-m-d H:i:s' ),
		// 			)
		// 		);
		// 		$this->add_note( $entry_id, 'Payment confirmed via webhook.' );
		// 		break;

		// 	case 'transaction.sale.failure':
		// 		// --- FIX: Create a detailed failure note for webhook ---
		// 		$note = sprintf(
		// 			'Payment failure via webhook. Amount: %1$s. Transaction ID: %2$s. Reason: %3$s',
		// 			GFCommon::to_money( $amount, $entry['currency'] ),
		// 			$transaction_id,
		// 			$response_text
		// 		);
		// 		$this->fail_payment(
		// 			$entry,
		// 			array(
		// 				'transaction_id' => $transaction_id,
		// 				'amount'         => $amount,
		// 				'note'           => $note,
		// 			)
		// 		);
		// 		break;
		// }

		return new WP_REST_Response( array( 'status' => 'success' ), 200 );
	}

	public function plugin_settings_fields() {
		return array(
			array(
				'title'  => 'TailoredPay Settings',
				'fields' => array(
					array(
						'name'          => 'environment',
						'label'         => 'Environment',
						'type'          => 'radio',
						'default_value' => 'test',
						'choices'       => array(
							array(
								'label' => 'Test',
								'value' => 'test',
							),
							array(
								'label' => 'Live',
								'value' => 'live',
							),
						),
					),
					array(
						'name'       => 'test_security_key',
						'label'      => 'Test Security Key',
						'type'       => 'text',
						'input_type' => 'password',
						'class'      => 'medium',
						'tooltip'    => 'Your secret API key for test mode',
					),
					array(
						'name'       => 'live_security_key',
						'label'      => 'Live Security Key',
						'type'       => 'text',
						'input_type' => 'password',
						'class'      => 'medium',
						'tooltip'    => 'Your secret API key for live mode',
					),
					array(
						'name'    => 'test_tokenization_key',
						'label'   => 'Test Tokenization Key',
						'type'    => 'text',
						'class'   => 'medium',
						'tooltip' => 'Your public key for Collect.js in test mode',
					),
					array(
						'name'    => 'live_tokenization_key',
						'label'   => 'Live Tokenization Key',
						'type'    => 'text',
						'class'   => 'medium',
						'tooltip' => 'Your public key for Collect.js in live mode',
					),
					// Add this field to your plugin_settings_fields array
					array(
						'name'       => 'webhook_signing_secret',
						'label'      => 'Webhook Signing Secret',
						'type'       => 'text',
						'input_type' => 'password',
						'class'      => 'medium',
						'tooltip'    => 'Found in your TailoredPay webhook settings. Used to verify that webhooks are legitimate.',
					),
					array(
						'name'          => 'webhook_url',
						'label'         => 'Webhook URL',
						'type'          => 'text',
						'readonly'      => true,
						'default_value' => rest_url( 'gf-tailoredpay/v1/webhook' ),
						'class'         => 'medium code',
						'description'   => 'Copy this URL and paste it into your TailoredPay webhook settings.',
					),
				),
			),
		);
	}
	

	private function save_card_digits_with_validation( $entry, $card_data, $webhook_transaction_id ) {
		// Safety checks
		if ( ! is_array( $entry ) || ! is_array( $card_data ) || empty( $webhook_transaction_id ) ) {
			error_log( 'ConvesioPay: Invalid parameters passed to save_card_digits_with_validation' );
			return false;
		}

		$entry_id             = rgar( $entry, 'id' );
		$entry_transaction_id = rgar( $entry, 'transaction_id' );

		if ( empty( $entry_id ) || empty( $entry_transaction_id ) ) {
			error_log( 'ConvesioPay: Missing entry ID or transaction ID for validation' );
			return false;
		}

		// Check if transaction IDs match
		if ( $webhook_transaction_id !== $entry_transaction_id ) {
			error_log( 'ConvesioPay: Transaction ID mismatch for entry ' . $entry_id . '. Webhook: ' . $webhook_transaction_id . ', Entry: ' . $entry_transaction_id );
			return false;
		}

		// Use base function to save
		return $this->save_card_digits_to_field( $entry, $card_data );
	}


	private function save_card_digits_to_field( $entry, $card_data ) {
		// Safety checks
		if ( ! is_array( $entry ) || ! is_array( $card_data ) ) {
			error_log( 'ConvesioPay: Invalid parameters passed to save_card_digits_to_field' );
			return false;
		}

		$entry_id = rgar( $entry, 'id' );
		$last4    = substr(rgar( $card_data, 'cc_number' ),-4);

		if ( empty( $entry_id ) || empty( $last4 ) ) {
			error_log( 'ConvesioPay: Missing entry ID or lastFour for card saving' );
			return false;
		}

		$form_id = rgar( $entry, 'form_id' );
		if ( empty( $form_id ) ) {
			error_log( 'ConvesioPay: Missing form ID for entry ' . $entry_id );
			return false;
		}

		$form = GFAPI::get_form( $form_id );
		if ( ! $form || is_wp_error( $form ) || empty( $form['fields'] ) ) {
			error_log( 'ConvesioPay: Form not found or invalid for entry ' . $entry_id );
			return false;
		}

		// Find the field labeled "Card Last 4 Digits"
		$field_id = null;
		foreach ( $form['fields'] as $field ) {
			if ( is_object( $field ) && stripos( $field->label, 'Card Last 4 Digits' ) !== false ) {
				$field_id = $field->id;
				break;
			}
		}

		if ( ! $field_id ) {
			error_log( 'ConvesioPay: Card Last 4 Digits field not found for form ' . $form_id );
			return false;
		}

		$result = GFAPI::update_entry_field( $entry_id, $field_id, sanitize_text_field( $last4 ) );

		if ( is_wp_error( $result ) ) {
			error_log( 'ConvesioPay: Failed to update card digits field: ' . $result->get_error_message() );
			return false;
		}

		error_log( 'ConvesioPay: Successfully saved last4 (' . $last4 . ') into field ' . $field_id . ' for entry ' . $entry_id );
		return true;
	}
	private function convesiopay_process_callback_action( $action ) {
		error_log( 'ConvesioPay Callback Action: ' . print_r( $action, true ) );

		$action = wp_parse_args(
			$action,
			array(
				'type'             => false,
				'amount'           => false,
				'transaction_type' => false,
				'transaction_id'   => false,
				'entry_id'         => false,
				'payment_status'   => false,
				'note'             => false,
			)
		);

		$result = false;

		if ( rgar( $action, 'id' ) && $this->is_duplicate_callback( $action['id'] ) ) {
			return new WP_Error( 'duplicate', sprintf( esc_html__( 'This callback has already been processed (Event Id: %s)', 'gravityforms' ), $action['id'] ) );
		}

		$entry = GFAPI::get_entry( $action['entry_id'] );
		if ( ! $entry || is_wp_error( $entry ) ) {
			error_log( 'ConvesioPay: Entry not found for callback action. Entry ID: ' . rgar( $action, 'entry_id' ) );
			return $result;
		}

		$form = GFAPI::get_form( $entry['form_id'] );
		do_action( 'gform_action_pre_payment_callback', $action, $entry );

		switch ( $action['type'] ) {
			case 'complete_payment':
				if ( 'Paid' === rgar( $entry, 'payment_status' ) ) {
					break;
				}
				$result = $this->complete_payment( $entry, $action );
				// GFAPI::send_notifications( $form, $entry, 'convesiopay_complete_payment' );
				break;

			case 'fail_payment':
				if ( 'Failed' === rgar( $entry, 'payment_status' ) ) {
					$result = true;
					break;
				}
				$result = $this->fail_payment( $entry, $action );
				// GFAPI::send_notifications( $form, $entry, 'convesiopay_fail_payment' );
				break;

			case 'add_pending_payment':
				if ( 'Processing' === rgar( $entry, 'payment_status' ) || 'Pending' === rgar( $entry, 'payment_status' ) ) {
					break;
				}
				$result = $this->add_pending_payment( $entry, $action );
				// GFAPI::send_notifications( $form, $entry, 'convesiopay_add_pending_payment' );
				break;
		}

		if ( rgar( $action, 'id' ) && $result ) {
			$this->register_callback( $action['id'], $action['entry_id'] );
		}

		do_action( 'gform_post_payment_callback', $entry, $action, $result );

		return $result;
	}

	/**
	 * Safe wrapper for webhook card saving with error handling.
	 *
	 * @param array $payload  Webhook payload.
	 * @param int   $entry_id Entry ID.
	 * @return void
	 */

	private function save_card_digits_to_field_from_webhook_safe( $payload, $entry_id = null ) {
		try {
			$this->save_card_digits_to_field_from_webhook( $payload, $entry_id );
		} catch ( Exception $e ) {
			error_log( 'ConvesioPay: Error in webhook card saving: ' . $e->getMessage() );
		}
	}
	/**
	 * Save card digits from webhook payload (for fresh entries).
	 *
	 * @param array $payload  Webhook payload.
	 * @param int   $entry_id Entry ID.
	 * @return void
	 */
	private function save_card_digits_to_field_from_webhook( $payload, $entry_id = null ) {
		// Safety checks
		if ( ! is_array( $payload ) || empty( $entry_id ) || ! is_numeric( $entry_id ) ) {
			error_log( 'ConvesioPay: Invalid parameters passed to save_card_digits_to_field_from_webhook' );
			return;
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || ! is_array( $entry ) ) {
			error_log( 'ConvesioPay: Entry not found or invalid for ID: ' . $entry_id );
			return;
		}

		$payment_method_details = rgar( $payload, 'card' );
		if ( is_array( $payment_method_details ) && ! empty( $payment_method_details ) ) {
			$this->save_card_digits_to_field( $entry, $payment_method_details );
		} else {
			error_log( 'ConvesioPay: No payment method details found in webhook payload for entry ' . $entry_id );
		}
	}

	/**
	 * Process webhook action based on payment status.
	 *
	 * @param array $payload Webhook payload.
	 * @param array $feed    Payment feed.
	 * @param array $entry   Entry object.
	 * @return array|WP_Error
	 */
	public function process_webhook_action( $payload, $feed, $entry ) {
		error_log( 'ConvesioPay Webhook Action: ' . print_r( $payload, true ) );

		$transaction_id = rgar( $payload, 'transaction_id' );
		$status         = strtolower( rgar( $payload, 'condition' ) );
		$amount         = rgar( $payload, 'requested_amount' );
		$currency       = rgar( $payload, 'currency' );

		$action                   = array();
		$action['id']             = $transaction_id . '_' . time();
		$action['entry_id']       = $entry['id'];
		$action['transaction_id'] = $transaction_id;
		$action['amount']         = $this->get_amount_import( $amount, $currency );
		$action['payment_method'] = $this->_slug;

		switch ( $status ) {
			case 'pendingsettlement':
				$action['type']             = 'complete_payment';
				$action['payment_date']     = gmdate( 'y-m-d H:i:s' );
				$action['note']             = 'Payment completed via webhook. Transaction ID: ' . $transaction_id;
				$action['ready_to_fulfill'] = ! $entry['is_fulfilled'];
				break;

			case 'failed':
			case 'blocked':
			case 'cancelled':
			case 'refunded':
			case 'disputed':
				$action['type']          = 'fail_payment';
				$action['note']          = 'Payment failed via webhook. Status: ' . ucfirst( $status ) . '. Transaction ID: ' . $transaction_id;
				$action['error_message'] = 'The payment status was updated to: ' . ucfirst( $status );
				break;

			case 'pending':
				$action['type'] = 'convesiopay_add_pending_payment';
				$action['note'] = 'Payment is pending via webhook. Transaction ID: ' . $transaction_id;
				break;

			default:
				return new WP_Error( 'unhandled_status', 'Unhandled webhook status', array( 'status' => 400 ) );
		}

		return $action;
	}


	public function redirect_url( $feed, $submission_data, $form, $entry ) {
		GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Processing' );
		gform_update_meta( $entry['id'], 'submission_data', $submission_data );

		$url = $this->return_url( $form['id'], $entry['id'] );
		return $url;
	}

	public function return_url( $form_id, $entry_id ) {
		$pageURL  = GFCommon::is_ssl() ? 'https://' : 'http://';
		$pageURL .= $_SERVER['SERVER_NAME'];
		if ( ! in_array( $_SERVER['SERVER_PORT'], array( '80', '443' ) ) ) {
			$pageURL .= ":{$_SERVER['SERVER_PORT']}";
		}
		$pageURL .= $_SERVER['REQUEST_URI'];

		$ids_query  = "ids={$form_id}|{$entry_id}";
		$ids_query .= '&hash=' . wp_hash($ids_query);
		return add_query_arg('gf_tailoredpay_return', base64_encode($ids_query), $pageURL);
	}

	public function maybe_process_tailoredpay_page()
	{
		$str = rgget('gf_tailoredpay_return');
		if (! $str) {
			return;
		}

		// Decode and validate the hash.
		$str = base64_decode($str);
		parse_str($str, $query);

		if (! isset($query['ids'], $query['hash'])) {
			return;
		}

		if (wp_hash('ids=' . $query['ids']) !== $query['hash']) {
			// Invalid / tampered return URL.
			return;
		}

		list($form_id, $entry_id) = explode('|', $query['ids']);

		$form  = GFAPI::get_form($form_id);
		$entry = GFAPI::get_entry($entry_id);

		if (! $form || is_wp_error($entry)) {
			return;
		}

		// ✅ NEW: If the payment is already marked as Paid, show the confirmation / thank-you page.
		// $payment_status = rgar($entry, 'payment_status');

		if ('Paid' === $payment_status) {
			if (! class_exists('GFFormDisplay')) {
				require_once GFCommon::get_base_path() . '/form_display.php';
			}

			$confirmation = GFFormDisplay::handle_confirmation($form, $entry, false);

			// If the confirmation is a redirect, send the user there.
			if (is_array($confirmation) && isset($confirmation['redirect'])) {
				wp_redirect($confirmation['redirect']);
				exit;
			}

			// Otherwise, show the confirmation message on the same page.
			GFFormDisplay::$submission[$form_id] = array(
				'is_confirmation'      => true,
				'confirmation_message' => $confirmation,
				'form'                 => $form,
				'lead'                 => $entry,
			);

			return;
		}

		// ❗ Not paid yet → load the TailoredPay payment form via the_content filter.
		$this->is_payment_page_load = true;
		$this->payment_page_form    = $form;
		$this->payment_page_entry   = $entry;
	}

	public function maybe_render_payment_page( $content ) {
		if ( ! $this->is_payment_page_load || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		if ( ! $this->payment_page_form || ! $this->payment_page_entry ) {
			return $content;
		}

		$this->is_payment_page_load = false;
		return $this->render_payment_form($this->payment_page_form, $this->payment_page_entry);
	}

	public function render_payment_form($form, $entry)
	{
		$settings        = $this->get_plugin_settings();
		$submission_data = gform_get_meta($entry['id'], 'submission_data');

		$error_message = $this->payment_page_error;

		if (empty($submission_data)) {
			return '<p>Error: Payment session not found.</p>';
		}

		$environment      = rgar($settings, 'environment', 'test');
		$tokenization_key = $environment === 'live'
			? rgar($settings, 'live_tokenization_key')
			: rgar($settings, 'test_tokenization_key');

		if (empty($tokenization_key)) {
			return '<p>Error: Tokenization key is not configured. Please check your TailoredPay settings.</p>';
		}

		// --- FIX: Enqueue ONLY our local script. The external one will be loaded directly in the HTML below. ---
		wp_enqueue_script(
			'tailoredpay-form',
			GF_TAILOREDPAY_PLUGIN_URL . '/public/js/tailoredpay-form.js',
			array( 'jquery' ), // It only depends on jQuery now.
			GF_TAILOREDPAY_VERSION,
			true
		);

		wp_enqueue_style(
			'tailoredpay-form-css',
			GF_TAILOREDPAY_PLUGIN_URL . '/public/css/payment-form.css',
			array(),
			GF_TAILOREDPAY_VERSION
		);

		// We still need to pass these variables to our local script.
		wp_localize_script(
			'tailoredpay-form',
			'tailoredpay_vars',
			array(
				'entryId' => $entry['id'],
				'amount'  => $submission_data['payment_amount'],
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'tailoredpay_payment' ),
			)
		);

		ob_start();
?>
		<div class="tailoredpay-checkout-wrapper-main">
			<div class="tailoredpay-custom-checkout-item-wrapper">
				<!-- for header -->
				<div class="tailoredpay-card-details-container">
					<div class="tailoredpay-card-details-header">
						<span class="tailoredpay-card-details-title">Card Details</span>
						<div class="tailoredpay-secure-payment-section">
							<span class="tailoredpay-secure-payment-text">SECURE PAYMENT</span>
							<img src="https://upload.wikimedia.org/wikipedia/commons/thumb/5/5e/Visa_Inc._logo.svg/2560px-Visa_Inc._logo.svg.png" alt="Visa" class="tailoredpay-payment-icon tailoredpay-visa">
							<img src="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/MasterCard_Logo.svg/2560px-MasterCard_Logo.svg.png" alt="Mastercard" class="tailoredpay-payment-icon tailoredpay-mastercard">
							<img src="https://upload.wikimedia.org/wikipedia/commons/thumb/4/40/JCB_logo.svg/1200px-JCB_logo.svg.png" alt="JCB" class="tailoredpay-payment-icon tailoredpay-jcb">
							<img src="https://upload.wikimedia.org/wikipedia/commons/thumb/f/fa/American_Express_logo_%282018%29.svg/1200px-American_Express_logo_%282018%29.svg.png" alt="American Express" class="tailoredpay-payment-icon tailoredpay-amex">
						</div>
					</div>
				</div>
				<div id="tailoredpay-checkout-payment-box" class="tailoredpay-checkout-payment-box">
					<div id="tailoredpay-wrapper">
						<!-- tailoredpay Form (iframe ki jagah) -->
						<form id="tailoredpay-payment-form" method="POST" action="">
							<div id="tailoredpay-component-container">
								<script src="https://tailoredpay.transactiongateway.com/token/Collect.js"
									data-tokenization-key="<?php echo esc_attr($tokenization_key); ?>"
									data-variant="inline"></script>
								<!-- FIX END -->

								<div class="payment-form">
									<div class="form-group">
										<label>Card Number</label>
										<div id="ccnumber"></div>
									</div>
									<div class="form-row">
										<div class="form-group">
											<label>Expiry Date</label>
											<div id="ccexp"></div>
										</div>
										<div class="form-group">
											<label>CVV</label>
											<div id="cvv"></div>
										</div>
									</div>
								</div>

								<div id="payment-errors" class="payment-errors"></div>
								<!-- <div id="payment-processing" class="payment-processing" style="display:none;">
									Processing payment...
								</div> -->
							</div>

							<!-- Initial loader until JS initializes tailoredpay -->
							<div id="tailoredpay-initial-loader" class="tailoredpay-initial-loader">
								<div class="tailoredpay-spinner"></div>
								<span>Processing your secure payment...</span>
							</div>
					</div>

					<div class="tailoredpay-terms-checkbox-container">
						<input type="checkbox" id="tailoredpay-terms-agreement" name="terms_agreement" data-gtm-form-interact-field-id="0">
						<label for="tailoredpay-terms-agreement" class="tailoredpay-information-text">By authorizing the payment, I acknowledge that I am purchasing a third-party service not affiliated with any government agency, and I have reviewed and angree to the <a href="https://usimmigrationassistance.com/terms_of_service/">term and conditions</a> and <a href="https://usimmigrationassistance.com/privacy_policy/">privacy policy</a></label>
					</div>
					<button id="payButton" class="pay-button" disabled>Complete Your Order</button>
					<div class="tailoredpay-payment-method-text">
						<p>We use secure payment methods to protect your information.</p>
					</div>
					<div class="tailoredpay-payment-errors-custom">
						<div id="tailoredpay-js-errors" class="tailoredpay-validation-error"></div>
						<?php if (! empty($error_message)) : ?>
							<div class="tailoredpay-validation-error" style="display: block;">
								<?php echo esc_html($error_message); ?>
							</div>
						<?php endif; ?>
					</div>
					</form>

					<div class="tailoredpay-note-section">
						<label for="id_accept_terms" class="form-check-label">
							I acknowledge that a private company will review and submit the ESTA application on my behalf, which includes the mandatory U.S. Government submission fee. I confirm that I am of legal age or have obtained parental/guardian consent to submit this application. I consent to the
							<a href="https://usimmigrationassistance.com/privacy_policy/" target="_blank" class="policy-privacy">Privacy Policy</a> and
							<a href="https://usimmigrationassistance.com/terms_of_service/" target="_blank" class="policy-terms">Terms &amp; Conditions</a>
							of this website. The service charge of <strong>$<?php echo esc_html(rgar($entry, 'payment_amount')); ?></strong>
							will appear on the statement as: US Immigration Assistance<br><br>
							If you have any questions about your order or your upcoming trip to the USA, please contact us at: support@usimmigrationassistance.com
						</label>
					</div>
				</div>
			</div>
		</div>
<?php
		return ob_get_clean();
	}

	public function handle_ajax_payment()
	{
		if (! wp_verify_nonce($_POST['nonce'], 'tailoredpay_payment')) {
			wp_send_json_error('Invalid nonce');
		}

		$payment_token = sanitize_text_field($_POST['payment_token']);
		$entry_id      = intval($_POST['entry_id']);

		$entry = GFAPI::get_entry($entry_id);
		if (! $entry) {
			wp_send_json_error('Entry not found');
		}

		$submission_data = gform_get_meta($entry_id, 'submission_data');
		if (! $submission_data) {
			wp_send_json_error('Submission data not found');
		}

		$settings     = $this->get_plugin_settings();
		$environment  = rgar($settings, 'environment', 'test');
		$security_key = $environment === 'live'
			? rgar($settings, 'live_security_key')
			: rgar($settings, 'test_security_key');

		$billing_info = rgar($submission_data, 'billing');

		// --- FIX: Format the amount to two decimal places ---
		$amount_formatted = number_format((float) $submission_data['payment_amount'], 2, '.', '');

		$request_data = array(
			'security_key'             => $security_key,
			'type'                     => 'sale',
			'amount'                   => $amount_formatted, // Use the formatted amount
			'currency'                 => $entry['currency'],
			'payment_token'            => $payment_token,
			'merchant_defined_field_1' => $entry_id,
			'ipaddress'                => GFFormsModel::get_ip(),
			'first_name'               => rgar( $billing_info, 'firstName' ),
			'last_name'                => rgar( $billing_info, 'lastName' ),
			'address1'                 => rgar( $billing_info, 'address1' ),
			'address2'                 => rgar( $billing_info, 'address2' ),
			'city'                     => rgar( $billing_info, 'city' ),
			'state'                    => rgar( $billing_info, 'state' ),
			'zip'                      => rgar( $billing_info, 'zip' ),
			'country'                  => rgar( $billing_info, 'country' ),
			'email'                    => rgar( $billing_info, 'email' ),
			'test_mode'				   => 'enabled',
		);

		$request_data = array_filter( $request_data );

		$response = wp_remote_post(
			'https://tailoredpay.transactiongateway.com/api/transact.php',
			array(
				'body'    => $request_data,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Payment processing failed: ' . $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		parse_str( $body, $result );
		error_log( '$request_data: ' . print_r( $request_data, true ) );
		error_log( '$result: ' . print_r( $result, true ) );
 
		if ( isset( $result['response'] ) && $result['response'] == '1' ) {
			$confirmed_amount = isset( $result['amount'] ) ? $result['amount'] : $submission_data['payment_amount'];
			$action           = array(
				'type'           => 'complete_payment',
				'transaction_id' => $result['transactionid'],
				'amount'         => $confirmed_amount,
				'payment_date'   => gmdate( 'Y-m-d H:i:s' ),
				'payment_method' => 'TailoredPay',
			);
			$this->complete_payment( $entry, $action );

			$form         = GFAPI::get_form( $entry['form_id'] );
			$confirmation = GFFormDisplay::handle_confirmation( $form, $entry, false );
			$redirect_url = isset( $confirmation['redirect'] ) ? $confirmation['redirect'] : '';
			wp_send_json_success( array( 'redirect_url' => $redirect_url ) );

		} else {
			$error_message  = isset( $result['responsetext'] ) ? $result['responsetext'] : 'Payment was declined or an error occurred.';
			$transaction_id = isset( $result['transactionid'] ) ? $result['transactionid'] : 'N/A';

			// --- FIX: Create a detailed failure note ---
			$note = sprintf(
				'Payment failed. Amount: %1$s. Transaction ID: %2$s. Reason: %3$s',
				GFCommon::to_money( $submission_data['payment_amount'], $entry['currency'] ),
				$transaction_id,
				$error_message
			);

			$action = array(
				'type'           => 'fail_payment',
				'amount'         => $submission_data['payment_amount'],
				'transaction_id' => $transaction_id,
				'note'           => $note, // Pass the detailed note here
			);

			$this->fail_payment( $entry, $action );
			wp_send_json_error( $error_message );
		}
	}

	public function billing_info_fields() {
		$fields = array(
			array(
				'name'     => 'firstName',
				'label'    => 'First Name',
				'required' => false,
			),
			array(
				'name'     => 'lastName',
				'label'    => 'Last Name',
				'required' => false,
			),
			array(
				'name'     => 'email',
				'label'    => 'Email',
				'required' => false,
			),
			array(
				'name'     => 'address1',
				'label'    => 'Address',
				'required' => false,
			),
			array(
				'name'     => 'address2',
				'label'    => 'Address 2',
				'required' => false,
			),
			array(
				'name'     => 'city',
				'label'    => 'City',
				'required' => false,
			),
			array(
				'name'     => 'state',
				'label'    => 'State',
				'required' => false,
			),
			array(
				'name'     => 'zip',
				'label'    => 'Zip',
				'required' => false,
			),
			array(
				'name'     => 'country',
				'label'    => 'Country',
				'required' => false,
			),
		);
		return $fields;
	}
	public function feed_settings_fields() {
		$default_settings = parent::feed_settings_fields();

		// Remove default options before adding custom
		$default_settings = $this->remove_field( 'transactionType', $default_settings );
		$default_settings = $this->remove_field( 'options', $default_settings );

		$transaction_type = array(
			array(
				'name'     => 'transactionType',
				'label'    => esc_html__( 'Transaction Type', 'gravityforms' ),
				'type'     => 'select',
				'onchange' => "jQuery(this).parents('form').submit();",
				'choices'  => array(
					array(
						'label' => esc_html__( 'Select a transaction type', 'gravityforms' ),
						'value' => '',
					),
					array(
						'label' => esc_html__( 'Products and Services', 'gravityforms' ),
						'value' => 'product',
					),
				),
				'tooltip'  => '<h6>' . esc_html__( 'Transaction Type', 'gravityforms' ) . '</h6>' . esc_html__( 'Select a transaction type.', 'gravityforms' ),
			),
		);

		$default_settings = $this->add_field_after( 'feedName', $transaction_type, $default_settings );

		return apply_filters( 'gform_tailoredpay_feed_settings_fields', $default_settings, $this->get_current_form() );

	}
}