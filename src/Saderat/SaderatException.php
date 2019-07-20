<?php

namespace Larabookir\Gateway\Saderat;

use Larabookir\Gateway\Exceptions\BankException;

class SaderatException extends BankException
{
	public static $errors = array(
		1 => 'وجود خطا در فرمت اطلاعات ارسالی',
		2 => 'عدم وجود پزیرنده و ترمینال مورد درخواست در سیستم',
		3 => 'رد درخواست به علت دریافت درخواست توسط ای پی نامعتبر',
		4 => 'پزیرنده موردنظر امکان استفاده از درگاه را ندارد',
		5 => 'برخورد با مشکل در انجام درخواست تراکنش مورد نظر',
		6 => 'خطا در پردازش درخواست',
		7 => 'امضای دیجیتالی نامعتبر است',
		8 => 'شماره خرید CRN تکراری است',
		9 => 'سیستم در حال حاضر قادر به پاسخگویی نمی باشد',
		200 => 'کاربر از انجام تراکنش منصرف شده است',
		101 => 'تراکنش مورد نظر قبلا تایید شده است',
		102 => 'تراکنش مورد نظر برگشت خورده است',
		103 => 'تایید انجام نشده است',
		106 => 'پیامی از سوییچ پرداخت دریافت نشد',
		107 => 'تراکنش درخواستی موجود نیست',
		111 => 'مشکل در ارتباط با سوییچ',
		112 => 'مقادیر ارسالی در درخواست معتبر نیستند',
		113 => 'خطای سمت سرور بانک',
		1009 => 'OPEN SSL SIGN ERROR',
	);

	public function __construct($errorId)
	{
		$this->errorId = intval($errorId);

		parent::__construct(@self::$errors[$this->errorId].' #'.$this->errorId, $this->errorId);
	}
}