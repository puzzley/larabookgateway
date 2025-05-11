<?php

namespace Larabookir\Gateway\Stripe;

//use Larabookir\Gateway\BazarPay\BazarPayException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Larabookir\Gateway\Enum;
use Larabookir\Gateway\Paypal\PaypalException;
use Larabookir\Gateway\PortAbstract;
use Larabookir\Gateway\PortInterface;

class Stripe extends PortAbstract implements PortInterface
{
    /**
     * CHECKOUT_SESSION
     *
     * @var string
     */
    const CHECKOUT_SESSION = 'https://api.stripe.com/v1/checkout/sessions';

    /**
     * VERIFY_URL
     *
     * @var string
     */
    const VERIFY_URL = 'https://api.stripe.com/v1/checkout/sessions/';

    /**
     * VERIFY_PAYMENT
     *
     * @var string
     */
    const VERIFY_PAYMENT = 'https://api.stripe.com/v1/payment_intents/';

    /**
     * Currency
     *
     * @var string
     */
    protected $currency;

    /**
     * paymentUrl
     *
     * @var string
     */
    protected $paymentUrl;

    public function set($amount)
    {
        $this->amount = $amount;

        $this->currency = $this->config->get('gateway.stripe.currency') ?? 'USD';   

        return $this;
    }

    public function ready()
    {
        $this->sendPayRequest();

        return $this;
    }

    public function redirect()
    {
        return \Redirect::to($this->getGatewayUrl());
    }

    public function verify($transaction)
    {
        parent::verify($transaction);

        if(!$transaction->ref_id){
            throw new StripeException('Not Authorize');
        }
        $this->verifyPayment($transaction->ref_id);
        return $this;
    }

    function setCallback($url)
    {
        $this->callbackUrl = $url;
        return $this;
    }

    function getCallback()
    {
        if (!$this->callbackUrl) {
            $this->callbackUrl = $this->config->get('gateway.Digipay.callback-url');
        }

        return $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);
    }

    protected function sendPayRequest()
    {
        $this->newTransaction();
        try {
            $response = Http::asForm()
                ->timeout(25)
                ->withToken($this->config->get('gateway.stripe.client_secret'))
                ->post(self::CHECKOUT_SESSION, [
                    'payment_method_types[]' => 'card',
                    'line_items[0][price_data][currency]' => $this->config->get('gateway.stripe.currency'),
                    'line_items[0][price_data][product_data][name]' => 'Product Name',
                    'line_items[0][price_data][unit_amount]' => $this->amount,
                    'line_items[0][quantity]' => 1,
                    'mode' => 'payment',
                    'success_url' => $this->getCallback(),
                    'cancel_url' => $this->getCallback(),
                ]);
            $body = json_decode($response, true);

            if($response->status() != 200){
                throw new StripeException($response['error']['message']);
            }
        }catch (\Exception $e){
            $this->transactionFailed();
            $this->newLog('RestfulFault', $e->getMessage());
            throw $e;
        }
        if ($body == null) return "Order of Stripe API call not created";

        if ($response->status() == 200){
            $refId   = $body['id'];
            $gateway = urldecode($body['url']);
        }else{
            $this->transactionFailed();
            throw new StripeException($response['error']['message']);
        }

        $this->refId = $refId;
        $this->transactionSetRefId();
        $this->setPaymentUrl($gateway);
    }

    public function verifyPayment(string $refId)
    {
        $url = self::VERIFY_URL . $refId;

        $response = Http::withToken($this->config->get('gateway.stripe.client_secret'))
            ->timeout(25)
            ->get($url);

        $body = json_decode($response, true);


        if ($response->status() == 200
            && $body['amount_total'] == $this->amount
            && isset($body['payment_intent'])
        ){
            $paymentIntentId = $body['payment_intent'];
        }else {
            $this->transactionFailed();
            $code = $body['error_code'] ?? $response->status();
            $message = $body['error']['message'];
            $this->newLog($code, $message);
            throw new StripeException($code);
        }

        $payment_url = self::VERIFY_PAYMENT . $paymentIntentId;


        $payment_response = Http::withToken($this->config->get('gateway.stripe.client_secret'))
            ->timeout(25)
            ->get($payment_url);

        $payment = json_decode($payment_response, true);

        if ($payment_response->status() == 200
            && isset($payment['amount']) && $payment['amount'] == $this->amount
            && isset($payment['status']) && $payment['status'] == 'succeeded'
        ){
            $this->trackingCode = $payment['id'];
            $this->transactionSucceed();
            $this->newLog($payment['status'], Enum::TRANSACTION_SUCCEED_TEXT);
            return true;
        }

        $this->transactionFailed();
        $code    = $payment['error_code'] ?? $payment_response->status();
        $message = $payment['error']['message'];
        $this->newLog($code, $message);
        throw new StripeException($code);
    }

    public function getGatewayUrl()
    {
        return $this->paymentUrl;
    }

    private function setPaymentUrl(string $paymentUrl)
    {
        $this->paymentUrl = $paymentUrl;

        return $this;
    }
}


