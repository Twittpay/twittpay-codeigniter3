<?php
defined('BASEPATH') || exit('No direct script access allowed');

/**
 * TwittPay - a working CodeIgniter 3 controller.
 *
 * Three URLs, and one of them is the one everybody forgets:
 *
 *   /twittpay/pay/100        start a payment
 *   /twittpay/back           the customer returns here
 *   /twittpay/webhook        the gateway calls here, with no browser involved
 *
 * BEFORE THIS WORKS, open application/config/config.php and add the webhook to
 * the CSRF exclusions:
 *
 *   $config['csrf_exclude_uris'] = ['twittpay/webhook'];
 *
 * The gateway's server has no CSRF token, so without that line CI3 answers every
 * webhook with a 403 and payments approved later never land.
 *
 * Rename this file and the class if the route does not suit you - nothing else
 * refers to it.
 */
class Twittpay extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->load->helper('url');
        $this->load->library('twittpay');
    }

    /** Start a payment. In a real site the amount comes from your own order row. */
    public function pay($amount = 100)
    {
        // Your own order id. Generate it, or pass one in - just never trust the
        // amount from the URL the way this demo does.
        $orderId = 'ORD-' . time();

        $result = $this->twittpay->create_payment(array(
            'cus_name'    => 'Demo Customer',
            'cus_email'   => 'demo@example.com',
            'amount'      => $amount,
            'success_url' => site_url('twittpay/back'),
            'cancel_url'  => site_url('twittpay/cancelled'),
            'webhook_url' => site_url('twittpay/webhook'),
            'metadata'    => array(
                'order_id' => $orderId,
                'source'   => 'codeigniter3',
            ),
        ));

        if (!empty($result['status']) && !empty($result['payment_url'])) {
            redirect($result['payment_url']);

            return;
        }

        show_error('Could not start the payment: ' . (isset($result['message']) ? $result['message'] : 'unknown error'), 502);
    }

    /** The customer is back. The URL is a nudge; the verify call is the truth. */
    public function back()
    {
        $transactionId = $this->input->get('transactionId');

        if (empty($transactionId)) {
            show_error('No transaction id on the return URL.', 400);

            return;
        }

        $verified = $this->twittpay->verify_payment($transactionId);
        $status   = $this->twittpay->read_status($verified);
        $meta     = $this->twittpay->decode_metadata($verified);
        $orderId  = isset($meta['order_id']) ? $meta['order_id'] : '';

        // Look the real total up from your own table by $orderId and pass it in,
        // so a 1 taka payment cannot settle a 1000 taka order.
        $expected = 0;

        if ($this->twittpay->is_paid($verified, $expected)) {
            // $this->your_model->mark_paid($orderId, $transactionId);
            echo 'Payment received for order ' . html_escape($orderId);

            return;
        }

        if ($status === 'PENDING') {
            echo 'Your payment is being checked. You will not need to pay again.';

            return;
        }

        echo 'Payment not completed. Status: ' . html_escape($status !== '' ? $status : 'unknown');
    }

    public function cancelled()
    {
        echo 'Payment cancelled. Nothing has been charged.';
    }

    /**
     * The webhook. No browser here, so nothing is echoed for a human and nothing
     * redirects. It can arrive twice for one payment - pending, then completed -
     * so whatever you do must be safe to run twice.
     */
    public function webhook()
    {
        $hook          = $this->twittpay->read_webhook();
        $transactionId = $hook['transactionId'];

        if ($transactionId === '') {
            $this->output->set_status_header(400)->set_output('No transaction id.');

            return;
        }

        $verified = $this->twittpay->verify_payment($transactionId);
        $status   = $this->twittpay->read_status($verified);
        $meta     = $this->twittpay->decode_metadata($verified);
        $orderId  = isset($meta['order_id']) ? $meta['order_id'] : '';

        if ($status === 'COMPLETED') {
            // $this->your_model->mark_paid($orderId, $transactionId);  <- idempotent
        } elseif ($status === 'PENDING') {
            // Leave the order alone. This file will be called again with the answer.
        } else {
            // Rejected, or never paid.
        }

        // Always 200 once handled, so the gateway stops retrying.
        $this->output->set_status_header(200)->set_output('OK');
    }
}
