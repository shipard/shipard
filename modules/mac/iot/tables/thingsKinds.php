<?php

namespace mac\iot;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class TableThingsKinds
 * @package mac\iot
 */
class TableThingsKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.thingsKinds', 'mac_iot_thingsKinds', 'Druhy věcí');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if (isset($recData['icon']) && $recData['icon'] !== '')
			return $recData['icon'];

		$thingType = $this->app()->cfgItem ('mac.iot.things.types.'.$recData['thingType'], NULL);

		if ($thingType)
			return $thingType['icon'];

		return parent::tableIcon ($recData, $options);
	}

	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [mac_iot_thingsKinds] WHERE [docState] != 9800 ORDER BY [fullName], [ndx]');

		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r ['ndx'], 'id' => $r ['id'],
				'fullName' => $r ['fullName'], 'shortName' => $r ['shortName'],
				'icon' => $r['icon'],
				'thingType' => $r['thingType']
			];

			$list [$r['ndx']] = $item;
		}

		// -- save to file
		$cfg ['mac']['iot']['things']['kinds'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_mac.iot.things.kinds.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewThingsKinds
 * @package mac\iot
 */
class ViewThingsKinds extends TableView
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

		if (isset($this->thingsTypes[$item['thingType']]))
			$listItem ['t2'][] = ['text' => $this->thingsTypes[$item['thingType']]['shortName'], 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT thingsKinds.*';
		array_push ($q, ' FROM [mac_iot_thingsKinds] AS thingsKinds');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' thingsKinds.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR thingsKinds.[shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'thingsKinds.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormThingKind
 * @package mac\iot
 */
class FormThingKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formHeader'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('thingType');
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('id');
					$this->addColumnInput ('topicType');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailThingKind
 * @package mac\iot
 */
class ViewDetailThingKind extends TableViewDetail
{
}

