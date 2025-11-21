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
		if ( in_array( rgar( $entry, 'payment_status' ), array( 'Paid', 'Failed', 'Refunded' ), true ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'success',
					'message' => 'Entry already processed.',
				),
				200
			);
		}

		$transaction_id = rgar( $event_body, 'transaction_id' );
		$amount         = rgar( $event_body['action'], 'amount', rgar( $entry, 'payment_amount' ) );
		$response_text  = rgar( $event_body['action'], 'response_text' );

		switch ( $event_type ) {
			case 'transaction.sale.success':
				$this->complete_payment(
					$entry,
					array(
						'type'           => 'complete_payment',
						'transaction_id' => $transaction_id,
						'amount'         => $amount,
						'payment_date'   => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->add_note( $entry_id, 'Payment confirmed via webhook.' );
				break;

			case 'transaction.sale.failure':
				// --- FIX: Create a detailed failure note for webhook ---
				$note = sprintf(
					'Payment failure via webhook. Amount: %1$s. Transaction ID: %2$s. Reason: %3$s',
					GFCommon::to_money( $amount, $entry['currency'] ),
					$transaction_id,
					$response_text
				);
				$this->fail_payment(
					$entry,
					array(
						'transaction_id' => $transaction_id,
						'amount'         => $amount,
						'note'           => $note,
					)
				);
				break;
		}

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
		$ids_query .= '&hash=' . wp_hash( $ids_query );
		return add_query_arg( 'gf_tailoredpay_return', base64_encode( $ids_query ), $pageURL );
	}

	public function maybe_process_tailoredpay_page() {
		$str = rgget( 'gf_tailoredpay_return' );
		if ( ! $str ) {
			return;
		}

		$str = base64_decode( $str );
		parse_str( $str, $query );

		if ( wp_hash( 'ids=' . $query['ids'] ) !== $query['hash'] ) {
			return;
		}

		list( $form_id, $entry_id ) = explode( '|', $query['ids'] );
		$form                       = GFAPI::get_form( $form_id );
		$entry                      = GFAPI::get_entry( $entry_id );

		if ( ! $form || ! $entry ) {
			return;
		}

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
		return $this->render_payment_form( $this->payment_page_form, $this->payment_page_entry );
	}

	public function render_payment_form( $form, $entry ) {
		$settings        = $this->get_plugin_settings();
		$submission_data = gform_get_meta( $entry['id'], 'submission_data' );

		if ( empty( $submission_data ) ) {
			return '<p>Error: Payment session not found.</p>';
		}

		$environment      = rgar( $settings, 'environment', 'test' );
		$tokenization_key = $environment === 'live'
		? rgar( $settings, 'live_tokenization_key' )
		: rgar( $settings, 'test_tokenization_key' );

		if ( empty( $tokenization_key ) ) {
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
	<div class="tailoredpay-payment-container">
		<h3>Complete Your Payment</h3>
		<div class="payment-amount">
			Amount: $<?php echo esc_html( $submission_data['payment_amount'] ); ?>
		</div>

		<!-- FIX START: Load Collect.js directly in the HTML with its required data attributes. -->
		<!-- This script MUST come before the payment form divs. -->
		<script src="https://tailoredpay.transactiongateway.com/token/Collect.js"
				data-tokenization-key="<?php echo esc_attr( $tokenization_key ); ?>"
				data-variant="inline"></script>
		<!-- FIX END -->

		<div class="payment-form">
			<div class="form-group">
				<label>Card Number</label>
				<div id="ccnumber"></div>
			</div>
			<div class="form-group">
				<label>Expiry Date</label>
				<div id="ccexp"></div>
			</div>
			<div class="form-group">
				<label>CVV</label>
				<div id="cvv"></div>
			</div>
			<button id="payButton" class="pay-button" disabled>Pay Now</button>
		</div>
		
		<div id="payment-errors" class="payment-errors"></div>
		<div id="payment-processing" class="payment-processing" style="display:none;">
			Processing payment...
		</div>
	</div>
		<?php
		return ob_get_clean();
	}

	public function handle_ajax_payment() {
		if ( ! wp_verify_nonce( $_POST['nonce'], 'tailoredpay_payment' ) ) {
			wp_send_json_error( 'Invalid nonce' );
		}

		$payment_token = sanitize_text_field( $_POST['payment_token'] );
		$entry_id      = intval( $_POST['entry_id'] );

		$entry = GFAPI::get_entry( $entry_id );
		if ( ! $entry ) {
			wp_send_json_error( 'Entry not found' );
		}

		$submission_data = gform_get_meta( $entry_id, 'submission_data' );
		if ( ! $submission_data ) {
			wp_send_json_error( 'Submission data not found' );
		}

		$settings     = $this->get_plugin_settings();
		$environment  = rgar( $settings, 'environment', 'test' );
		$security_key = $environment === 'live'
		? rgar( $settings, 'live_security_key' )
		: rgar( $settings, 'test_security_key' );

		$billing_info = rgar( $submission_data, 'billing' );

		// --- FIX: Format the amount to two decimal places ---
		$amount_formatted = number_format( (float) $submission_data['payment_amount'], 2, '.', '' );

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
