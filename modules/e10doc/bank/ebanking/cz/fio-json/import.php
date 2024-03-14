<?php

namespace E10Doc\Bank\Import\cz_fio_json;

require_once __SHPD_MODULES_DIR__ . 'e10doc/bank/bank.php';
use \Shipard\Utils\Json;
use \Shipard\Utils\Str;

/**
 * class Import
 */
class Import extends \E10Doc\Bank\ebankingImportDoc
{
	public function importRow ($r)
	{
		$newItem = [];

		$money = $r['column1']['value'];
		$this->setRowInfo ('money', $money);
		//$newItem['bankTransId'] = $r['column22']['value'];

		// -- date
		$dateDue = new \DateTime($r['column0']['value']);
		$this->setRowInfo ('dateDue', $dateDue);

		// -- bank account
		$bankAccount = '';
		if (isset($r['column2']))
			$bankAccount = $r['column2']['value'];
		if (isset($r['column3']))
			$bankAccount .= '/'.$r['column3']['value'];
		$bankAccount = ltrim ($bankAccount, '0'); // strip leading zeros and blank account prefix
		$bankAccount = ltrim ($bankAccount, '-');
		$bankAccount = ltrim ($bankAccount, '0');
		$this->setRowInfo ('bankAccount', $bankAccount);

		// -- symbols
		$symbol1 = isset($r['column5']) ? $r['column5']['value'] : '';
		$symbol1 = ltrim ($symbol1, '0');
		$symbol2 = isset($r['column6']) ? $r['column6']['value'] : '';
		if ($symbol2 === '0')
			$symbol2 = '';
		$symbol3 = isset($r['column4']) ? $r['column4']['value'] : '';
		if ($symbol3 === '0000')
			$symbol3 = '';
		$this->setRowInfo ('symbol1', $symbol1);
		$this->setRowInfo ('symbol2', $symbol2);
		$this->setRowInfo ('symbol3', $symbol3);

		$notes = [];
		if (isset($r['column16']))
			$notes[] = $r['column16']['value'];
		if (isset($r['column7']))
			$notes[] = $r['column7']['value'];
		if (isset($r['column8']))
			$notes[] = $r['column8']['value'];
		if (isset($r['column10']))
			$notes[] = $r['column10']['value'];

		$this->setRowInfo ('memo', Str::upToLen(implode(', ', $notes), 180));

		$this->appendRow();
	}

	public function import ()
	{
		$data = Json::decode($this->textData);
		if (!$data || !isset($data['accountStatement']['info']['accountId']))
			return;

		$this->setHeadInfo ('bankAccount', $data['accountStatement']['info']['accountId']);
		$this->setHeadInfo ('initBalance', $data['accountStatement']['info']['openingBalance']);
		$this->setHeadInfo ('balance', $data['accountStatement']['info']['closingBalance']);
		$this->setHeadInfo ('docOrderNumber', $data['accountStatement']['info']['idList']);

		$datePeriodBegin = new \DateTime($data['accountStatement']['info']['dateStart']);
		$this->setHeadInfo ('datePeriodBegin', $datePeriodBegin);

		$datePeriodEnd = new \DateTime($data['accountStatement']['info']['dateEnd']);
		$this->setHeadInfo ('datePeriodEnd', $datePeriodEnd);

		forEach ($data['accountStatement']['transactionList']['transaction'] as $tr)
		{
			$this->importRow($tr);
		}
	}
}
