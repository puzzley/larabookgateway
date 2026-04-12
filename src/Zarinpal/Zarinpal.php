<?php

namespace Larabookir\Gateway\Zarinpal;

use Illuminate\Support\Facades\Request;
use Larabookir\Gateway\Enum;
use Larabookir\Gateway\PortAbstract;
use Larabookir\Gateway\PortInterface;

class Zarinpal extends PortAbstract implements PortInterface
{
    /**
     * ZarinPal REST API v4 endpoints
     */
    const API_REQUEST     = 'https://api.zarinpal.com/pg/v4/payment/request.json';
    const API_VERIFY      = 'https://api.zarinpal.com/pg/v4/payment/verify.json';
    const API_START_PAY   = 'https://www.zarinpal.com/pg/StartPay/';

    /**
     * Sandbox endpoints
     */
    const SANDBOX_REQUEST   = 'https://sandbox.zarinpal.com/pg/v4/payment/request.json';
    const SANDBOX_VERIFY    = 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json';
    const SANDBOX_START_PAY = 'https://sandbox.zarinpal.com/pg/StartPay/';

    /**
     * ZarinGate start pay URL
     */
    const ZARINGATE_START_PAY = 'https://www.zarinpal.com/pg/StartPay/%s/ZarinGate';

    /**
     * Optional payer mobile number
     *
     * @var string|null
     */
    protected $mobile;

    /**
     * Optional payer email address
     *
     * @var string|null
     */
    protected $email;

    /**
     * Whether to use sandbox environment
     *
     * @var bool
     */
    protected $sandboxMode = false;

    /**
     * Whether to use ZarinGate mode
     *
     * @var bool
     */
    protected $zarinGate = false;

    /**
     * Set optional payer mobile
     *
     * @param string $mobile
     * @return $this
     */
    public function setMobile(string $mobile): self
    {
        $this->mobile = $mobile;
        return $this;
    }

    /**
     * Enable sandbox mode for testing
     *
     * @return $this
     */
    public function enableSandbox(): self
    {
        $this->sandboxMode = true;
        return $this;
    }

    /**
     * Enable ZarinGate redirect mode
     *
     * @return $this
     */
    public function enableZarinGate(): self
    {
        $this->zarinGate = true;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function set($amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    /**
     * Sets callback url
     * @param $url
     */
    function setCallback($url)
    {
        $this->callbackUrl = $url;
        return $this;
    }

    /**
     * Gets callback url
     * @return string
     */
    function getCallback()
    {
        if (!$this->callbackUrl)
            $this->callbackUrl = $this->config->get('gateway.zarinpal.callback-url');

        return $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);
    }

    /**
     * {@inheritdoc}
     */
    public function ready(): self
    {
        $this->sendPayRequest();
        return $this;
    }

    public function getGatewayUrl()
    {
        return $this->sandboxMode
            ? self::SANDBOX_START_PAY . $this->refId
            : ($this->zarinGate
                ? sprintf(self::ZARINGATE_START_PAY, $this->refId)
                : self::API_START_PAY . $this->refId);
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        $gatewayUrl = $this->getGatewayUrl();
        return redirect()->away($gatewayUrl);
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction): self
    {
        parent::verify($transaction);
        $this->userPayment();
        $this->verifyPayment();
        return $this;
    }

    /**
     * Send payment request to ZarinPal REST API v4
     *
     * @throws ZarinpalException
     */
    protected function sendPayRequest(): void
    {
        $this->newTransaction();

        $payload = [
            'merchant_id'  => $this->config->get('gateway.zarinpal.merchant-id'),
            'amount'       => $this->amount,
            'callback_url' => $this->getCallback(),
            'description'  => $this->config->get('gateway.zarinpal.description', 'پرداخت'),
        ];

        if ($this->mobile) {
            $payload['metadata']['mobile'] = $this->mobile;
        }

        if ($this->email) {
            $payload['metadata']['email'] = $this->email;
        }

        $endpoint = $this->sandboxMode ? self::SANDBOX_REQUEST : self::API_REQUEST;
        $response = $this->postJson($endpoint, $payload);

        if (isset($response['errors']) && !empty($response['errors'])) {
            $code    = $response['errors']['code']    ?? -1;
            $message = $response['errors']['message'] ?? 'خطای نامشخص';
            $this->transactionFailed();
            throw new ZarinpalException($code, $message);
        }

        if (!isset($response['data']['authority'])) {
            $this->transactionFailed();
            throw new ZarinpalException(-1, 'پاسخ نامعتبر از درگاه زرین‌پال');
        }

        $this->refId = $response['data']['authority'];
        $this->transactionSetRefId();
    }

    /**
     * Validate the payment status returned by ZarinPal callback
     *
     * @throws ZarinpalException
     */
    protected function userPayment(): void
    {
        $status    = Request::input('Status');

        if (strtoupper($status) !== 'OK') {
            $this->transactionFailed();
            throw new ZarinpalException(-22, 'پرداخت توسط کاربر لغو شد یا ناموفق بود.');
        }
    }

    /**
     * Verify the payment with ZarinPal REST API v4
     *
     * @throws ZarinpalException
     */
    protected function verifyPayment(): void
    {
        $payload = [
            'merchant_id' => $this->config->get('gateway.zarinpal.merchant-id'),
            'amount'      => $this->amount,
            'authority'   => $this->refId,
        ];

        $endpoint = $this->sandboxMode ? self::SANDBOX_VERIFY : self::API_VERIFY;
        $response = $this->postJson($endpoint, $payload);

        if (isset($response['errors']) && !empty($response['errors'])) {
            $code    = $response['errors']['code']    ?? -1;
            $message = $response['errors']['message'] ?? 'خطای نامشخص';
            $this->transactionFailed();
            throw new ZarinpalException($code, $message);
        }

        $statusCode = $response['data']['code'] ?? -1;

        // 100 = success, 101 = already verified
        if (!in_array($statusCode, [100, 101], true)) {
            $this->transactionFailed();
            throw new ZarinpalException($statusCode);
        }

        $refId = $response['data']['ref_id'] ?? null;

        $this->trackingCode = $refId;
        $this->transactionSucceed();
        $this->newLog($statusCode, Enum::TRANSACTION_SUCCEED_TEXT);
    }

    /**
     * POST JSON payload to endpoint using cURL
     *
     * @param string $url
     * @param array  $payload
     * @return array
     * @throws ZarinpalException
     */
    protected function postJson(string $url, array $payload): array
    {
        $jsonData = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData),
            'Accept: application/json',
        ]);

        $raw      = curl_exec($ch);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new ZarinpalException(-1, 'خطا در ارتباط با درگاه زرین‌پال: ' . $curlErr);
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ZarinpalException(-1, 'پاسخ نامعتبر JSON از درگاه زرین‌پال');
        }

        return $decoded;
    }
}
