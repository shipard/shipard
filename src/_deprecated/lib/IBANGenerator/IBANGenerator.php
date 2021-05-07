<?php

namespace lib;

/**
 * Class IBANGenerator
 * @package lib
 */
class IBANGenerator
{
	protected $country;
	public $bban;
	public $iban = '';

	function __construct($localBankAccountNumber, $country)
	{
		$this->country = strtoupper($country);

		if ($this->country === 'CZ')
		{
			$parts1 = explode ('/', $localBankAccountNumber);
			if (count($parts1) !== 2)
				return;

			$bankId = $parts1[1];
			$parts2 = explode ('-', $parts1[0]);

			$accPrefix = '';

			if (count($parts2) === 2)
			{
				$accPrefix = $parts2[0];
				$accNumber = $parts2[1];
			}
			else
				$accNumber = $parts2[0];

			$this->bban = $bankId.sprintf('%06s', $accPrefix).sprintf('%010s', $accNumber);
		}

		$ibanBeforeCheck = $this->bban.$this->country.'00';
		$ibanNumCodes = $this->getNumericCode($ibanBeforeCheck);
		$checkSum = $this->getCheckCipher($ibanNumCodes);
		$this->iban = $this->country.$checkSum.$this->bban;
	}

	public function getCheckCipher($string)
	{
		return str_pad(98 - bcmod($string, 97), 2, "0", STR_PAD_LEFT);
	}

	public function getNumericCode ($src)
	{
		static $codes = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$res = '';
		foreach(str_split($src) as $char)
			$res .= strpos ($codes, $char);
		return $res;
	}
}
