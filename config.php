<?php

/*
 * Static defaults for the MoMo settings.
 *
 * This file is replaced on every 1-click update, so it holds defaults only. The runtime
 * source of truth is admin_config (per store, keys encrypted at rest), read through
 * momopayment_config(); these values are returned only when a key has no database row.
 * No env() here on purpose: the settings are managed in admin, never in .env.
 *
 * The return / IPN URLs are not settings: they are computed from the plugin routes
 * (function.php) so they always match the store's current domain.
 */
return [
    'test_mode' => 1,
    'partner_code_test' => '',
    'access_key_test' => '',
    'secret_key_test' => '',
    'partner_code_live' => '',
    'access_key_live' => '',
    'secret_key_live' => '',
    'order_status_success' => 2,  // Processing
    'order_status_refunded' => 7, // Refunded
    'order_prefix' => '',         // empty = derived per site (function.php)
];
