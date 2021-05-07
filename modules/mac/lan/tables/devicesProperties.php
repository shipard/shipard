<?php

namespace mac\lan;


use \e10\TableView, \e10\TableViewDetail, \e10\DbTable, \E10\utils;


/**
 * Class TableDevicesProperties
 * @package mac\lan
 */
class TableDevicesProperties extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.devicesProperties', 'mac_lan_devicesProperties', 'Vlastnosti zařízení');
	}
}


/**
 * Class ViewUnknownPackages
 * @package mac\lan
 */
class ViewUnknownPackages extends TableView
{
	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['s1'];
		$listItem ['i1'] = '#'.$item ['ndx'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = "SELECT props.s1 AS s1, ";
		array_push ($q, ' (SELECT sp.ndx FROM [mac_lan_devicesProperties] AS sp WHERE sp.s1 = props.s1 LIMIT 1) AS ndx');
		array_push ($q, ' FROM [mac_lan_devicesProperties] AS props');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [property] = %i', 3);
		array_push ($q, ' AND [deleted] = %i', 0);
		array_push ($q, ' AND [i1] = %i', 0);

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([s1] LIKE %s)", '%'.$fts.'%');

		array_push ($q, ' GROUP BY [s1]');
		array_push ($q, ' ORDER BY [s1] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * Class ViewDetailUnknownPackage
 * @package mac\lan
 */
class ViewDetailUnknownPackage extends TableViewDetail
{
	public function createHeaderCode ()
	{
		$hdr ['icon'] = 'icon-question-circle';
		$hdr ['info'] = [];

		$hdr ['info'][] = ['class' => 'title', 'value' => $this->item['s1']];

		return $this->defaultHedearCode ($hdr);
	}

	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.lan.DocumentCardUnknownSwPackage');
	}

	public function createToolbar ()
	{
		return [];
	}
}
