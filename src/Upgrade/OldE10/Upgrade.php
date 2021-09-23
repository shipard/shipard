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
		$this->upgradeTaxCodes('e10doc.core.rows');
		$this->upgradeTaxCodes('e10doc.core.taxes');
		$this->upgradeTaxCodes('e10doc.taxes.reportsRowsVatReturn');
		$this->upgradeTaxCodes('e10doc.taxes.reportsRowsVatRS');
	}

	protected function upgradeTaxRegs()
	{
		$table = $this->app()->table('e10doc.base.taxRegs');
		if (!$table)
			return;

		$this->db()->query('UPDATE [e10doc_base_taxRegs] SET [taxArea] = %s', 'eu', ' WHERE [taxArea] = %s', '');
		$this->db()->query('UPDATE [e10doc_base_taxRegs] SET [taxCountry] = %s', 'cz', ' WHERE [taxCountry] = %s', '');
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

	public function run()
	{
		$this->upgradeWorld();
		$this->upgradeTaxRegs();
		$this->upgradeDocs();
	}
}
