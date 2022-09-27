<?php

namespace Shipard\Upgrade\OldE10;
use \Shipard\Base\Utility;
use \Shipard\Utils\World;

class Upgrade extends Utility
{
	protected function upgradeWorld()
	{
		$this->upgradeWorldCountries();
	}

	protected function upgradeWorldCountries()
	{
		$this->upgradeWorldCountries_Table('e10.persons.address', 'country', 'worldCountry');
	}

	protected function upgradeWorldCountries_Table(string $tableId, string $oldColumnId, string $newColumnId)
	{
		$table = $this->app()->table($tableId);
		if (!$table)
			return;

		$q = [];
		array_push ($q, 'SELECT DISTINCT ', $oldColumnId);
		array_push ($q, ' FROM ['.$table->sqlName().']');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND ['.$oldColumnId.'] != %s', '');
		array_push ($q, ' AND (['.$newColumnId.'] = %i', 0, ' OR ['.$newColumnId.'] IS NULL)');

		$rows = $this->db()->query($q);

		if (!$rows || !count($rows))
			return;

		foreach ($rows as $r)
		{
			$oldCountryId = $r[$oldColumnId];
			$newCountryNdx = ($oldCountryId === '') ? 0 : World::countryNdx($this->app(), $oldCountryId);
			$update = [$newColumnId => $newCountryNdx];

			$this->db()->query('UPDATE ['.$table->sqlName().'] SET ', $update, ' WHERE ['.$oldColumnId.'] = %s', $oldCountryId);
			echo " * ".\Dibi::$sql."\n";
		}
	}

	protected function upgradeDocs()
	{
		$table = $this->app()->table('e10doc.base.taxRegs');
		if (!$table)
			return;

		$this->upgradeTaxCodes('e10doc.core.rows');
		$this->upgradeTaxCodes('e10doc.core.taxes');
		$this->upgradeTaxCodes('e10doc.taxes.reportsRowsVatReturn');
		$this->upgradeTaxCodes('e10doc.taxes.reportsRowsVatRS');

		$this->db()->query('UPDATE [e10doc_core_heads] SET [taxCountry] = %s', 'cz', ' WHERE [taxCountry] = %s', '', ' AND [vatReg] != %i', 0);
	}

