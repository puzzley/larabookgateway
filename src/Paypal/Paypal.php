<?php

namespace Larabookir\Gateway\Paypal;

use Google\Rpc\Context\AttributeContext\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Input;
use Larabookir\Gateway\Enum;
use Larabookir\Gateway\PortAbstract;
use Larabookir\Gateway\PortInterface;
use Illuminate\Support\Facades\Config;
use mysql_xdevapi\Exception;

class Paypal extends PortAbstract implements PortInterface
{
    /**
     * Authentication url
     *
     * @var string
     */
    const AUTH_URL= 'https://api-m.sandbox.paypal.com/v1/oauth2/token';

    /**
     * Payment Name
     *
     * @var string
     */
    protected $name;

    /**
     * Address of iran RESTFUL server
     *
     * @var string
     */
    protected $mainServer = 'https://api-m.paypal.com/v2/checkout/orders';

    /**
     * Address of main RESTFUL server
     *
     * @var string
     */
    protected $serverUrl;

    /**
     * Address of sandbox RESTFUL server
     *
     * @var string
     */
    protected $sandboxServer = 'https://api-m.sandbox.paypal.com/v2/checkout/orders';

    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = '';

    /**
     * Address of sandbox gate for redirect
     *
     * @var string
     */
    protected $sandboxGateUrl = 'https://api.sandbox.paypal.com/v2/checkout/orders';

    /**
     * Currency
     *
     * @var string
     */
    protected $currency;


    public function boot()
    {
        $this->setServer();
    }

    /**
     * Set server for Restful transfers data
     *
     * @return void
     */
    protected function setServer()
    {
        $server = $this->config->get('gateway.paypal.server');
        switch ($server) {
            case 'test':
                return $this->serverUrl = $this->sandboxServer;
                break;

            case 'main':
                return $this->serverUrl = $this->mainServer;
                break;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function set($amount)
    {
        $this->amount = $amount;
        // for USD amount must set be like 1.00

        $this->currency = $this->config->get('gateway.paypal.currency');

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function ready()
    {
        $this->sendPayRequest();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        if ($this->config->get('gateway.paypal.server') == 'test')
            return \Redirect::to($this->sandboxGateUrl);

        else if ($this->config->get('gateway.paypal.server') == 'main')
            return \Redirect::to($this->gatewayUrl);
    }

    /**
     * Sets callback url
     * @param $url
     */
    public function setCallback($url)
    {
        $this->callbackUrl = $url;
        return $this;
    }

    /**
     * Gets callback url
     * @return string
     */
    public function getCallback()
    {
        if (!$this->callbackUrl) $this->callbackUrl = $this->config->get('gateway.paypal.callback_url');

        return $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);
    }

    public function getAccessToken()
    {

        $response = Http::asForm()
            ->withBasicAuth($this->config->get('gateway.paypal.client_id'), $this->config->get('gateway.paypal.client_secret'))
            ->post(self::AUTH_URL, ['grant_type' => 'client_credentials']);

        if($response->status() != 200){
            if ($response->status() == 401) {
                throw new PaypalException($response->status());
            }
            throw new PaypalException($response->status());
        }

        $body = json_decode($response->body(), true);
        return $body['access_token'];
    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws PaypalException
     */
    protected function sendPayRequest()
    {

        $this->newTransaction();
        $fields = [
            'order_name' => 'Custom order',
            'url' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders',
            'callback_url' => $this->getCallback(),
            'currency' => $this->config->get('gateway.paypal.currency'),
            'intent' => 'CAPTURE',
            'amount' => $this->amount,
            'proxy_status' => $this->config->get('gateway.proxy.status'),
            'proxy_address' => $this->config->get('gateway.proxy.address'),
            'proxy_port' => $this->config->get('gateway.proxy.port'),
        ];

        $data = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => $fields['currency'],
                        'value' => $fields['amount'],
                        'breakdown' => [
                            'item_total' => [
                                'currency_code' => $fields['currency'],
                                'value' => $fields['amount']
                            ]
                        ]
                    ]
                ]
            ],
            'application_context' => [
                'return_url' => $fields['callback_url'],
                'cancel_url' => $fields['callback_url']
            ]
        ];

