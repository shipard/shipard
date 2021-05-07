<?php

namespace e10doc\contracts\core;
use e10\utils;


/**
 * Class ModuleServices
 * @package e10doc\contracts\sale
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade()
	{
		//$s [] = ['end' => '2019-12-31', 'sql' => ""];
		//$this->doSqlScripts($s);

		if (!$this->checkDbCounters())
		{
			$this->checkDbCounters_UpdateContracts();
		}
	}

	function checkDbCounters()
	{
		$cnt = $this->app->db()->query('SELECT COUNT(*) AS cnt FROM [e10doc_contracts_core_docNumbers]')->fetch();
		if (!$cnt || $cnt['cnt'])
			return FALSE;

		$item = [
			'fullName' => 'Smlouvy prodejní',
			'shortName' => 'Smlouvy',
			'tabName' => '',
			'docKeyId' => '1',
			'docState' => 4000, 'docStateMain' => 2,
		];

		$this->app->db()->query('INSERT INTO [e10doc_contracts_core_docNumbers]', $item);

		return TRUE;
	}

	function checkDbCounters_UpdateContracts()
	{
		$cnt = $this->app->db()->query('SELECT COUNT(*) AS cnt FROM [e10doc_contracts_heads] WHERE [dbCounter] = %i', 0)->fetch();
		if (!$cnt || !$cnt['cnt'])
			return;

		/** @var \e10doc\contracts\core\TableHeads $tableContractsHeads */
		$tableContractsHeads = $this->app->table('e10doc.contracts.core.heads');

		$q[] = 'SELECT * FROM [e10doc_contracts_heads] ';
		array_push ($q, ' WHERE [dbCounter] = %i', 0);
		array_push ($q, ' ORDER BY ndx');

		$rows = $this->app->db()->query($q);
		foreach ($rows as $r)
		{
			$recData = $r->toArray();
			$recData['dbCounter'] = 1;
			if ($recData['docState'] !== 1000)
			{
				$tableContractsHeads->makeDocNumber($recData);
			}

			$this->app->db()->query('UPDATE [e10doc_contracts_heads] SET ', $recData, ' WHERE [ndx] = %i', $recData['ndx']);
		}
	}

	public function contractsSaleInvoiceGenerator ($forceSave = 0)
	{
		$h = new \e10doc\contracts\core\libs\ContractsSaleInvoiceGenerator ($this->app);

		$debug = intval($this->app->arg('debug'));
		$h->debug = $debug;

		$today = $this->app->arg('today');
		if ($today !== FALSE)
		{
			$t = utils::createDateTime($today);
			if ($t !== NULL)
				$h->today = $t;
		}

		if ($forceSave)
			$h->save = 1;
		else
		{
			$save = intval($this->app->arg('save'));
			$h->save = $save;
		}

		$h->run ();

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'contracts-sale-invoice-generator': return $this->contractsSaleInvoiceGenerator();
		}

		parent::onCliAction($actionId);
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'morning': $this->contractsSaleInvoiceGenerator(1); break;
		}
		return TRUE;
	}
}
