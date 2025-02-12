<?php

namespace e10doc\waster\libs;
use \Shipard\Viewer\TableView;

/**
 * class WDViewWasteCodes
 */
class WDViewWasteCodes extends TableView
{
	public function init ()
	{
		//$this->setMainQueries ();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['wasteCodeNomenc'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['wasteCodeText'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT DISTINCT [rr].wasteCodeNomenc, [rr].wasteCodeText');
    array_push ($q, 'FROM [e10pro_reports_waste_cz_returnRows] AS [rr]');
    array_push ($q, ' WHERE 1');

		// -- fulltext
    /*
		if ($fts != '')
    {
			array_push ($q, ' AND (');
      array_push ($q, '[name] LIKE %s)', '%'.$fts.'%');
      array_push ($q, ')');
    }
    */

    array_push($q, $this->sqlLimit());

		$this->runQuery ($q);


    /*
        array_push($q, 'SELECT [wo].*,');
    array_push($q, ' [niSrc].[shortName] AS [wasteCodeNameSrc], [niDst].[shortName] AS [wasteCodeNameDst]');
    array_push($q, ' FROM [e10doc_waster_wasteOps] AS [wo]');
    array_push($q, ' LEFT JOIN [e10_base_nomencItems] AS [niSrc] ON [wo].[wasteCodeNomencSrc] = [niSrc].[ndx]');
    array_push($q, ' LEFT JOIN [e10_base_nomencItems] AS [niDst] ON [wo].[wasteCodeNomencDst] = [niDst].[ndx]');
*/
	}

}

