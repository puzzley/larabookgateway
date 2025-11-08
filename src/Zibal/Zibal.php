<?php

namespace Larabookir\Gateway\Zibal;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;
use Larabookir\Gateway\Enum;
use Larabookir\Gateway\PortAbstract;
use Larabookir\Gateway\PortInterface;
use Larabookir\Gateway\Zibal\ZibalException;

class Zibal extends PortAbstract implements PortInterface
{
    const SERVER_URL = 'https://gateway.zibal.ir';
    const REQUEST_URL = '/v1/request';
    const START_URL = '/start/';
    const VERIFY_URL = '/v1/verify';
    const INQUIRY_URL = '/v1/inquiry';
    protected $paymentUrl;
    protected $settings;
    protected $invoiceNumber;
    
    public function __construct()
    {
        parent::__construct();
        $this->settings = (object) Config::get('gateway.Zibal');
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
    
    public function setInvoiceNumber($invoiceNumber)
    {
        $this->invoiceNumber = $invoiceNumber;
    }

    public function getInvoiceNumber()
    {
        return $this->invoiceNumber;
    }

    function setCallback($url)
    {
        $this->callbackUrl = $url;
        return $this;
    }

    function getCallback()
    {
        if (!$this->callbackUrl) {
            $this->callbackUrl = $this->config->get('gateway.Zibal.callbackUrl');
        }

        return $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);
    }
    
    private function request()
    {
        $url = self::SERVER_URL . self::REQUEST_URL;
        
        $params = [
            'merchant'      => $this->config->get('gateway.Zibal.merchant'),
            'amount'        => $this->amount,
            'cellNumber'    => $this->mobileNumber,
            'orderId'       => $this->transactionId,
            'callbackUrl'   => $this->getCallback(),
        ];
        
        $headers = [
            'Agent' => 'WEB',
            'Content-Type' => 'application/json',
        ];
        
        $response = Http::withHeaders($headers)
            ->post($url, $params);
        
        $body = json_decode($response->body(), true);
        
        if ($response->status() != 200 || $body['result'] != 100) {
            throw new ZibalException($body['result']);
        }
        
        return $body['trackId'];
    }

    protected function sendPayRequest()
    {
        $this->newTransaction();
        $trackId = $this->request();
        $url = self::SERVER_URL . self::START_URL . $trackId;
        $this->setPaymentUrl($url);
    }
    
    protected function verifyPayment()
    {
        $this->trackingCode = \Request::input('trackId');

        $url = self::SERVER_URL . self::VERIFY_URL;
        
        $params = [
            'trackId'       => $this->refId,
            'merchant'      => $this->config->get('gateway.Zibal.merchant'),
        ];
        
        $response = \Http::withHeaders([
            'Content-Type'  => 'application/json',
        ])->post($url, $params);
        
        $body = json_decode($response->body(), true);
        
        if ($response->status() != 200
            || $body['result']  != 100
            || $body['message'] != 'success'
            || $body['amount']  != $this->amount
        ) {
            throw new ZibalException($body['result']);
        }
        $this->transactionSucceed();
        $this->newLog($response->status(), Enum::TRANSACTION_SUCCEED_TEXT);
    }
    
    protected function userVerify()
    {
        $status = \Request::get('status');
        if ($status == 1 || $status == 2){
            return true;
        }
        $this->transactionFailed();
        throw new ZibalException($status);
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


