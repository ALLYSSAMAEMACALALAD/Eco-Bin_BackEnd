<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/* Eco-Bin application settings. Secrets come from environment variables. */

// Points earned per kilogram of sorted, verified recyclables
$config['points_per_kg'] = ['plastic' => 10, 'paper' => 5, 'metal' => 20, 'glass' => 8];

$config['upload_max_bytes'] = 5 * 1024 * 1024;

// Cloudinary unsigned upload. If unset, photos are saved to public/uploads.
$config['cloudinary_cloud_name']    = getenv('CLOUDINARY_CLOUD_NAME') ?: '';
$config['cloudinary_upload_preset'] = getenv('CLOUDINARY_UPLOAD_PRESET') ?: '';

// SMS provider: 'semaphore' or '' (empty = reminders are only queued in the notifications table)
$config['sms_provider'] = getenv('SMS_PROVIDER') ?: '';
$config['sms_api_key']  = getenv('SMS_API_KEY') ?: '';
$config['sms_sender']   = getenv('SMS_SENDER') ?: 'EcoBin';
