<?php

namespace Larabookir\Gateway\Digipay;

//use Larabookir\Gateway\BazarPay\BazarPayException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;
use Larabookir\Gateway\Enum;
use Larabookir\Gateway\PortAbstract;
use Larabookir\Gateway\PortInterface;

class Digipay extends PortAbstract implements PortInterface
{
    const SERVER_URL = 'https://api.mydigipay.com';
    const VERSION = '2022-02-02';
    const OAUTH_URL = '/digipay/api/oauth/token';
    const PURCHASE_URL = '/digipay/api/tickets/business';
    const VERIFY_URL = '/digipay/api/purchases/verify/';
    const REVERSE_URL = '/digipay/api/reverse';
    const DELIVER_URL = '/digipay/api/purchases/deliver';
    const REFUNDS_CONFIG = '/digipay/api/refunds/config';
    const REFUNDS_REQUEST = '/digipay/api/refunds';

    protected $oauthToken;

    protected $paymentUrl;

    protected $providerId;

    protected $tiket;

    protected $settings;

    protected $productCode;

    protected $deliveryDate;

    protected $invoiceNumber;

    protected array $products;

    protected $refundAmount;

    public function __construct()
    {
        parent::__construct();
        $this->settings = (object) Config::get('gateway.Digipay');
        $this->oauthToken = $this->oauth();
    }

    protected function oauth()
    {
        $url = self::SERVER_URL . self::OAUTH_URL;

        $data = [
            'username' => $this->settings->username,
            'password' => $this->settings->password,
            'grant_type' => 'password',
        ];

        $response = Http::asForm()
            ->withHeader('Authorization', 'Basic '.base64_encode("{$this->settings->client_id}:{$this->settings->client_secret}"))
            ->post($url, $data);

        if ($response->status() != 200) {
            if ($response->status() == 401) {
                throw new PurchaseFailedException('خطا نام کاربری یا رمز عبور شما اشتباه می‌باشد.');
            }
            throw new PurchaseFailedException('خطا در هنگام احراز هویت.');
        }

        $body = json_decode($response->body(), true);

        $this->oauthToken = $body['access_token'];

        return $body['access_token'];
    }

    public function set($amount)
    {
        $this->amount = $amount;

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
        $this->userVerify();
        $this->verifyPayment();
        return $this;
    }

    public function setProducts(array $products)
    {
        $this->products = $products;

        return $this;
    }

    public function getProducts()
    {
        return $this->products;
    }

    public function setInvoiceNumber($invoiceNumber)
    {
        $this->invoiceNumber = $invoiceNumber;

        return $this;
    }

    public function getInvoiceNumber()
    {
        return $this->invoiceNumber;
    }

    public function setDeliveryDate($deliveryDate)
    {
        $date = \DateTime::createFromFormat('Y-m-d', $deliveryDate);

        if (!$date || $date->format('Y-m-d') !== $deliveryDate) {
            throw new \InvalidArgumentException("Invalid date format. Expected format: YYYY-MM-DD");
        }

        $this->deliveryDate = $date->getTimestamp();

        return $this;
    }

    public function getDeliveryDate()
    {
        return $this->deliveryDate;
    }

    public function setRefundAmount($refundAmount)
    {
        $this->refundAmount = $amount;

        return $this;
    }

    public function getRefundAmount()
    {
        return $this->refundAmount;
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

    public function setProductCode(string $productCode)
    {
        $this->productCode = $productCode;

        return $this;
    }

    protected function sendPayRequest()
    {
        $this->newTransaction();

        $this->providerId = $this->transactionId;

        $url = self::SERVER_URL . self::PURCHASE_URL . '?type=11';

        $params = [
            'amount'           => $this->amount,
            'cellNumber'       => $this->mobileNumber,
            'providerId'       => $this->providerId,
            'callbackUrl'      => $this->getCallback(),
            'basketDetailsDto' => [
                'items' => [
                    [
                        'productCode' => $this->productCode,
                        'productType' => '1',
                        'count' => '1',
                    ],
                ],
                'basketId' => $this->providerId,
            ],
        ];

        $headers = [
            'Agent' => 'WEB',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$this->oauthToken,
            'Digipay-Version' => self::VERSION,
        ];

        $response = Http::withHeaders($headers)
            ->post($url, $params);

        $body = json_decode($response->body(), true);

        if ($response->status() != 200) {
            $message = $body['result']['message'] ?? 'خطا در هنگام درخواست برای پرداخت رخ داده است.';
            throw new PurchaseFailedException($message);
        }
        $this->tiket = $body['ticket'];
        $this->setPaymentUrl($body['redirectUrl']);


        return $this->getTiket();
    }

    protected function commit()
    {
        // No use
    }

    protected function verifyPayment()
    {
        $this->refId = Request::input('type');  // Save Type As ref_id In DataBase
        $this->trackingCode = Request::input('trackingCode');

        $url = self::SERVER_URL . self::VERIFY_URL . $this->trackingCode . '?type=' . $this->refId;

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->oauthToken,
        ])
            ->post($url);

