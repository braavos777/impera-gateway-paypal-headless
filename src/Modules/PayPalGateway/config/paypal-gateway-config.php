<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

return [
    'payment_fields' => [
        'enabled'               => [
            'title'       => 'Enable/Disable',
            'label'       => 'Enable Impera PayPal Gateway',
            'type'        => 'checkbox',
            'description' => '',
            'default'     => 'no',
        ],
        'title'                 => [
            'title'       => 'Title',
            'type'        => 'text',
            'description' => 'This controls the title which the user sees during checkout.',
            'default'     => 'PayPal',
            'desc_tip'    => true,
        ],
        'description'           => [
            'title'       => 'Description',
            'type'        => 'textarea',
            'description' => 'This controls the description which the user sees during checkout.',
            'default'     => 'You will be redirected to PayPal to complete your order.',
        ],
        'checkout_button_content' => [
            'title'   => 'Checkout button text',
            'type'    => 'text',
            'default' => 'Continue to PayPal',
        ],
        'proxy_domain'          => [
            'title'       => 'Proxy domain <sup style="color: red">*</sup>',
            'type'        => 'text',
            'description' => 'Host of the proxy site that holds the PayPal credentials, for example <code>pay.example.com</code>. Required — without it the gateway cannot start a payment.',
            'default'     => 'malythia.com',
            'placeholder' => 'pay.example.com',
            'custom_attributes' => [
                'required'  => 'required'
            ],
        ],
        'shared_key'            => [
            'title'       => 'Proxy shared key <sup style="color: red">*</sup>',
            'type'        => 'password',
            'description' => 'It signs every request the shop sends and verifies every payment the proxy reports back, so it is kept here rather than in the plugin files. Defining <code>IMPERA_PPH_KEY</code> in <code>wp-config.php</code> overrides this field.',
            'default'     => '',
            'custom_attributes' => [
                'required'  => 'required'
            ],
        ],
        'sandbox'               => [
            'title'       => 'Sandbox',
            'label'       => 'Pay through the PayPal sandbox',
            'type'        => 'checkbox',
            'default'     => 'no',
            'description' => 'The proxy must have sandbox credentials and a sandbox webhook ID configured for this to work. A stored setting rather than a URL flag, so a customer cannot switch it.',
        ],
        'shipping_preference'   => [
            'title'       => 'Shipping address on PayPal',
            'type'        => 'select',
            'default'     => 'NO_SHIPPING',
            'description' => '<strong>Do not show</strong> is right for a shop that already collected the address on its own checkout. <strong>Show the order address</strong> displays it, unchangeable. <strong>Let the buyer choose</strong> allows them to pick another address on PayPal, which can move the total — a payment whose amount no longer matches the order is refused and left for manual review.',
            'desc_tip'    => false,
            'options'     => [
                'NO_SHIPPING'          => 'Do not show (recommended)',
                'SET_PROVIDED_ADDRESS' => 'Show the order address',
                'GET_FROM_FILE'        => 'Let the buyer choose on PayPal',
            ],
        ],
        'cancel_behaviour'      => [
            'title'       => 'When the customer cancels on PayPal',
            'type'        => 'select',
            'default'     => 'pending',
            'description' => 'Leaving the order pending lets the customer pay it later from My Account. Cancelling closes it and releases the stock.',
            'desc_tip'    => true,
            'options'     => [
                'pending'   => 'Leave the order pending (recommended)',
                'cancelled' => 'Cancel the order',
            ],
        ],
        'invoice_prefix'        => [
            'title'       => 'Invoice Prefix',
            'type'        => 'text',
            'description' => 'Prefix added to the order number in the proxy statistics. It is not sent to PayPal as an invoice id — PayPal requires that to be unique across the whole account and rejects a retried payment that reuses one.',
            'default'     => 'wdmlshd',
            'desc_tip'    => true,
        ],
        'product_title_setting' => [
            'title'       => 'Overwrite product title',
            'type'        => 'text',
            'description' => 'Applied to each item name in the order description. Supports <b>[order_id]</b>, <b>[last_word]</b>, <b>[keep_original]</b> and <b>[rand_N]</b> (random string of N characters).',
            'default'     => 'Order [order_id] item [rand_7]',
        ],
        'brand_name'            => [
            'title'             => 'Brand Name',
            'type'              => 'text',
            'description'       => 'Shown to the customer on the PayPal page so they know which store they are paying. <b>Up to 127 characters.</b>',
            'default'           => '',
            'placeholder'       => 'Online store of original shoes -Style-',
            'custom_attributes' => [
                'maxlength' => '127',
            ],
        ],
        'soft_descriptor'       => [
            'title'             => 'Soft Descriptor',
            'type'              => 'text',
            'description'       => 'What the buyer sees on their card or bank statement. <b>Up to 22 characters</b>; PayPal accepts letters, digits, spaces, dots, dashes and asterisks only.',
            'default'           => '',
            'placeholder'       => 'SHOES-STORE-STYLE',
            'custom_attributes' => [
                'maxlength' => '22',
            ],
        ],
    ],
    'available_currency' => IMPERA_PPH_AVAILABLE_CURRENCIES,
];
