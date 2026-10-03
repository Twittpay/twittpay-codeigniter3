# TwittPay for CodeIgniter 3

CodeIgniter 3 library and controller

Part of the [TwittPay](https://twittpay.com) addon family.

## Quick start

1. Download the latest zip from the **Releases** page of this repository.
2. Install it on your CodeIgniter 3 following the guide below.
3. Open the TwittPay settings and enter your **Brand Key**. You can get it from [your dashboard](https://twittpay.com/user/brands).
4. Make a small test payment to confirm everything works.

Payments are always re-verified on your server before an order or invoice is marked paid.

## Detailed installation guide

```text
===========================================================================
 TWITTPAY - CodeIgniter 3 SDK
===========================================================================

 WHERE IT GOES
   Extract this zip at your CodeIgniter 3 project root - the folder that has
   application/ and index.php in it. Three files land in place:

     application/libraries/Twittpay.php      the client
     application/config/twittpay.php         your Brand Key and gateway URL
     application/controllers/Twittpay.php    a working example, safe to delete

 SETUP
   1. Edit application/config/twittpay.php and put in your Brand Key and your
      gateway domain (Dashboard -> Brands for the key).

   2. Open application/config/config.php and exclude the webhook from CSRF:

        $config['csrf_exclude_uris'] = ['twittpay/webhook'];

      Do not skip this. The gateway's server has no CSRF token and never will,
      so without it CI3 answers every webhook with 403 - and a payment the
      merchant approves later never reaches your site.

   3. Visit /twittpay/pay/100 to try it end to end.

 USING IT
   $this->load->library('twittpay');

   $r = $this->twittpay->create_payment([
       'cus_name'    => 'John Doe',
       'cus_email'   => 'john@example.com',
       'amount'      => 100,
       'success_url' => site_url('checkout/back'),
       'cancel_url'  => site_url('checkout/cancelled'),
       'webhook_url' => site_url('checkout/webhook'),
       'metadata'    => ['order_id' => $orderId],
   ]);

   if (!empty($r['status']) && !empty($r['payment_url'])) {
       redirect($r['payment_url']);
   }

   On the way back:

   $v = $this->twittpay->verify_payment($this->input->get('transactionId'));

   if ($this->twittpay->is_paid($v, $orderTotal)) {
       $meta = $this->twittpay->decode_metadata($v);   // your order_id
       // mark it paid
   }

 WHAT TO WATCH
   * Never believe the return URL. ?status=completed is typed by whoever asks
     for the page. verify_payment() is the only thing that decides.
   * Always send webhook_url. The return URL only runs while the customer is
     still there; a payment approved an hour later has no browser left.
   * PENDING is not a failure. The money has been sent, the merchant has not
     approved it yet. Leave the order unpaid and wait - do not ask again.
   * metadata needs named keys: ['order_id' => '1043']. A plain list is
     rejected. The library casts it to an object for you.
   * The gateway charges BDT. Pricing in USD? Convert before sending and keep
     the original amount and currency in metadata.

 CHECKED
   All three files were checked with a lexer that balances braces only inside
   real PHP code. PHP itself was NOT run - there is no PHP binary on the
   machine this was built on, so php -l was never executed.
```
