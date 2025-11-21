# Gravity Forms TailoredPay Gateway

A WordPress plugin that integrates Gravity Forms with the TailoredPay payment gateway using Collect.js tokenization.

## Features

- Secure payment processing using TailoredPay's Collect.js tokenization
- Test and Live environment support
- Pay Later functionality for retrieving and paying unpaid applications
- Seamless integration with Gravity Forms payment framework

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Configure the plugin settings under Forms > Settings > TailoredPay

## Configuration

### Plugin Settings

Navigate to **Forms > Settings > TailoredPay** and configure:

1. **Environment**: Choose Test or Live
2. **Test Security Key**: Your secret API key for test mode
3. **Live Security Key**: Your secret API key for live mode  
4. **Test Tokenization Key**: Your public key for Collect.js in test mode
5. **Live Tokenization Key**: Your public key for Collect.js in live mode

### Form Setup

1. Create or edit a Gravity Form
2. Add a payment feed under **Form Settings > TailoredPay**
3. Configure the payment amount and other settings

## Pay Later Feature

Use the shortcode `[tailoredpay_retrieve_application]` on any page to allow users to find and pay for their pending applications by email.

To configure which forms are searchable, add this to your theme's functions.php:

```php
add_filter( 'tailoredpay_retrieve_form_ids', function( $form_ids ) {
    return array( 1, 2, 3 ); // Replace with your form IDs
});
```

## API Endpoints

The plugin uses the following TailoredPay endpoints:

- **Payment Processing**: `https://tailoredpay.transactiongateway.com/api/transact.php`
- **Collect.js Library**: `https://tailoredpay.transactiongateway.com/token/Collect.js`

## Requirements

- WordPress 5.0+
- Gravity Forms 2.3+
- PHP 7.4+
- Valid TailoredPay merchant account

## Support

For questions about the TailoredPay API, refer to the documentation in the `tailoredPay-docs` folder or contact TailoredPay support.
