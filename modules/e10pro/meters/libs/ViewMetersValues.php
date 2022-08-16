<?php

namespace e10pro\meters\libs;
use \Shipard\Viewer\TableViewGrid, \Shipard\Utils\Utils;


/**
 * class ViewMetersValues
 */
class ViewMetersValues extends TableViewGrid
{
  var $units;

	public function init ()
	{
		parent::init();

    $this->units = $this->app->cfgItem ('e10.witems.units');

		$this->objectSubType = self::vsMain;
		$this->enableDetailSearch = TRUE;
		$this->gridEditable = TRUE;
		$this->linesWidth = 65;

		$g = [
      'tsRead' => 'Datum měření',
			'meterName' => 'Měřič',
		];

		$g['value'] = ' Hodnota';
		$g['unit'] = 'Jed.';

		$this->setGrid ($g);


		$this->createBottomTabs();

		$this->setMainQueries ();
	}

	public function createBottomTabs()
	{
		$rows = $this->db()->query ('SELECT * FROM [e10pro_meters_groups] WHERE [docState] != 9800 ORDER BY [order], [fullName]');
		$bt = [];
		$active = 1;
		foreach ($rows as $r)
		{
			$bt [] = ['id' => $r['ndx'], 'title' => $r['shortName'], 'active' => $active];
			$active = 0;
		}
		$bt[] = ['id' => '0', 'title' => 'Vše', 'active' => 0];

		if (count($bt))
			$this->setBottomTabs($bt);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['meterName'] = $item['meterFullName'];
    $listItem ['value'] = $item['value'];
    $listItem ['unit'] = $this->units[$item['meterUnit']]['shortcut'];
    $listItem ['tsRead'] = Utils::datef($item['datetime'], '%d, %T');

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$bottomTabId = intval($this->bottomTabId());

		$q = [];
    array_push($q, 'SELECT vals.*,');
    array_push($q, ' [meters].[fullName] AS meterFullName, [meters].[shortName] AS meterShortName, [meters].[unit] AS meterUnit');
    array_push($q, ' FROM [e10pro_meters_values] AS [vals]');
    array_push($q, ' LEFT JOIN [e10pro_meters_meters] AS [meters] ON [vals].[meter] = [meters].[ndx]');
		array_push($q, ' WHERE 1');

		if ($bottomTabId)
			array_push($q, ' AND [meters].[metersGroup] = %i', $bottomTabId);

		// -- fulltext
		if ($fts != '')
    {
		  array_push ($q, ' AND (');
      array_push ($q, ' [meters].[fullName] LIKE %s', '%'.$fts.'%');
      array_push ($q, ' OR [meters].[shortName] LIKE %s', '%'.$fts.'%');
      array_push ($q, ')');
    }

		$this->queryMain ($q, 'vals.', ['vals.[datetime] DESC, [meter]', '[vals].[ndx]']);
		$this->runQuery ($q);
	}
}
