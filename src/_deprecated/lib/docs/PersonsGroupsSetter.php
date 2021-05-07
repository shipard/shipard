<?php

namespace lib\docs;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';

use \E10\utils, \E10\uiutils, \E10\Utility;


/**
 * Class PersonsGroupsSetter
 * @package lib\docs
 */
class PersonsGroupsSetter extends Utility
{
	var $debsGroups = [];
	var $brandsGroups = [];
	var $itemTypesGroups = [];

	var $docTypes;
	var $dir;

	public function setDirection ($dir)
	{
		$this->dir = $dir;
		if ($dir === 'sale')
			$this->docTypes = ['invno', 'cashreg'];
		else
		if ($dir === 'buy')
			$this->docTypes = ['invni', 'purchase'];
	}

	public function loadDebsGroups ()
	{
		$q[] = 'SELECT * FROM [e10doc_debs_groups] WHERE docState != 9800';

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$ql[] = 'SELECT * FROM [e10_base_doclinks] as docLinks ';
			array_push ($ql, 'WHERE srcTableId = %s', 'e10doc.debs.groups', 'AND dstTableId = %s', 'e10.persons.groups');
			array_push ($ql, ' AND docLinks.linkId = %s', 'e10-persons-groups-debsgroups-'.$this->dir, 'AND srcRecId = %i', $r['ndx']);

			$linksRows = $this->app->db()->query ($ql);
			foreach ($linksRows as $lr)
			{
				$this->debsGroups[$lr['srcRecId']][] = $lr['dstRecId'];
			}

			unset ($ql);
		}
	}

	public function doDebsGroups ()
	{
		$this->loadDebsGroups();

		foreach ($this->debsGroups as $debsGroupNdx => $personsGroups)
		{
			foreach ($personsGroups as $personGroupNdx)
			{
				$q[] = 'SELECT DISTINCT heads.person as person FROM e10doc_core_heads as heads WHERE 1 ';
				array_push($q, ' AND heads.docState = 4000');
				array_push($q, ' AND heads.docType IN %in', $this->docTypes);
				array_push($q, ' AND heads.person != 0 AND heads.person IS NOT NULL');

				array_push($q, ' AND EXISTS (SELECT [rows].ndx FROM e10doc_core_rows as [rows], e10_witems_items as items WHERE heads.ndx = [rows].document AND [rows].item = items.ndx');
				array_push($q, ' AND items.debsGroup = %i)', $debsGroupNdx);

				array_push($q, ' AND NOT EXISTS (SELECT ndx FROM e10_persons_personsgroups as pg WHERE heads.person = pg.person');
				array_push($q, ' AND pg.[group] = %i)', $personGroupNdx);

				$rows = $this->app->db()->query($q);
				foreach ($rows as $r)
				{
					$newItem = ['person' => $r['person'], 'group' => $personGroupNdx];
					$this->app->db()->query('INSERT INTO [e10_persons_personsgroups]', $newItem);
				}

				unset ($q);
			}
		}
	}

	public function loadBrandsGroups ()
	{
		$q[] = 'SELECT * FROM [e10_witems_brands] WHERE docState != 9800';

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$ql[] = 'SELECT * FROM [e10_base_doclinks] as docLinks ';
			array_push ($ql, 'WHERE srcTableId = %s', 'e10.witems.brands', 'AND dstTableId = %s', 'e10.persons.groups');
			array_push ($ql, ' AND docLinks.linkId = %s', 'e10-persons-groups-brands-'.$this->dir, 'AND srcRecId = %i', $r['ndx']);

			$linksRows = $this->app->db()->query ($ql);
			foreach ($linksRows as $lr)
			{
				$this->brandsGroups[$lr['srcRecId']][] = $lr['dstRecId'];
			}

			unset ($ql);
		}
	}

	public function doBrandsGroups ()
	{
		$this->loadBrandsGroups();

		foreach ($this->brandsGroups as $brandGroupNdx => $personsGroups)
		{
			foreach ($personsGroups as $personGroupNdx)
			{
				$q[] = 'SELECT DISTINCT heads.person as person FROM e10doc_core_heads as heads WHERE 1 ';
				array_push($q, ' AND heads.docState = 4000');
				array_push($q, ' AND heads.docType IN %in', $this->docTypes);
				array_push($q, ' AND heads.person != 0 AND heads.person IS NOT NULL');

				array_push($q, ' AND EXISTS (SELECT [rows].ndx FROM e10doc_core_rows as [rows], e10_witems_items as items WHERE heads.ndx = [rows].document AND [rows].item = items.ndx');
				array_push($q, ' AND items.brand = %i)', $brandGroupNdx);

				array_push($q, ' AND NOT EXISTS (SELECT ndx FROM e10_persons_personsgroups as pg WHERE heads.person = pg.person');
				array_push($q, ' AND pg.[group] = %i)', $personGroupNdx);

				$rows = $this->app->db()->query($q);
				foreach ($rows as $r)
				{
					$newItem = ['person' => $r['person'], 'group' => $personGroupNdx];
					$this->app->db()->query('INSERT INTO [e10_persons_personsgroups]', $newItem);
				}

				unset ($q);
			}
		}
	}

	public function loadItemTypesGroups ()
	{
		$q[] = 'SELECT * FROM [e10_witems_itemtypes] WHERE docState != 9800';

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$ql[] = 'SELECT * FROM [e10_base_doclinks] as docLinks ';
			array_push ($ql, 'WHERE srcTableId = %s', 'e10.witems.itemtypes', 'AND dstTableId = %s', 'e10.persons.groups');
			array_push ($ql, ' AND docLinks.linkId = %s', 'e10-persons-groups-itemtypes-'.$this->dir, 'AND srcRecId = %i', $r['ndx']);

			$linksRows = $this->app->db()->query ($ql);
			foreach ($linksRows as $lr)
			{
				$this->itemTypesGroups[$lr['srcRecId']][] = $lr['dstRecId'];
			}

			unset ($ql);
		}
	}

	public function doItemTypesGroups ()
	{
		$this->loadItemTypesGroups();

		foreach ($this->itemTypesGroups as $itemTypeGroupNdx => $personsGroups)
		{
			foreach ($personsGroups as $personGroupNdx)
			{
				$q[] = 'SELECT DISTINCT heads.person as person FROM e10doc_core_heads as heads WHERE 1 ';
				array_push($q, ' AND heads.docState = 4000');
				array_push($q, ' AND heads.docType IN %in', $this->docTypes);
				array_push($q, ' AND heads.person != 0 AND heads.person IS NOT NULL');

				array_push($q, ' AND EXISTS (SELECT [rows].ndx FROM e10doc_core_rows as [rows], e10_witems_items as items WHERE heads.ndx = [rows].document AND [rows].item = items.ndx');
				array_push($q, ' AND items.itemType = %i)', $itemTypeGroupNdx);

				array_push($q, ' AND NOT EXISTS (SELECT ndx FROM e10_persons_personsgroups as pg WHERE heads.person = pg.person');
				array_push($q, ' AND pg.[group] = %i)', $personGroupNdx);

				$rows = $this->app->db()->query($q);
				foreach ($rows as $r)
				{
					$newItem = ['person' => $r['person'], 'group' => $personGroupNdx];
					$this->app->db()->query('INSERT INTO [e10_persons_personsgroups]', $newItem);
				}

				unset ($q);
			}
		}
	}


	public function run ()
	{
		$this->doDebsGroups();
		$this->doBrandsGroups();
		$this->doItemTypesGroups();
	}

	static function runAll ($app)
	{
		$sale = new PersonsGroupsSetter ($app);
		$sale->setDirection('sale');
		$sale->run ();

		$buy = new PersonsGroupsSetter ($app);
		$buy->setDirection('buy');
		$buy->run ();
	}
}


/**
 * @param $app
 * @param null $params
 */
function setGroups ($app, $params = NULL)
{
	PersonsGroupsSetter::runAll ($app);
}




