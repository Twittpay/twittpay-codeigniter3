<?php
defined('BASEPATH') || exit('No direct script access allowed');

/**
 * TwittPay - CodeIgniter 3 library.
 * ---------------------------------------------------------------------------
 * Drop this file into application/libraries/ and load it the normal way:
 *
 *   $this->load->library('twittpay', [
 *       'api_key'  => 'YOUR_API_KEY',
 *       'base_url' => 'https://checkout.twittpay.com',
 *   ]);
 *
 * Or put the two values in application/config/twittpay.php as
 * $config['twittpay_api_key'] / $config['twittpay_base_url'] and load it
 * with no parameters at all.
 *
 * THE FLOW
 *   1. create_payment()            -> payment_url, redirect the customer to it
 *   2. they come back with ?transactionId=...
 *   3. verify_payment()            -> the truth. Deliver on this and nothing else
 *   4. your webhook_url is called server to server, possibly twice
 *
 * Note for CI3: 'twittpay' is a library, not a controller, so the webhook URL
 * you send must point at a controller method of yours - and that method must be
 * excluded from CSRF checks in application/config/config.php:
 *
 *   $config['csrf_exclude_uris'] = ['payment/twittpay_webhook'];
 *
 * A webhook comes from the gateway's server, which has no CSRF token and never
 * will. Without that line CI3 rejects every webhook with a 403 and the payment
 * silently never lands.
 */
class Twittpay
{
    /** @var string */
    protected $api_key = '';

    /** @var string scheme://host, no trailing slash */
    protected $base_url = '';

    /** @var int */
    protected $timeout = 30;

    /** @var string */
    protected $last_error = '';

    public function __construct($params = array())
    {
        // Fall back to application/config/twittpay.php when nothing is passed.
        if (empty($params['api_key']) && function_exists('get_instance')) {
            $ci = get_instance();

            if (isset($ci->config)) {
                $configured = $ci->config->item('twittpay_api_key');

                if (!empty($configured)) {
                    $params['api_key'] = $configured;
                }

                $configuredUrl = $ci->config->item('twittpay_base_url');

                if (!empty($configuredUrl) && empty($params['base_url'])) {
                    $params['base_url'] = $configuredUrl;
                }
            }
        }

        $this->set_credentials(
            isset($params['api_key']) ? $params['api_key'] : '',
            isset($params['base_url']) ? $params['base_url'] : ''
        );

        if (!empty($params['timeout'])) {
            $this->timeout = (int) $params['timeout'];
        }
    }

    public function set_credentials($api_key, $base_url)
    {
        $this->api_key  = trim((string) $api_key);
        $this->base_url = $this->normalise_base_url($base_url);

        return $this;
    }

    /** Turn whatever was typed into scheme://host. */
    public function normalise_base_url($url)
    {
        $raw    = rtrim(trim((string) $url), '/');
        $scheme = parse_url($raw, PHP_URL_SCHEME);
        $host   = parse_url($raw, PHP_URL_HOST);

        if (empty($host)) {
            $host = strtok(ltrim(preg_replace('#^[a-z]+://#i', '', $raw), '/'), '/');
        }

        if (empty($scheme)) {
            $scheme = 'https';
        }

        if (empty($host)) { $host = 'checkout.twittpay.com'; }
        return 'https://' . $host;
    }

    /**
     * Step 1. amount, success_url and cancel_url are required; cus_name,
     * cus_email, webhook_url and metadata are optional.
     *
     * @return array status 1 + payment_url, or status 0 + message.
     */
    public function create_payment($data = array())
    {
        foreach (array('amount', 'success_url', 'cancel_url') as $required) {
            if (empty($data[$required])) {
                return array('status' => 0, 'message' => 'Missing required field: ' . $required);
            }
        }

        $payload = array(
            'cus_name'    => isset($data['cus_name']) ? (string) $data['cus_name'] : 'Default Name',
            'cus_email'   => isset($data['cus_email']) ? (string) $data['cus_email'] : 'default@gmail.com',
            'amount'      => number_format((float) $data['amount'], 2, '.', ''),
            'success_url' => (string) $data['success_url'],
            'cancel_url'  => (string) $data['cancel_url'],
        );

        if (!empty($data['webhook_url'])) {
            $payload['webhook_url'] = (string) $data['webhook_url'];
        }

        // Has to arrive as a JSON object. A PHP list would encode as a JSON
        // array and be rejected, so it is cast here.
        if (!empty($data['metadata'])) {
            $payload['metadata'] = (object) $data['metadata'];
        }

        return $this->call('/api/payment/create', $payload);
    }

    /** Step 3. The only answer worth acting on. */
    public function verify_payment($transaction_id)
    {
        $transaction_id = trim((string) $transaction_id);

        if ($transaction_id === '') {
            return array('status' => 0, 'message' => 'Missing transaction id.');
        }

        return $this->call('/api/payment/verify', array('transaction_id' => $transaction_id));
    }

    /**
     * True only for a payment you may deliver. Pass the order total as $expected
     * so a customer cannot pay 1 taka for a 1000 taka order.
     */
    public function is_paid($verified, $expected = 0)
    {
        if ($this->read_status($verified) !== 'COMPLETED') {
            return false;
        }

        if ((float) $expected <= 0) {
            return true;
        }

        $paid = isset($verified['amount']) ? (float) $verified['amount'] : 0;

        return ($paid + 0.01) >= (float) $expected;
    }

    /** COMPLETED, PENDING, ERROR or ''. */
    public function read_status($verified)
    {
        if (!is_array($verified) || !isset($verified['status']) || !is_string($verified['status'])) {
            return '';
        }

        return strtoupper(trim($verified['status']));
    }

    /** metadata comes back as a JSON string. This unpacks it. */
    public function decode_metadata($verified)
    {
        if (!is_array($verified) || !isset($verified['metadata'])) {
            return array();
        }

        $meta = $verified['metadata'];

        if (is_array($meta)) {
            return $meta;
        }

        if (is_object($meta)) {
            return (array) $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return array();
    }

    /**
     * Read the webhook body. Form encoded, and NOT signed - so this hands you a
     * transaction id to go and verify, never a decision.
     */
    public function read_webhook()
    {
        $body = $_POST;

        if (empty($body)) {
            $raw = file_get_contents('php://input');

            if (!empty($raw)) {
                $decoded = json_decode($raw, true);

                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }

        $out = array(
            'transactionId' => '',
            'paymentAmount' => '',
            'paymentFee'    => '',
            'paymentMethod' => '',
            'status'        => '',
        );

        foreach (array('transactionId', 'transaction_id') as $key) {
            if (!empty($body[$key])) {
                $out['transactionId'] = trim((string) $body[$key]);
                break;
            }
        }

        foreach (array('paymentAmount', 'paymentFee', 'paymentMethod', 'status') as $key) {
            if (isset($body[$key])) {
                $out[$key] = trim((string) $body[$key]);
            }
        }

        return $out;
    }

    public function last_error()
    {
        return $this->last_error;
    }

    /** One POST. JSON in, array out. */
    protected function call($endpoint, $payload)
    {
        $this->last_error = '';

        $ch = curl_init($this->base_url . $endpoint);

        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json',
                'Accept: application/json',
                'API-KEY: ' . $this->api_key,
            ),
        ));

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            $this->last_error = ($error !== '') ? $error : 'No response from the gateway.';

            return array('status' => 0, 'message' => $this->last_error);
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            $this->last_error = 'The gateway sent back something that is not JSON.';

            return array('status' => 0, 'message' => $this->last_error);
        }

        return $decoded;
    }
}