        $body = json_decode($response->body(), true);
        // check amount, trackingcode, result.status and responce status
        // check amount, trackingcode, result.status and responce status
        if ($response->status() != 200
            && $body['trackingCode'] != $this->trackingCode
            && $body['amount'] != $this->amount
            && $body['result']['status'] != 0
        ) {
            $message = $body['result']['message'] ?? 'تراکنش تایید نشد';
            throw new InvalidPaymentException($message, (int) $response->status());
        }
        $this->transactionSucceed();
        $this->newLog($response->status(), Enum::TRANSACTION_SUCCEED_TEXT);
    }

    public function deliver()
    {
        $url = self::SERVER_URL . self::DELIVER_URL;

        $type = $this->refId;

        if (empty($type)) {
            throw new PurchaseFailedException('"type" is required for this method.');
        }
        if (!in_array($type, [5, 13])) {
            throw new PurchaseFailedException('This method is not supported for this type.');
        }

        if (!is_array($products)) {
            throw new PurchaseFailedException('"products" must be an array.');
        }

        $data = [
            'invoiceNumber' => $this->invoiceNumber,
            'deliveryDate'  => $this->deliveryDate,
            'trackingCode'  => $this->trackingCode,
            'products'      => $this->products,
        ];

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->oauthToken,
        ])
            ->post($url, $data);

        $body = json_decode($response->body(), true);
        if ($response->status() != 200 || (isset($body['result']['code']) && $body['result']['code'] != 0)) {
            $message = $body['result']['message'] ?? 'خطا در هنگام درخواست برای تحویل کالا رخ داده است.';
            throw new InvalidPaymentException($message, $response->status());
        }
        return $body;
    }

    protected function userVerify()
    {
        $result = strtoupper(Request::input('result'));
        if ($result == 'SUCCESS') {
            return true;
        }

        $this->transactionFailed();
        throw new PurchaseFailedException($result);
        //check input success
    }

    public function refundTransaction(string $providerId)
    {
        $transaction = $this->getTable()->whereId($providerId)->first();

//        dd ($transaction);

        $url = self::SERVER_URL . self::REFUNDS_REQUEST . '?type=' . $transaction->ref_id;

//        dd($transaction->id);
//        dd($url);
//        dd($transaction->ref_id);
//        dd($transaction->price);

        if (empty($transaction->ref_id)) {
            throw new PurchaseFailedException('"type" is required for this method.');
        }

        if (empty($transaction->id)) {
            throw new PurchaseFailedException('"providerId" is required for this method.');
        }

        if (empty($transaction->price)) {
            throw new PurchaseFailedException('"amount" is required for this method.');
        }

        $data = [
            'providerId'       => $transaction->id,
            'amount'           => intval($transaction->price),
            'saleTrackingCode' => $transaction->tracking_code,
        ];

//        dd($data);


        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->oauthToken,
        ])->post($url, $data);


        $body = json_decode($response->body(), true);

//        dd($body);

        if ($response->status() != 200 || (isset($body['result']['code']) && $body['result']['code'] != 0)) {
            $message = $body['result']['message'] ?? 'خطا در هنگام درخواست مرجوعی تراکنش رخ داده است.';
            throw new InvalidPaymentException($message, $response->status());
        }

        $this->newLog( (int) $body['result']['status'], $body['trackingCode']);
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

    protected function getTiket()
    {
        return $this->tiket;
    }

    protected function transactionSucceed()
    {
        return $this->getTable()->whereId($this->transactionId)->update([
            'status'        => Enum::TRANSACTION_SUCCEED,
            'tracking_code' => $this->trackingCode,
            'ref_id'        => $this->refId,
            'card_number'   => $this->cardNumber,
            'payment_date'  => Carbon::now(),
            'updated_at'    => Carbon::now(),
        ]);
    }
}


