<?php

namespace E10Doc\Bank\Import\cz_cspor_xml {

require_once __SHPD_MODULES_DIR__ . 'e10doc/bank/bank.php';

use \E10\Application, E10\Wizard, E10\utils;


class Import extends \E10Doc\Bank\ebankingImportDoc
{
	var $xml;

	public function import ()
	{
		$this->xml = new \SimpleXMLElement($this->textData);

		$data = $this->xml->xpath ('/exportStatements/exportStatement');
		$headIdx = 0;

		forEach ($data as $head)
		{
		//	$head = $data[0];

			if ($headIdx !== 0)
			{
				$this->saveDoc ();
				$this->clear();
			}

			$this->setHeadInfo ('bankAccount', $head->accountNumber.'/'.sprintf('%04d', $head->bankCode));

			$this->setHeadInfo ('datePeriodBegin', $this->parseDate ((string)$head->statGenDate));
			$this->setHeadInfo ('datePeriodEnd', $this->parseDate ((string)$head->statGenDate));
			$this->setHeadInfo ('docOrderNumber', intval($head->statOrderNum));

			$this->setHeadInfo ('initBalance', $this->parseNumber ((string)$head->begBalance));
			$this->setHeadInfo ('balance', $this->parseNumber ((string)$head->finBalance));

			forEach ($head->exportDetails->exportDetail as $row)
			{
				$this->setRowInfo ('dateDue', $this->parseDate ((string)$row->turnAccDate));

				$bankAccount = '';
				if ((string)$row->bfAccountNumber !== '0')
				{
					if ((string)$row->bfAccountPrefix !== '0')
						$bankAccount .= (string)$row->bfAccountPrefix . '-';
					$bankAccount .= (string)$row->bfAccountNumber . '/' . sprintf('%04d', (string)$row->bfBankID);
				}

				$this->setRowInfo ('bankAccount', $bankAccount);
				$this->setRowInfo ('money', $this->parseNumber ((string)$row->amount));

				$s1 = (string)$row->bfVariableSymbol;
				if ($s1 === '0')
					$s1 = '';
				$this->setRowInfo ('symbol1', $s1);

				$s2 = (string)$row->bfSpecSymbol;
				if ($s2 === '0')
					$s2 = '';
				$this->setRowInfo ('symbol2', $s2);

				$s3 = (string)$row->bfConstSymb;
				if ($s3 === '0')
					$s3 = '';
				$this->setRowInfo ('symbol3', $s3);

				$this->setRowInfo ('memo', $row->bfName);
				$this->setRowInfo ('memo', $row->itemtext);

				$this->appendRow();
			}
			$headIdx++;
		}
	} // function import

	public function parseDate ($value)
	{
		$ltz = date_default_timezone_get();
		$localTimezone = new \DateTimeZone($ltz);
		$d = new \DateTime($value);
		$d->setTimezone($localTimezone);

		return $d;
	}

	public function parseNumber ($value)
	{
		return floatval($value);
	}
} // class Import


} // namespace E10Doc\Bank\Import\cz_gpc