	protected function upgradeTaxRegs()
	{
		$table = $this->app()->table('e10doc.base.taxRegs');
		if (!$table)
			return;

		$this->db()->query('UPDATE [e10doc_base_taxRegs] SET [taxArea] = %s', 'eu', ' WHERE [taxArea] = %s', '');
		$this->db()->query('UPDATE [e10doc_base_taxRegs] SET [taxCountry] = %s', 'cz', ' WHERE [taxCountry] = %s', '');

		// -- accDocument / e10doc_taxes_reports
		$dbCounter = $this->db()->query ('SELECT * FROM [e10doc_base_docnumbers] WHERE [docType] = %s AND [activitiesGroup] = %s', 'cmnbkp', 'tax')->fetch();
		if (!$dbCounter || !isset ($dbCounter['ndx']))
			return;

		$q = [];
		array_push ($q, 'SELECT * FROM [e10doc_taxes_reports]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [accDocument] = %i', 0);
		array_push ($q, ' AND [docState] = %i', 4000);
		array_push ($q, ' AND [reportType] = %s', 'eu-vat-tr');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$accDoc = $this->db()->query ('SELECT * FROM [e10doc_core_heads] WHERE [docType] = %s', 'cmnbkp',
				' AND [dbCounter] = %i AND [taxPeriod] = %i', $dbCounter['ndx'], $r['taxPeriod'])->fetch();

			if (!$accDoc)
				continue;

			// -- taxReport
			$this->db()->query ('UPDATE [e10doc_taxes_reports] SET [accDocument] = %i', $accDoc['ndx'], ' WHERE [ndx] = %i', $r['ndx']);

			$qf = [];
			$qf[] = 'SELECT * FROM [e10doc_taxes_filings]';
			array_push($qf, ' WHERE [report] = %i', $r['ndx'], ' AND [docState] = %i', 4000);
			array_push($qf, ' ORDER BY dateIssue DESC, ndx DESC');
			$lastFiling = $this->db()->query($qf)->fetch();
			if ($lastFiling && isset($lastFiling['ndx']))
			{
				$linkId = 'accTaxReport;'.$r['ndx'].';'.$lastFiling['ndx'];
				$this->db()->query ('UPDATE [e10doc_core_heads] SET [linkId] = %s', $linkId, ' WHERE [ndx] = %i', $accDoc['ndx']);
			}
		}
	}

	protected function upgradeTaxCodes($tableId, $columnId = 'taxCode')
	{
		$table = $this->app()->table($tableId);
		if (!$table)
			return;

		echo "### ".$table->sqlName()."\n";

		$q = [];
		array_push ($q, 'SELECT DISTINCT ['.$columnId.']');
		array_push ($q, ' FROM ['.$table->sqlName().']');
		array_push ($q, ' WHERE 1');

		$rows = $this->db()->query($q);

		if (!$rows || !count($rows))
			return;

		foreach ($rows as $r)
		{
			if (strlen($r[$columnId]) === 7)
				continue;

			if (strlen($r[$columnId]) === 5)
			{
				$newTaxCode = 'EU'.trim($r[$columnId]);
			}
			else {
				$newTaxCode = 'EUCZ'.trim($r[$columnId]);
				if ($newTaxCode === 'EUCZ0' || $newTaxCode == '' || $newTaxCode == 'EUCZ')
					$newTaxCode = 'EUCZ000';
			}

			echo "* ".json_encode($r[$columnId]) .' -> '.$newTaxCode;

			$update = [$columnId => $newTaxCode];
			$this->db()->query('UPDATE ['.$table->sqlName().'] SET ', $update, ' WHERE ['.$columnId.'] = %s', $r[$columnId]);
			echo " - ".\Dibi::$sql;

			echo "\n";
		}
	}

	public function upgradeOldBBoard()
	{
		echo "bboard import 2...\n";

		$q = [];
		array_push ($q, 'SELECT msgs.*');
		array_push ($q, ' FROM [e10pro_wkf_messages] AS [msgs]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND msgs.[msgType] = %i', 3);
		array_push ($q, ' AND msgs.[subject] != %s', '');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			echo $r['ndx'].' ['.$r['docState'].'] '.$r['subject'].' '.json_encode($r['dateCreate']).' --> ';

			$item = [
				'ndx' => $r['ndx'], 'bboard' => 1,
				'title' => $r['subject'],'text' => $r['text'],
				'author' => $r['author'],

				'publishFrom' => $r['dateCreate'],

				'docState' => $r['docState'], 'docStateMain' => $r['docStateMain']
			];

			if ($r['docState'] === 4000)
				$item['docStateMain'] = 2;

			$exist = $this->db()->query('SELECT * FROM [wkf_bboard_msgs] WHERE [ndx] = %i', $r['ndx'])->fetch();
			if (!$exist)
			{
				echo 'INSERT ';
				$this->db()->query('INSERT INTO [wkf_bboard_msgs] ', $item);
			}
			else
			{
				echo 'UPDATE ';
				$this->db()->query('UPDATE [wkf_bboard_msgs] SET ', $item, ' WHERE [ndx] = %i', $r['ndx']);
			}

			$this->db()->query('UPDATE [e10_base_doclinks] SET [srcTableId] = %s', 'wkf.bboard.msgs',
													', linkId = %s', 'wkf-bboard-msgs-notify',
													' WHERE [srcTableId] = %s', 'e10pro.wkf.messages', ' AND [linkId] = %s', 'e10pro-wkf-message-notify',
													' AND [srcRecId] = %i', $r['ndx']
			);

			echo "\n";
		}
	}

	public function run()
	{
		$this->upgradeWorld();
		$this->upgradeTaxRegs();
		$this->upgradeDocs();
	}
}
