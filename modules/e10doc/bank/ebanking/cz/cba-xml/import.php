<?php

namespace E10Doc\Bank\Import\cz_cba_xml;

require_once __SHPD_MODULES_DIR__ . 'e10doc/bank/bank.php';

use e10\json, \Shipard\Utils\Xml;


/**
 * Class Import
 * @package E10Doc\Bank\Import\cz_cba_xml
 */
class Import extends \E10Doc\Bank\ebankingImportDoc
{
	public function import ()
	{
		$data = Xml::toArray($this->textData);
		//file_put_contents (__APP_DIR__.'/tmp/'."___BANKA.json", json::lint($data));

		$statements = isset($data['Document']['BkToCstmrStmt']['Stmt'][0]['Id']) ? $data['Document']['BkToCstmrStmt']['Stmt'] : [$data['Document']['BkToCstmrStmt']['Stmt']];
		$headIdx = 0;
		forEach ($statements as $s)
		{
			if ($headIdx !== 0)
			{
				$this->saveDoc ();
				$this->clear();
			}

			$this->setHeadInfo ('bankAccount', $s['Acct']['Id']['IBAN']);

			$this->setHeadInfo ('datePeriodBegin', $this->parseDate ($s['FrToDt']['FrDtTm']));
			$this->setHeadInfo ('datePeriodEnd', $this->parseDate ($s['FrToDt']['ToDtTm']));
			$this->setHeadInfo ('docOrderNumber', intval($s['LglSeqNb']));

			$this->setHeadInfo ('initBalance', $this->parseNumber ($s['Bal'][0]['Amt']));
			$this->setHeadInfo ('balance', $this->parseNumber ($s['Bal'][1]['Amt']));

			$ntry = (isset($s['Ntry']['NtryRef'])) ? [$s['Ntry']] : $s['Ntry'];
			forEach ($ntry as $row)
			{
				if (isset($row['ValDt']['Dt']))
					$this->setRowInfo ('dateDue', $this->parseDate ($row['ValDt']['Dt']));
				elseif (isset($row['ValDt']))
					$this->setRowInfo ('dateDue', $this->parseDate ($row['ValDt']));

				$bankAccount = '';
				if (isset($row['NtryDtls']['TxDtls']['RltdPties']['DbtrAcct']['Id']['Othr']['Id']))
					$bankAccount .= $row['NtryDtls']['TxDtls']['RltdPties']['DbtrAcct']['Id']['Othr']['Id'];
				if (isset($row['NtryDtls']['TxDtls']['RltdAgts']['DbtrAgt']['FinInstnId']['Othr']['Id']))
				{
					$bankAccount .= '/';
					$bankAccount .= $row['NtryDtls']['TxDtls']['RltdAgts']['DbtrAgt']['FinInstnId']['Othr']['Id'];
				}
				if ($bankAccount === '/')
					$bankAccount = '';
				$this->setRowInfo ('bankAccount', $bankAccount);

				$money = $this->parseNumber ($row['Amt']);
				if ($row['CdtDbtInd'] === 'DBIT')
					$money = - $money;
				$this->setRowInfo ('money', $money);

				if (isset($row['NtryDtls']['TxDtls']['Refs']['EndToEndId']))
				{
					$s1 = $row['NtryDtls']['TxDtls']['Refs']['EndToEndId'];
					if (substr($s1, 0, 2) === 'VS')
						$s1 = substr($s1, 2);
					$this->setRowInfo('symbol1', $s1);
				}

				if (isset($row['NtryDtls']['TxDtls']['Refs']['PmtInfId']))
				{
					$s2 = $row['NtryDtls']['TxDtls']['Refs']['PmtInfId'];
					if (substr($s2, 0, 2) === 'SS')
						$s2 = substr($s2, 2);
					if ($s2 === '0')
						$s2 = '';
					$this->setRowInfo('symbol2', $s2);
				}

				if (isset($row['NtryDtls']['TxDtls']['Refs']['PmtInfId']))
				{
					$s3 = $row['NtryDtls']['TxDtls']['Refs']['PmtInfId'];
					if (substr($s3, 0, 2) === 'KS')
						$s3 = substr($s3, 2);
					if ($s3 === '0000')
						$s3 = '';
					$this->setRowInfo('symbol3', $s3);
				}

				if (isset($row['NtryDtls']['TxDtls']['RmtInf']['Strd']))
				{
					foreach ($row['NtryDtls']['TxDtls']['RmtInf']['Strd'] as $strd)
					{
						$refParts = NULL;
						if (isset ($strd['CdtrRefInf']['Ref']))
							$refParts = explode(':', $strd['CdtrRefInf']['Ref']);
						elseif (isset ($strd['Ref']))
							$refParts = explode(':', $strd['Ref']);

						if (!$refParts || count($refParts) !== 2)
							continue;
						if ($refParts[0] === 'VS')
							$this->setRowInfo('symbol1', $refParts[1]);
						elseif ($refParts[0] === 'SS' && $refParts[1] !== '0000000000')
							$this->setRowInfo('symbol2', $refParts[1]);
						elseif ($refParts[0] === 'KS' && $refParts[1] !== '0000')
							$this->setRowInfo('symbol3', $refParts[1]);
					}
				}

				if (isset($row['NtryDtls']['TxDtls']['RmtInf']['Ustrd']))
					$this->setRowInfo ('memo', $row['NtryDtls']['TxDtls']['RmtInf']['Ustrd']);
				if (isset($row['NtryDtls']['TxDtls']['AddtlTxInf']))
					$this->setRowInfo ('memo', $row['NtryDtls']['TxDtls']['AddtlTxInf']);

				$this->appendRow();
			}
			$headIdx++;
		}
	}

	public function parseDate ($value)
	{
		return date_create_from_format ('Y-m-d', substr ($value, 0, 10));
	}

	public function parseNumber ($value)
	{
		return floatval($value);
	}
}



