<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/* Eco-Bin application settings. Secrets come from environment variables. */

// Recyclable drop-off materials. Points are awarded per unit ('kg' or 'pcs').
// plastic_bottle is counted per piece (0.5 points each), the rest per kilogram.
$config['dropoff_materials'] = [
    'plastic'        => ['label' => 'Plastic',             'unit' => 'kg',  'points' => 10],
    'plastic_bottle' => ['label' => 'Plastic bottles',     'unit' => 'pcs', 'points' => 0.5],
    'paper'          => ['label' => 'Paper',               'unit' => 'kg',  'points' => 5],
    'cardboard'      => ['label' => 'Cardboard & cartons', 'unit' => 'kg',  'points' => 5],
    'metal'          => ['label' => 'Scrap metal',         'unit' => 'kg',  'points' => 20],
    'aluminum'       => ['label' => 'Aluminum cans',       'unit' => 'kg',  'points' => 15],
    'glass'          => ['label' => 'Glass',               'unit' => 'kg',  'points' => 8],
    'ewaste'         => ['label' => 'E-waste',             'unit' => 'kg',  'points' => 25],
    'textile'        => ['label' => 'Textiles / tela',     'unit' => 'kg',  'points' => 4],
];

$config['upload_max_bytes'] = 5 * 1024 * 1024;

// Cloudinary photo storage. Signed uploads through the SDK (run `composer install`).
// If all three are set, photos go to Cloudinary; otherwise photos fall back to public/uploads.
$config['cloudinary_cloud_name']    = getenv('CLOUDINARY_CLOUD_NAME') ?: '';
$config['cloudinary_api_key']       = getenv('CLOUDINARY_API_KEY') ?: '';
$config['cloudinary_api_secret']    = getenv('CLOUDINARY_API_SECRET') ?: '';
$config['cloudinary_upload_preset'] = getenv('CLOUDINARY_UPLOAD_PRESET') ?: '';

// SMS provider: 'semaphore' or '' (empty = reminders are only queued in the notifications table)
$config['sms_provider'] = getenv('SMS_PROVIDER') ?: '';
$config['sms_api_key']  = getenv('SMS_API_KEY') ?: '';
$config['sms_sender']   = getenv('SMS_SENDER') ?: 'EcoBin';

// Facebook Login. Create an app at https://developers.facebook.com/apps and add
// <backend>/api/auth/facebook/callback as a Valid OAuth Redirect URI.
$config['facebook_app_id']        = getenv('FACEBOOK_APP_ID') ?: '';
$config['facebook_app_secret']    = getenv('FACEBOOK_APP_SECRET') ?: '';
$config['facebook_redirect_uri']  = getenv('FACEBOOK_REDIRECT_URI') ?: '';
$config['facebook_graph_version'] = getenv('FACEBOOK_GRAPH_VERSION') ?: 'v23.0';
$config['facebook_state_secret']  = getenv('FACEBOOK_STATE_SECRET') ?: '';
// Where the user lands after Facebook sends them back. Empty = use the first CORS origin.
$config['frontend_url']           = getenv('FRONTEND_URL') ?: '';

// Email / password-reset codes. Brevo is tried first, then Gmail SMTP.
$config['brevo_api_key']      = getenv('BREVO_API_KEY') ?: '';
$config['brevo_api_url']      = getenv('BREVO_API_URL') ?: 'https://api.brevo.com/v3/smtp/email';
$config['brevo_sender_email'] = getenv('BREVO_SENDER_EMAIL') ?: '';
$config['brevo_sender_name']  = getenv('BREVO_SENDER_NAME') ?: 'Eco-Bin';
$config['gmail_user']         = getenv('GMAIL_USER') ?: '';
$config['gmail_app_password'] = getenv('GMAIL_APP_PASSWORD') ?: '';
