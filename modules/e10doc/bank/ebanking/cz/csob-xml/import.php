<?php

namespace E10Doc\Bank\Import\cz_csob_xml;

require_once __SHPD_MODULES_DIR__ . 'e10doc/bank/bank.php';

use \Shipard\Utils\Xml;


/**
 * Class Import
 * @package E10Doc\Bank\Import\cz_csob_xml
 */
class Import extends \E10Doc\Bank\ebankingImportDoc
{
	public function import ()
	{
		$data = Xml::toArray($this->textData);

		$s = $data['FINSTA']['FINSTA03'];

		$this->setHeadInfo ('bankAccount', $s['S25_CISLO_UCTU']);

		$this->setHeadInfo ('datePeriodBegin', $this->parseDate ($s['S60_DATUM']));
		$this->setHeadInfo ('datePeriodEnd', $this->parseDate ($s['S62_DATUM']));

		$this->setHeadInfo ('docOrderNumber', intval($s['S28_CISLO_VYPISU']));

		$this->setHeadInfo ('initBalance', $this->parseNumber ($s['S60_CASTKA']));
		$this->setHeadInfo ('balance', $this->parseNumber ($s['S62_CASTKA']));

		forEach ($s['FINSTA05'] as $row)
		{
			$this->setRowInfo ('dateDue', $this->parseDate ($row['DPROCD']));

			$bankAccount = '';
			if (isset($row['PART_ACCNO']) && is_string($row['PART_ACCNO']))
				$bankAccount .= ltrim($row['PART_ACCNO'], ' 0');
			if (isset($row['PART_BANK_ID']) && is_string($row['PART_BANK_ID']))
			{
				$bankAccount .= '/';
				$bankAccount .= $row['PART_BANK_ID'];
			}
			if ($bankAccount === '/')
				$bankAccount = '';
			$this->setRowInfo ('bankAccount', $bankAccount);

			$money = $this->parseNumber ($row['S61_CASTKA']);
			$this->setRowInfo ('money', $money);

			$s1 = '';
			if (isset($row['S86_VARSYMOUR']) && is_string($row['S86_VARSYMOUR']))
				$s1 = $row['S86_VARSYMOUR'];
			$this->setRowInfo ('symbol1', $s1);

			$s2 = '';
			if (isset($row['S86_SPECSYMOUR']) && is_string($row['S86_SPECSYMOUR']))
				$s2 = $row['S86_SPECSYMOUR'];
			$this->setRowInfo ('symbol2', $s2);

			$s3 = '';
			if (isset($row['S86_KONSTSYM']) && is_string($row['S86_KONSTSYM']))
				$s3 = $row['S86_KONSTSYM'];
			$this->setRowInfo ('symbol3', $s3);

			if (isset($row['S61_POST_NAR']) && is_string($row['S61_POST_NAR']))
				$this->setRowInfo ('memo', $row['S61_POST_NAR']);
			if (isset($row['REMARK']) && is_string($row['REMARK']))
				$this->setRowInfo ('memo', $row['REMARK']);
			if (isset($row['PART_ID1_1']) && is_string($row['PART_ID1_1']))
				$this->setRowInfo ('memo', $row['PART_ID1_1']);
			if (isset($row['PART_ID1_2']) && is_string($row['PART_ID1_2']))
				$this->setRowInfo ('memo', $row['PART_ID1_2']);

			$this->appendRow();
		}
	}

	public function parseDate ($value)
	{
		return date_create_from_format ('d.m.Y', substr ($value, 0, 10));
	}

	public function parseNumber ($value)
	{
		$s = str_replace(',', '.', $value);
		return floatval($s);
	}
}
