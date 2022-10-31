<?php

namespace e10\persons\libs\viewers;
use \Shipard\Viewer\TableView;
use \e10\base\libs\UtilsBase;

/**
 * class AddressForm
 */
class AddressForm extends TableView
{
	var $dstRecId = 0;
	var $dstTableId = '';
	var $addressTypes;
	var $classification = [];

	public function init ()
	{
		$this->enableDetailSearch = TRUE;
		$this->objectSubType = TableView::vsDetail;

		$this->addressTypes = $this->app()->cfgItem('e10.persons.addressTypes');

		$this->dstTableId = $this->queryParam('dstTableId');
		$this->dstRecId = intval($this->queryParam('dstRecId'));

		$this->addAddParam('tableid', $this->dstTableId);
		$this->addAddParam('recid', $this->dstRecId);

		$this->toolbarTitle = ['text' => 'Adresy', 'class' => 'h2 e10-bold'/*, 'icon' => 'system/iconMapMarker'*/];
		$this->setMainQueries();

		parent::init();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [address].* ';
		array_push ($q, ' FROM [e10_persons_address] AS [address]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [address].[tableid] = %s', $this->dstTableId);
		array_push ($q, ' AND [address].[recid] = %i', $this->dstRecId);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [address].city LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [address].street LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [address].specification LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[address].', ['[type]', '[city]', '[street]', '[ndx]']);
		$this->runQuery ($q);

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);
	}

	public function renderRow ($item)
	{
		$at = $this->addressTypes[$item['type']];

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $at['icon'];//$this->table->tableIcon ($item);

		$listItem ['tt'] = $this->table->addressText($item);

		$listItem ['t2'] = [];

		$listItem ['t2'][] = ['text' => $at['name'], 'class' => 'label label-default'];

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{

			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t2'] = array_merge ($item ['t2'], $clsfGroup);
		}
	}
}
