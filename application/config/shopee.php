<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Shopee API Configuration
|--------------------------------------------------------------------------
|
| Configuration for Shopee Open Platform integration
|
*/

$config['shopee_host'] = 'https://partner.shopeemobile.com';
$config['shopee_partner_id'] = '2012584'; // Your partner ID
$config['shopee_partner_key'] = 'your_partner_key'; // Your partner key
$config['shopee_redirect_uri'] = base_url('shopee/callback');

// Token storage (you can change to database later)
$config['shopee_token_storage'] = 'session'; // 'session' or 'database'

// Rate limiting
$config['shopee_rate_limit'] = TRUE;
$config['shopee_rate_delay'] = 200; // milliseconds between requests
