<?php

namespace e10\persons\libs;
use \Shipard\Base\Utility;


class SetPersonRelations extends Utility
{
	var $categoryNdx = 0;
	var $category = NULL;
	var $categoryType = NULL;

	/** @var \e10\DbTable */
	var $tablePersonsRelations;

	public function setCategory ($categoryNdx)
	{
		$this->tablePersonsRelations = $this->app()->table('e10.persons.relations');

		$this->categoryNdx = $categoryNdx;
		$this->category = $this->app()->cfgItem ('e10.persons.categories.categories.'.$categoryNdx, NULL);

		if ($this->category && isset($this->category['type']))
			$this->categoryType = $this->app()->cfgItem ('e10.persons.categories.types.'.$this->category['type'], NULL);
	}

	public function run ()
	{
		if ($this->categoryType)
		{
			if (isset($this->categoryType['personLastUse']))
			{
				$this->setFromLastUse ();
			}
		}
	}

	protected function setFromLastUse ()
	{
		$q[] = 'SELECT ndx, person, firstUseDate, lastUseDate FROM [e10_persons_personsLastUse]';
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND (');
		foreach ($this->categoryType['personLastUse'] as $lu)
		{
			array_push ($q, ' ([lastUseType] = %s', $lu['type']);
			array_push ($q, ' AND [lastUseRole] = %i', $lu['role']);
			array_push ($q, ')');
		}
		array_push ($q, ')');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$exist = $this->db()->query('SELECT * FROM [e10_persons_relations] WHERE [person] = %i', $r['person'],
				' AND [category] = %i', $this->categoryNdx)->fetch();
			if ($exist)
			{
				if ($r['firstUseDate'] !== $exist['validFrom'] || $r['lastUseDate'] !== $exist['validTo'])
				{
					$item = ['ndx' => $exist['ndx'], 'validFrom' => $r['firstUseDate'], 'validTo' => $r['lastUseDate']];
					$this->tablePersonsRelations->dbUpdateRec($item);
				}
			}
			else
			{
				$item = ['person' => $r['person'], 'category' => $this->categoryNdx, 'source' => 1, 'docState' => 4000, 'docStateMain' => 2];
				$newNdx = $this->tablePersonsRelations->dbInsertRec($item);
				//$this->tablePersonsRelations->docsLog($newNdx);
			}
		}
	}
}
