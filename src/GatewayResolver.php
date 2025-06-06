<?php

namespace Larabookir\Gateway;

use Larabookir\Gateway\Digipay\Digipay;
use Larabookir\Gateway\Parsian\Parsian;
use Larabookir\Gateway\Paypal\Paypal;
use Larabookir\Gateway\Sadad\Sadad;
use Larabookir\Gateway\Mellat\Mellat;
use Larabookir\Gateway\Payline\Payline;
use Larabookir\Gateway\Pasargad\Pasargad;
use Larabookir\Gateway\Saman\Saman;
use Larabookir\Gateway\Stripe\Stripe;
use Larabookir\Gateway\Zarinpal\Zarinpal;
use Larabookir\Gateway\JahanPay\JahanPay;
use Larabookir\Gateway\Pay\Pay;
use Larabookir\Gateway\Saderat\Saderat;
use Larabookir\Gateway\Saderatnew\Saderatnew;
use Larabookir\Gateway\Idpay\Idpay;
use Larabookir\Gateway\Alfacoins\Alfacoins;
use Larabookir\Gateway\Payping\Payping;
use Larabookir\Gateway\Plisio\Plisio;
use Larabookir\Gateway\Exceptions\RetryException;
use Larabookir\Gateway\Exceptions\PortNotFoundException;
use Larabookir\Gateway\Exceptions\InvalidRequestException;
use Larabookir\Gateway\Exceptions\NotFoundTransactionException;
use Illuminate\Support\Facades\DB;
use Larabookir\Gateway\Bazarpay\Bazarpay;
use Larabookir\Gateway\Thawani\Thawani;

class GatewayResolver
{

	protected $request;

	/**
	 * @var Config
	 */
	public $config;

	/**
	 * Keep current port driver
	 *
	 * @var Mellat|Saman|Sadad|Zarinpal|Payline|JahanPay|Parsian|Pay|Saderat|Saderatnew|Idpay|Alfacoins|Payping|Plisio|Bazarpay|Thawani|Digipay|Paypal|Stripe
	 */
	protected $port;

	/**
	 * Gateway constructor.
	 * @param null $config
	 * @param null $port
	 */
	public function __construct($config = null, $port = null)
	{
		$this->config = app('config');
		$this->request = app('request');

		if ($this->config->has('gateway.timezone'))
			date_default_timezone_set($this->config->get('gateway.timezone'));

		if (!is_null($port)) $this->make($port);
	}

	/**
	 * Get supported ports
	 *
	 * @return array
	 */
	public function getSupportedPorts()
	{
		return [Enum::MELLAT, Enum::SADAD, Enum::ZARINPAL, Enum::PAYLINE, Enum::JAHANPAY, Enum::PARSIAN, Enum::PASARGAD, Enum::SAMAN, Enum::PAY, Enum::SADERAT, Enum::SADERATNEW, Enum::IDPAY, Enum::ALFACOINS, Enum::PAYPING, Enum::PLISIO, Enum::BAZARPAY, Enum::THAWANI,
        Enum::DIGIPAY, Enum::STRIPE, Enum::PAYPAL];
	}

	/**
	 * Call methods of current driver
	 *
	 * @return mixed
	 */
	public function __call($name, $arguments)
	{

		// calling by this way ( Gateway::mellat()->.. , Gateway::parsian()->.. )
		if(in_array(strtoupper($name),$this->getSupportedPorts())){
			return $this->make($name);
		}

		return call_user_func_array([$this->port, $name], $arguments);
	}

	/**
	 * Gets query builder from you transactions table
	 * @return mixed
	 */
	function getTable()
	{
		return DB::table($this->config->get('gateway.table'));
	}

	/**
	 * Callback
	 *
	 * @return $this->port
	 *
	 * @throws InvalidRequestException
	 * @throws NotFoundTransactionException
	 * @throws PortNotFoundException
	 * @throws RetryException
	 */
	public function verify()
	{
		if (!$this->request->has('transaction_id') && !$this->request->has('iN') && !$this->request->has('invoiceid') && !$this->request->has('order_number')&& !$this->request->has('session_id'))
			throw new InvalidRequestException;
		if ($this->request->has('transaction_id')) {
			$id = $this->request->get('transaction_id');
		} elseif ($this->request->has('invoiceid')) {
			$id = $this->request->get('invoiceid');
		} elseif ($this->request->has('order_number')) {
			$id = $this->request->get('order_number');
		} elseif ($this->request->has('session_id')) {
            $id = $this->request->get('session_id');
        } else {
			$id = $this->request->get('iN');
		}

		$transaction = $this->getTable()->whereId($id)->first();

		if (!$transaction)
			throw new NotFoundTransactionException;

		if (in_array($transaction->status, [Enum::TRANSACTION_SUCCEED, Enum::TRANSACTION_FAILED]))
		 	throw new RetryException;

		$this->make($transaction->port);

		return $this->port->verify($transaction);
	}


	/**
	 * Create new object from port class
	 *
	 * @param int $port
	 * @throws PortNotFoundException
	 */
	function make($port)
	{
		if ($port InstanceOf Mellat) {
			$name = Enum::MELLAT;
		} elseif ($port InstanceOf Parsian) {
			$name = Enum::PARSIAN;
		} elseif ($port InstanceOf Saman) {
			$name = Enum::SAMAN;
		} elseif ($port InstanceOf Payline) {
			$name = Enum::PAYLINE;
		} elseif ($port InstanceOf Zarinpal) {
			$name = Enum::ZARINPAL;
		} elseif ($port InstanceOf JahanPay) {
			$name = Enum::JAHANPAY;
		} elseif ($port InstanceOf Sadad) {
			$name = Enum::SADAD;
		} elseif ($port InstanceOf Pay) {
			$name = Enum::PAY;
		} elseif ($port InstanceOf Saderat) {
			$name = Enum::SADERAT;
		} elseif ($port InstanceOf Saderatnew) {
			$name = Enum::SADERATNEW;
		} elseif ($port InstanceOf Idpay) {
			$name = Enum::IDPAY;
		} elseif ($port InstanceOf Alfacoins) {
			$name = Enum::ALFACOINS;
		} elseif ($port InstanceOf Payping) {
			$name = Enum::PAYPING;
		} elseif ($port InstanceOf Plisio) {
            $name = Enum::PLISIO;
        } elseif ($port InstanceOf Bazarpay) {
            $name = Enum::BAZARPAY;
        } elseif ($port InstanceOf Thawani) {
            $name = Enum::THAWANI;
        } elseif ($port InstanceOf Stripe) {
            $name = Enum::STRIPE;
        }elseif ($port InstanceOf Paypal) {
            $name = Enum::PAYPAL;
        }elseif ($port InstanceOf Digipay) {
            $name = Enum::DIGIPAY;
        }elseif(in_array(strtoupper($port),$this->getSupportedPorts())){
			$port=ucfirst(strtolower($port));
			$name=strtoupper($port);
			$class=__NAMESPACE__.'\\'.$port.'\\'.$port;
			$port=new $class;
		} else
			throw new PortNotFoundException;

		$this->port = $port;
		$this->port->setConfig($this->config); // injects config
		$this->port->setPortName($name); // injects config
		$this->port->boot();

		return $this;
	}
}
