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
		foreach ($rows as $r)
		{
			$oldCountryId = $r[$oldColumnId];
			$newCountryNdx = ($oldCountryId === '') ? 0 : World::countryNdx($this->app(), $oldCountryId);
			$update = [$newColumnId => $newCountryNdx];
			
			$this->db()->query('UPDATE ['.$table->sqlName().'] SET ', $update, ' WHERE ['.$oldColumnId.'] = %s', $oldCountryId);
			echo " * ".\Dibi::$sql."\n";
		}
	}

	public function run()
	{
		$this->upgradeWorld();
	}
}
