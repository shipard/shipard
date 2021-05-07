<?php

namespace mac\access;
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewGrid, e10\TableViewDetail, \e10\utils, \e10\str;



/**
 * Class TableLevelsCfg
 * @package mac\access
 */
class TableLevelsCfg extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.access.levelsCfg', 'mac_access_levelsCfg', 'Úrovně přístupu');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['note']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		$recData['enabledTimeFromMin'] = 0;
		$recData['enabledTimeToMin'] = 1439;

		if ($recData['enabledTimeFrom'] !== '')
			$recData['enabledTimeFromMin'] = utils::timeToMinutes($recData['enabledTimeFrom']);
		if ($recData['enabledTimeTo'] !== '')
			$recData['enabledTimeToMin'] = utils::timeToMinutes($recData['enabledTimeTo']);
	}
}


/**
 * Class ViewLevelsCfg
 * @package mac\access
 */
class ViewLevelsCfg extends TableViewGrid
{
	var $accessLevelNdx = 0;
	var $tagTypes;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$g = [
			'gate' => 'Brány a dveře',
			'accessTypes' => 'Povol. přístupy',
			'days' => 'Čas',
			'note' => 'Pozn.',
		];
		$this->setGrid ($g);

		$this->accessLevelNdx = intval($this->queryParam('accessLevel'));
		$this->addAddParam ('accessLevel', $this->accessLevelNdx);

		$this->tagTypes = $this->app()->cfgItem('mac.access.tagTypes');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['gate'] = ($item['gateName']) ? $item['gateName'] : '*';

		$days = [];
		for ($d = 1; $d <= 7; $d++)
		{
			if ($item['enableDOW'.$d])
				$days[] = ['text' => utils::$dayShortcuts[$d - 1], 'class' => 'label label-default'];
		}

		if ($item['enabledTimeFrom'] !=='' || $item['enabledTimeTo'] !== '')
			$days[] = ['text' => $item['enabledTimeFrom'].' - '.$item['enabledTimeTo'], 'icon' => 'icon-clock-o', 'class' => 'label label-default'];
		$listItem ['days'] = $days;

		$accessTypes = [];
		for ($d = 1; $d <= 3; $d++)
		{
			if ($item['enableTagType'.$d])
				$accessTypes[] = ['text' => $this->tagTypes[$d]['sc'], 'icon' => $this->tagTypes[$d]['icon'], 'class' => 'label label-default'];
		}
		$listItem ['accessTypes'] = $accessTypes;

		$listItem ['note'] = $item['note'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [cfgs].*, ';

		array_push ($q, ' [gates].fullName AS gateName');
		array_push ($q, ' FROM [mac_access_levelsCfg] AS [cfgs]');
		array_push ($q, ' LEFT JOIN [mac_iot_things] AS [gates] ON [cfgs].[gate] = [gates].ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND cfgs.[accessLevel] = %i', $this->accessLevelNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [note] LIKE %s', '%'.$fts.'%');
			//array_push ($q, ' OR [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY [cfgs].[rowOrder], [cfgs].[ndx]');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailLevelCfg
 * @package mac\access
 */
class ViewDetailLevelCfg extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'cfg #'.$this->item['ndx']]]);
	}
}

/**
 * Class FormLevelCfg
 * @package mac\access
 */
class FormLevelCfg extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault viewerFormList');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_PARENT_FORM);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-cogs'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('gate');
					$this->addColumnInput ('note');

					$this->addSeparator(self::coH2);
					$this->addStatic('Povolené druhy přístupu', self::coH2);
					$this->addColumnInput ('enableTagType1');
					$this->addColumnInput ('enableTagType2');
					$this->addColumnInput ('enableTagType3');

					$this->addSeparator(self::coH2);
					$this->addStatic('Povolené dny a čas', self::coH2);
					$this->openRow();
						$this->addColumnInput ('enableDOW1');
						$this->addColumnInput ('enableDOW2');
						$this->addColumnInput ('enableDOW3');
						$this->addColumnInput ('enableDOW4');
						$this->addColumnInput ('enableDOW5');
						$this->addColumnInput ('enableDOW6');
						$this->addColumnInput ('enableDOW7');
					$this->closeRow();
					$this->addColumnInput ('enabledTimeFrom');
					$this->addColumnInput ('enabledTimeTo');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
