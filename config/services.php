<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    /*
     * Outgoing calls to payment gateways. Connecting fails fast so the switch
     * can fall back to another provider; once connected, we wait up to
     * `timeout` seconds for an answer, because the gateway may already be
     * moving the money. Both are in seconds and overridable from .env.
     */
    'gateways' => [
        'connect_timeout' => (int) env('GATEWAY_CONNECT_TIMEOUT', 8),
        'timeout' => (int) env('GATEWAY_TIMEOUT', 180),
    ],

    /*
     * PayPal can take well over 8 seconds to connect from some networks
     * (TCP plus TLS). A call that never connected can't have moved money, and
     * PayPal calls carry request ids, so it's safe to wait longer here.
     */
    'paypal' => [
        'connect_timeout' => (int) env('PAYPAL_CONNECT_TIMEOUT', 20),
        // Added to the demo account by `php artisan demo:seed` (optional).
        // How agents reach PayPal's Agent Toolkit: "local" runs PayPal's official
        // MCP server (@paypal/mcp, from node_modules) over stdio; "remote" uses
        // PayPal's hosted MCP server over streamable HTTP.
        'mcp_transport' => env('PAYPAL_MCP_TRANSPORT', 'local'),
        // The Node.js binary for the local server (Node 18+).
        'mcp_node' => env('PAYPAL_MCP_NODE', 'node'),
        // PayPal's MCP server entry point; installed by npm install.
        'mcp_server' => env('PAYPAL_MCP_SERVER', base_path('node_modules/@paypal/mcp/dist/index.js')),
        'sandbox_client_id' => env('PAYPAL_SANDBOX_CLIENT_ID'),
        'sandbox_client_secret' => env('PAYPAL_SANDBOX_CLIENT_SECRET'),
    ],

    'pawapay' => [
        // Added to the demo account by `php artisan demo:seed` (optional).
        'sandbox_token' => env('PAWAPAY_SANDBOX_TOKEN'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
