<?php

namespace e10\persons\libs\viewers;
use \Shipard\Viewer\TableView;

/**
 * class ViewAddressNomencCity
 */
class ViewAddressNomencCity extends TableView
{
  var $nomecTypeNdx = 0;
	var $level = 2;

	public function init ()
	{
		parent::init();

    $test = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-orp')->fetch();
    $this->nomecTypeNdx = $test['ndx'];

		if ($this->queryParam('level'))
			$this->level = intval($this->queryParam('level'));

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['shortName'];
		$listItem ['i1'] = ['text' => $item['itemId'], 'class' => 'id'];

    $listItem ['t2'] = $item['parentCityName'];
		$listItem ['i2'] = $item['parentCityItemId'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push($q, ' SELECT allCities.*, parentCities.shortName AS parentCityName, parentCities.itemId AS parentCityItemId');
    array_push($q, ' FROM [e10_base_nomencItems] AS allCities');
    array_push($q, ' LEFT JOIN [e10_base_nomencItems] AS parentCities ON allCities.ownerItem = parentCities.ndx');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND allCities.nomencType = %i', $this->nomecTypeNdx);
    array_push($q, ' AND allCities.level = %i', $this->level);

		$this->qryDefault ($q);

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (',
					' allCities.[shortName] LIKE %s', '%'.$fts.'%',
					' OR allCities.[itemId] LIKE %s', '%'.$fts.'%',
					' OR parentCities.[itemId] LIKE %s', '%'.$fts.'%',
					')'
			);
		}
		$this->queryMain ($q, 'allCities.', ['allCities.[shortName]', 'allCities.[ndx]']);
		$this->runQuery ($q);
	}

	function qryDefault (&$q) {}

	function queryNomencTypeValue ()
	{
		$nomencType = intval($this->queryParam ('nomencType'));
		if (!$nomencType)
		{
			$crd = $this->queryParam('comboRecData');
			if (isset($crd['nomencType']))
				$nomencType = $crd['nomencType'];
		}
		return $nomencType;
	}
}
