<?php

namespace mac\iot;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class TableThings
 * @package mac\iot
 */
class TableThings extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.things', 'mac_iot_things', 'IoT věci');
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

		if (isset($recData['uid']) && $recData['uid'] === '')
			$recData['uid'] = utils::createToken(20);
	}

	public function checkAfterSave2 (&$recData)
	{
		if ($recData['docState'] == 9800)
		{ // trash
			$this->db()->query('DELETE FROM [mac_iot_thingsCfg] WHERE [thing] = %i', $recData['ndx']);
		}

		if ($recData['docStateMain'] > 1)
		{
			$tcu = new \mac\iot\libs\ThingCfgUpdater($this->app());
			$tcu->init();
			$tcu->updateOne($recData);
		}
	}

	public function tableIcon ($recData, $options = NULL)
	{
		//if (isset($recData['icon']) && $recData['icon'] !== '')
		//	return $recData['icon'];

		$thingKind = $this->app()->cfgItem ('mac.iot.things.kinds.'.$recData['thingKind'], NULL);
		if ($thingKind && $thingKind['icon'] !== '')
			return $thingKind['icon'];

		if ($thingKind && $thingKind['thingType'])
		{
			$thingType = $this->app()->cfgItem('mac.iot.things.types.' . $thingKind['thingType'], NULL);
			if ($thingType)
				return $thingType['icon'];
		}

		return parent::tableIcon ($recData, $options);
	}
}


/**
 * Class ViewThings
 * @package mac\iot
 */
class ViewThings extends TableView
{
	var $thingsTypes;

	public function init ()
	{
		parent::init();
		$this->thingsTypes = $this->app->cfgItem('mac.iot.things.types');

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t2'] = [];

		if ($item['kindName'])
			$listItem ['t2'][] = ['text' => $item['kindName'], 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT things.*,';
		array_push ($q, ' thingsKinds.shortName AS kindName, thingsKinds.thingType');
		array_push ($q, ' FROM [mac_iot_things] AS things');
		array_push ($q, ' LEFT JOIN [mac_iot_thingsKinds] AS thingsKinds ON things.thingKind = thingsKinds.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' things.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR things.[shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'things.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormThing
 * @package mac\iot
 */
class FormThing extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Napojení', 'icon' => 'formConnect'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('thingKind');
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('id');
				$this->closeTab ();

				$this->openTab(TableForm::ltNone);
					$this->addListViewer('items', 'formList');
				$this->closeTab();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailThing
 * @package mac\iot
 */
class ViewDetailThing extends TableViewDetail
{
}

/**
 * Class ViewDetailThingCfg
 * @package mac\iot
 */
class ViewDetailThingCfg extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.iot.dc.ThingCfg');
	}
}

