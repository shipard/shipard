<?php

namespace E10\Base;

use \E10\utils, \E10\TableView, \E10\DbTable;

/**
 * Class TableProperties
 * @package E10\Base
 */
class TableProperties extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.base.properties', 'e10_base_properties', 'Hodnoty vlastností');
	}
}


/**
 * Class ViewPropertiesCombo
 * @package E10\Base
 */
class ViewPropertiesCombo extends TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = 0;
		$listItem ['t1'] = $item['valueString'];
		$listItem ['data-cc']['valueString'] = $item['valueString'];

		$listItem ['icon'] = 'x-properties';

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT DISTINCT valueString from [e10_base_properties] WHERE 1';


		if ($this->queryParam('tableid'))
			array_push ($q, ' AND [tableid] = %s', $this->queryParam('tableid'));
		if ($this->queryParam('property'))
			array_push ($q, ' AND [property] = %s', $this->queryParam('property'));

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([valueString] LIKE %s)', '%'.$fts.'%');


		array_push ($q, ' ORDER BY [valueString], [ndx] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}
