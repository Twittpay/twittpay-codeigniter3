<?php
defined('BASEPATH') || exit('No direct script access allowed');

/**
 * TwittPay - optional config file.
 *
 * With this file in place you can load the library with no parameters:
 *
 *   $this->load->library('twittpay');
 *
 * Passing them to the loader still works and overrides what is here.
 */

$config['twittpay_api_key'] = '';

// Your gateway's endpoint URL, e.g. https://checkout.twittpay.com
// Scheme and host only; a pasted path is trimmed off. No default on purpose.