        // Send the HTTP POST request using the Http facade
        try {
            $response = Http::withHeaders([
                'Prefer' => 'return=minimal',
                'Content-Type' => 'application/json',
                'Authorization' => "Bearer " .$this->getAccessToken()
            ])
                ->timeout(25)
                ->withOptions([
                    CURLOPT_PROXY => $fields['proxy_address'], // Set proxy address
                    CURLOPT_PROXYPORT => $fields['proxy_port'] // Set proxy port
                ])
                ->post($fields['url'], $data);

            $body = json_decode($response, true);



            if ($response->status() != 201 && $response->status() != 200) {
                return "PROBLEM IN RESPONSE STATUS CODE IS: $response->status()";
            }
        }catch (Exception $e){
            $this->transactionFailed();
            $this->newLog('RestfulFault', $e->getMessage());
            throw $e;
        }

        // If the request was not created can be because of user connection problem
        if ($body === NULL) {
            return "Order of Paypal API call not created";
        }


        if ($response['status'] && 'CREATED' === $response['status']) {
            $refId = $response['id'];
            $gatewayUrl = $response['links'][1]['href'];
        } else {
            $this->transactionFailed();
            throw new PaypalException($response['status']);
        }
        // set refId and gatewayUrl
        $this->refId = $refId ?? '';
        $this->transactionSetRefId();

        $this->gatewayUrl = $gatewayUrl ?? '';
    }

    /**
     * {@inheritdoc}
     * @throws PaypalException
     */
    public function verify($transaction)
    {
        parent::verify($transaction);
        // if ref id of transaction was not set
        if (!$transaction->ref_id)
            throw new PaypalException(401);
        $this->verifyPayment($transaction->ref_id);
        return $this;
    }

    /**
     * Verify user payment from paypal server
     *
     * @return bool
     *
     * @throws PaypalException
     */
    protected function verifyPayment(string $refId)
    {
        // fields informations and proxy address and port
        $fields = [
            'url' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders',
            'proxy_address' => (Config::get('services.proxy.address') != NULL) ? Config::get('services.proxy.address') : $this->config->get('gateway.proxy.address'),
            'proxy_port' => $this->config->get('gateway.proxy.port'),
        ];

        $capture_url = $fields['url'] . '/' . $refId . '/capture';

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Prefer' => 'return=minimal',
            'Authorization' => "Bearer " .$this->getAccessToken()
        ])
            ->timeout(20)
            ->withOptions([
                CURLOPT_PROXY => $fields['proxy_address'], // Set proxy address
                CURLOPT_PROXYPORT => $fields['proxy_port'] // Set proxy port
            ])
            ->post($capture_url, $fields);
        $body = json_decode($response, true);

        if (
            $response->status() === 201 &&
            isset($body['status']) && $body['status'] === 'COMPLETED'  &&
            isset($body['purchase_units'][0]['payments']['captures'][0]['amount']['value']) && $body['purchase_units'][0]['payments']['captures'][0]['amount']['value'] == $this->amount
        ) {
            // store tracking code to the database and set transaction to succeed
            $this->trackingCode = $body['purchase_units'][0]['payments']['captures'][0]['id'];
            $this->transactionSucceed();
            $this->newLog($body['status'], Enum::TRANSACTION_SUCCEED_TEXT);
            return true;
        }

        // set transaction fail and create code and message error
        $this->transactionFailed();
        $code = $body['error_code'] ?? $response->status();
        $message = $body['message'] ?? PaypalException::$errors[$code];
        $this->newLog($code, $message);
        throw new PaypalException($code);
    }
}
