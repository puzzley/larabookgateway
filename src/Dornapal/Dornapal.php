<?php

namespace Larabookir\Gateway\Dornapal;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;
use Larabookir\Gateway\Enum;
use Larabookir\Gateway\PortAbstract;
use Larabookir\Gateway\PortInterface;

class Dornapal extends PortAbstract implements PortInterface
{
    private const SERVER_URL = 'https://api.dornapal.ir';
    private const REQUEST_URL = '/api/v1/payments/request';
    private const VERIFY_URL = '/api/v1/payments/verify';

    public function set($amount)
    {
        $this->amount = (int) $amount;

        return $this;
    }

    public function ready()
    {
        $this->sendPayRequest();

        return $this;
    }

    public function redirect()
    {
        $gatewayUrl = $this->getGatewayUrl();

        return \View::make('app.gateway.pay-link-redirector', compact('gatewayUrl'));
    }

    public function verify($transaction)
    {
        parent::verify($transaction);
        $this->userVerify();
        $this->verifyPayment();

        return $this;
    }

    public function setCallback($url)
    {
        $this->callbackUrl = $url;

        return $this;
    }

    public function getCallback()
    {
        if (!$this->callbackUrl) {
            $this->callbackUrl = $this->config->get('gateway.dornapal.callback-url');
        }

        return $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);
    }

    protected function sendPayRequest()
    {
        $this->assertRequestAmount();
        $this->newTransaction();

        try {
            $data = $this->request();
        } catch (DornapalException $e) {
            $this->transactionFailed();
            $this->newLog($e->getCode(), mb_substr($e->getMessage(), 0, 255));
            throw $e;
        }

        $this->refId = $data['authority'];
        $this->trackingCode = $data['trackingCode'];
        $this->gatewayUrl = $data['paymentUrl'];
        $this->transactionSetRefId();
        $this->getTable()->whereId($this->transactionId)->update([
            'tracking_code' => $this->trackingCode,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function request()
    {
        $payload = [
            'merchant_key' => $this->merchantKey(),
            'order_id' => (string) $this->transactionId(),
            'amount' => $this->requestAmount(),
            'currency' => $this->currency(),
            'callback_url' => $this->getCallback(),
        ];

        if (!empty($this->description)) {
            $payload['description'] = $this->description;
        }

        $body = $this->post(self::REQUEST_URL, $payload, (string) $this->transactionId());
        $data = $body['data'] ?? null;

        if (!is_array($data) || (int) ($data['code'] ?? 0) !== 100 || empty($data['authority']) || empty($data['paymentUrl'])) {
            $this->throwFromBody($body, 0);
        }

        return $data;
    }

    protected function userVerify()
    {
        $status = (string) Request::input('status', '');

        if (strcasecmp($status, 'OK') === 0) {
            return true;
        }

        $message = DornapalException::$errors[$status] ?? 'بازگشت از درگاه نامعتبر است.';
        $this->newLog(substr($status !== '' ? $status : 'NO_STATUS', 0, 10), $message);

        throw new DornapalException($message, $status !== '' ? $status : 0);
    }

    protected function verifyPayment()
    {
        $authority = (string) $this->refId;

        if ($authority === '') {
            $this->transactionFailed();
            throw new DornapalException('شناسه پرداخت ذخیره نشده است.', -206);
        }

        try {
            $body = $this->post(self::VERIFY_URL, [
                'merchant_key' => $this->merchantKey(),
                'authority' => $authority,
            ]);
        } catch (DornapalException $e) {
            if (!in_array($e->getCode(), [0, 429, 500, 503, -900], true)) {
                $this->transactionFailed();
            }
            $this->newLog($e->getCode(), mb_substr($e->getMessage(), 0, 255));
            throw $e;
        }

        $data = $body['data'] ?? [];
        $code = (int) ($data['code'] ?? 0);
        $payment = $data['payment'] ?? null;

        if ($code === 202 || !is_array($payment)) {
            $message = DornapalException::$errors['202'];
            $this->newLog(202, $message);
            throw new DornapalException($message, 202);
        }

        $confirmed = $code === 100 || $code === 201;
        $requested = (string) ($payment['amounts']['requested']['amount'] ?? '');
        $requestedCurrency = (string) ($payment['amounts']['requested']['currency'] ?? '');
        $orderId = isset($payment['orderId']) ? (string) $payment['orderId'] : null;

        $amountMatches = $requestedCurrency === 'IRR'
            && $requested !== ''
            && (int) $requested === (int) $this->amount;
        $orderMatches = $orderId === null || $orderId === (string) $this->transactionId;

        if (!$confirmed || ($payment['status'] ?? null) !== 'CONFIRMED' || !$amountMatches || !$orderMatches) {
            $message = (!$amountMatches || !$orderMatches)
                ? 'مبلغ یا شناسه سفارش با پرداخت تأییدشده همخوانی ندارد.'
                : 'تأیید پرداخت ناموفق بود.';
            $this->transactionFailed();
            $this->newLog($code, $message);
            throw new DornapalException($message, $code);
        }

        $this->trackingCode = $payment['trackingCode'] ?? $this->trackingCode;
        $this->transactionSucceed();
        $this->newLog($code, Enum::TRANSACTION_SUCCEED_TEXT);
    }

    private function post(string $path, array $payload, ?string $idempotencyKey = null)
    {
        $pending = Http::acceptJson()->asJson()->timeout(30);

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $pending = $pending->withHeaders([
                'Idempotency-Key' => $idempotencyKey,
            ]);
        }

        $response = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $pending->post(self::SERVER_URL . $path, $payload);
            } catch (ConnectionException $e) {
                if ($attempt === 3) {
                    throw new DornapalException('ارتباط با درناپال برقرار نشد.', 0);
                }
                usleep(500000);
                continue;
            }

            if (!in_array($response->status(), [429, 500, 503], true) || $attempt === 3) {
                break;
            }

            $retryAfter = (int) $response->header('Retry-After');
            if ($retryAfter > 2) {
                break;
            }

            usleep(($retryAfter > 0 ? $retryAfter : 1) * 1000000);
        }

        $body = $response->json();

        if (!is_array($body)) {
            throw new DornapalException('پاسخ درناپال نامعتبر است.', $response->status());
        }

        if ($response->status() !== 200) {
            $this->throwFromBody($body, $response->status());
        }

        return $body;
    }

    private function throwFromBody(array $body, $httpStatus)
    {
        $code = $body['code'] ?? ($body['data']['code'] ?? $httpStatus);
        $detail = $body['detail'] ?? ($body['data']['message'] ?? ($body['title'] ?? ''));
        $traceId = $body['metadata']['traceId'] ?? null;

        if ($traceId) {
            $detail = trim($detail . ' traceId: ' . $traceId);
        }

        throw new DornapalException($detail, $code);
    }

    private function merchantKey()
    {
        $merchantKey = (string) $this->config->get('gateway.dornapal.merchant-key');

        if ($merchantKey === '') {
            throw new DornapalException('کلید مرچنت درناپال تنظیم نشده است.', -200);
        }

        return $merchantKey;
    }

    private function currency()
    {
        $currency = strtoupper((string) $this->config->get('gateway.dornapal.currency', 'IRR'));

        if (!in_array($currency, ['IRR', 'IRT'], true)) {
            throw new DornapalException(DornapalException::$errors['-103'], -103);
        }

        return $currency;
    }

    private function requestAmount()
    {
        if ($this->currency() === 'IRT') {
            return intdiv((int) $this->amount, 10);
        }

        return (int) $this->amount;
    }

    private function assertRequestAmount()
    {
        $this->currency();

        if ((int) $this->amount % 10 !== 0) {
            throw new DornapalException('مبلغ باید مضرب ۱۰ ریال باشد.', -102);
        }
    }
}
