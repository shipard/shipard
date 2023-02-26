<?php

namespace mac\iot;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class TableSetups
 */
class TableSetups extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.setups', 'mac_iot_setups', 'IoT sestavy');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

		//if (isset($recData['uid']) && $recData['uid'] === '')
		//	$recData['uid'] = utils::createToken(20);
	}

	public function checkAfterSave2 (&$recData)
	{
		if ($recData['docState'] == 9800)
		{ // trash
			//$this->db()->query('DELETE FROM [mac_iot_thingsCfg] WHERE [thing] = %i', $recData['ndx']);
		}
		/*
		if ($recData['docStateMain'] > 1)
		{
			$tcu = new \mac\iot\libs\ThingCfgUpdater($this->app());
			$tcu->init();
			$tcu->updateOne($recData);
		}
		*/
	}

	public function tableIcon ($recData, $options = NULL)
	{
		//if (isset($recData['icon']) && $recData['icon'] !== '')
		//	return $recData['icon'];

		/*
		$thingKind = $this->app()->cfgItem ('mac.iot.things.kinds.'.$recData['thingKind'], NULL);
		if ($thingKind && $thingKind['icon'] !== '')
			return $thingKind['icon'];

		if ($thingKind && $thingKind['thingType'])
		{
			$thingType = $this->app()->cfgItem('mac.iot.things.types.' . $thingKind['thingType'], NULL);
			if ($thingType)
				return $thingType['icon'];
		}
		*/

		return parent::tableIcon ($recData, $options);
	}
}


/**
 * Class ViewSetups
 */
class ViewSetups extends TableView
{
	var $setupsTypes;

	public function init ()
	{
		parent::init();
		$this->setupsTypes = $this->app->cfgItem('mac.iot.setups.types');

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$t2 = [];
		$st = $this->setupsTypes[$item['setupType']] ?? NULL;
		if ($st)
			$t2[] = ['text' => $st['fn'], 'class' => 'label label-default'];

		if ($item['placeName'])
			$t2[] = ['text' => $item['placeName'], 'class' => 'label label-default', 'icon' => 'tables/e10.base.places'];

		$listItem ['t2'] = $t2;

		$listItem ['i2'] = ['text' => $item['id'], 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [iotSetups].*,';
		array_push ($q, ' places.fullName AS placeName');
		array_push ($q, ' FROM [mac_iot_setups] AS [iotSetups]');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS places ON iotSetups.place = places.ndx');

		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [iotSetups].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [iotSetups].[shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[iotSetups].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormSetup
 */
class FormSetup extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Události', 'icon' => 'formEvents'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('setupType');
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('id');
					$this->addColumnInput ('place');
				$this->closeTab ();

				$this->openTab(TableForm::ltNone);
					$this->addViewerWidget ('mac.iot.eventsOn', 'form', ['dstTableId' => 'mac.iot.setups', 'dstRecId' => $this->recData['ndx']],TRUE);
				$this->closeTab();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailSetup
 */
class ViewDetailSetup extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.iot.libs.dc.DCSetup');
	}
}

/**
 * Class ViewDetailSetupCfg
 */
class ViewDetailSetupCfg extends TableViewDetail
{
	public function createDetailContent ()
	{
		//$this->addDocumentCard('mac.iot.dc.ThingCfg');
	}
}

