<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GF_TailoredPay_Pay_Later {

	private static $_instance = null;

	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		add_shortcode( 'tailoredpay_retrieve_application', array( $this, 'render_retrieve_application_form' ) );
		add_action( 'wp_ajax_tailoredpay_retrieve_applications', array( $this, 'handle_retrieve_applications_ajax' ) );
		add_action( 'wp_ajax_nopriv_tailoredpay_retrieve_applications', array( $this, 'handle_retrieve_applications_ajax' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

    public function enqueue_scripts() {
        // Only enqueue if the shortcode exists on the page
        global $post;
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'tailoredpay_retrieve_application' ) ) {
            wp_enqueue_script(
                'tailoredpay-pay-later',
                GF_TAILOREDPAY_PLUGIN_URL . '/public/js/pay-later.js', // We will create this file
                array( 'jquery' ),
                GF_TAILOREDPAY_VERSION,
                true
            );
            wp_localize_script(
                'tailoredpay-pay-later',
                'tailoredpay_pay_later_vars',
                array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce'   => wp_create_nonce( 'tailoredpay_retrieve' ),
                )
            );
        }
    }

	public function render_retrieve_application_form() {
		ob_start();
		?>
		<div class="tailoredpay-retrieve-container">
			<h3>Find Your Application</h3>
			<p>Enter your email address to find and pay for your pending applications.</p>
			
			<form id="tailoredpay-retrieve-form">
				<div class="form-group">
					<label for="retrieve-email">Email Address:</label>
					<input type="email" id="retrieve-email" name="email" required>
				</div>
				<button type="submit">Find Applications</button>
			</form>
			
			<div id="retrieve-results" style="margin-top: 20px;"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function handle_retrieve_applications_ajax() {
		if ( ! wp_verify_nonce( $_POST['nonce'], 'tailoredpay_retrieve' ) ) {
			wp_send_json_error( 'Invalid request' );
		}

		$email = sanitize_email( $_POST['email'] );
		if ( ! is_email( $email ) ) {
			wp_send_json_error( 'Please enter a valid email address.' );
		}

		// Allow themes/plugins to specify which forms are searchable
		$form_ids = apply_filters( 'tailoredpay_retrieve_form_ids', array() );
		
		if ( empty( $form_ids ) ) {
			wp_send_json_error( 'No forms are configured for payment retrieval. Please contact the site administrator.' );
		}

		$all_entries = array();

		foreach ( $form_ids as $form_id ) {
			$form = GFAPI::get_form( $form_id );
			if ( ! $form ) continue;

			$email_field_id = null;
			foreach ( $form['fields'] as $field ) {
				if ( $field->type === 'email' ) {
					$email_field_id = $field->id;
					break;
				}
			}

			if ( ! $email_field_id ) continue;

			$search_criteria = array(
				'status' => 'active',
				'field_filters' => array(
					'mode' => 'all', // Ensure all filters match
					array(
						'key'   => $email_field_id,
						'value' => $email,
					),
					array(
						'key'   => 'payment_status',
						'value' => 'Paid',
						'operator' => '<>' // Not equal to 'Paid'
					)
				)
			);

			$entries = GFAPI::get_entries( $form_id, $search_criteria );
			if ( ! is_wp_error( $entries ) ) {
				$all_entries = array_merge($all_entries, $entries);
			}
		}
		
		if ( empty( $all_entries ) ) {
			wp_send_json_error( 'No unpaid applications found for this email address.' );
		}

		$html = $this->render_applications_table( $all_entries );
		wp_send_json_success( $html );
	}
    
	private function render_applications_table( $entries ) {
		$gateway = GF_TailoredPay_Gateway::get_instance();
		
		ob_start();
		?>
		<style>
            .applications-table{width:100%;border-collapse:collapse;margin-top:20px}.applications-table th,.applications-table td{padding:10px;border:1px solid #ddd;text-align:left}.applications-table th{background:#f5f5f5}.pay-link{background:#28a745;color:white;padding:5px 10px;text-decoration:none;border-radius:3px;display:inline-block}.pay-link:hover{background:#218838;color:white}
        </style>
		<table class="applications-table">
			<thead>
				<tr>
					<th>Form</th>
					<th>Date</th>
					<th>Amount</th>
					<th>Status</th>
					<th>Action</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php
					$form = GFAPI::get_form( $entry['form_id'] );
					$payment_amount = rgar( $entry, 'payment_amount' );
					$payment_status = rgar( $entry, 'payment_status' );
					
					// Final check to ensure we only show entries that need payment
					if ( empty( $payment_amount ) || $payment_status === 'Paid' ) {
						continue;
					}
					
					// Generate the secure payment URL
					$payment_url = $gateway->return_url( $entry['form_id'], $entry['id'] );
					?>
					<tr>
						<td><?php echo esc_html( $form['title'] ); ?></td>
						<td><?php echo esc_html( GFCommon::format_date( $entry['date_created'], false ) ); ?></td>
						<td><?php echo GFCommon::to_money( $payment_amount, $entry['currency'] ); ?></td>
						<td><?php echo esc_html( $payment_status ?: 'Pending Payment' ); ?></td>
						<td>
							<a href="<?php echo esc_url( $payment_url ); ?>" class="pay-link">
								Make Payment
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return ob_get_clean();
	}
}