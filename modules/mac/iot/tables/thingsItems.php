<?php

namespace mac\iot;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class TableThingsItems
 * @package mac\iot
 */
class TableThingsItems extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.thingsItems', 'mac_iot_thingsItems', 'Položky věcí');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		/*
		if (isset($recData['icon']) && $recData['icon'] !== '')
			return $recData['icon'];

		$thingType = $this->app()->cfgItem ('mac.iot.things.types.'.$recData['thingType'], NULL);

		if ($thingType)
			return $thingType['icon'];
		*/
		return parent::tableIcon ($recData, $options);
	}
}


/**
 * Class ViewThingsItems
 * @package mac\iot
 */
class ViewThingsItems extends TableView
{
}

/**
 * Class ViewThingsItemsFormList
 * @package mac\iot
 */
class ViewThingsItemsFormList extends \e10\TableViewGrid
{
	/** @var \mac\iot\TableThings */
	var $tableThings;
	var $thingNdx = 0;
	var $thingRecData = NULL;

	var $itemsTypes;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$this->thingNdx = intval($this->queryParam('thing'));
		$this->addAddParam('thing', $this->thingNdx);

		$this->tableThings = $this->app()->table('mac.iot.things');
		$this->thingRecData = $this->tableThings->loadItem($this->thingNdx);

		$this->itemsTypes = $this->app()->cfgItem('mac.iot.things.itemsTypes');

		$g = [
			'type' => 'Typ',
			'item' => 'Položka',
			'note' => 'Pozn.',
		];
		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = 'icon-cogs';

		$listItem ['type'] = [];

		$itemType = $this->itemsTypes[$item['itemType']];

		$listItem ['type'][] = ['text' => $itemType['fullName'], 'class' => 'break e10-bold'];

		if ($itemType['type'] === 'ioPort')
		{
			$itemInfo = [];

			if ($item['ioPortFullName'])
				$itemInfo[] = ['text' => $item['ioPortFullName'], 'class' => 'block'];
			else
				$itemInfo[] = ['text' => $item['ioPortId'], 'class' => 'block'];

			$itemInfo[] = ['text' => $item['ioPortDeviceFullName'], 'class' => 'label label-default'];

			$listItem ['item'] = $itemInfo;
		}

		$listItem ['note'] = '';

		return $listItem;
	}

	function decorateRow (&$item)
	{
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT items.*, ';
		array_push ($q, ' ioPorts.fullName AS ioPortFullName, ioPorts.portId AS ioPortId,');
		array_push ($q, ' ioPortsDevices.fullName AS ioPortDeviceFullName');
		array_push ($q, ' FROM [mac_iot_thingsItems] AS [items]');
		array_push ($q, ' LEFT JOIN [mac_iot_things] AS things ON items.thing = things.ndx');

		array_push ($q, ' LEFT JOIN [mac_lan_devicesIOPorts] AS ioPorts ON items.ioPort = ioPorts.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS ioPortsDevices ON ioPorts.device = ioPortsDevices.ndx');


		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND items.[thing] = %i', $this->thingNdx);

		// -- fulltext
		/*
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' ports.[fullName] LIKE %s', '%'.$fts.'%',
				' OR devices.[fullName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}
		*/
		array_push ($q, ' ORDER BY items.[rowOrder] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;
	}
}


/**
 * Class ViewThingsItemsFormListDetail
 * @package mac\iot
 */
class ViewThingsItemsFormListDetail extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'port #'.$this->item['ndx']]]);
	}
}


/**
 * Class FormThingItem
 * @package mac\iot
 */
class FormThingItem extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault viewerFormList');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_PARENT_FORM);
		//$this->setFlag ('maximize', 1);

		$thingItemTypeCfg = $this->app()->cfgItem('mac.iot.things.itemsTypes.'.$this->recData['itemType'], NULL);

		$tabs ['tabs'][] = ['text' => 'Data', 'icon' => 'icon-database'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('itemType');
					if ($thingItemTypeCfg && $thingItemTypeCfg['type'] === 'ioPort')
						$this->addColumnInput ('ioPort');
					if ($thingItemTypeCfg && $thingItemTypeCfg['type'] === 'camera')
						$this->addColumnInput ('camera');
				$this->closeTab ();

			$this->closeTabs ();
		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'mac.iot.thingsItems' && $srcColumnId === 'ioPort')
		{
			$cp = [
				'thingItemType' => $recData['itemType']
			];

			return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}

}


/**
 * Class ViewDetailThingItem
 * @package mac\iot
 */
class ViewDetailThingItem extends TableViewDetail
{
}

