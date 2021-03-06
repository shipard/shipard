<?php

namespace mac\iot;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewGrid, e10\TableViewDetail, \e10\utils, \e10\str;


/**
 * Class TableScenariosSchedule
 * @package mac\access
 */
class TableScenariosSchedule extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.scenariosSchedule', 'mac_iot_scenariosSchedule', 'Časový rozvrh scénářů');
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

		$recData['timeFromMin'] = 0;
		$recData['timeToMin'] = 1439;

		if ($recData['timeFrom'] !== '')
			$recData['timeFromMin'] = utils::timeToMinutes($recData['timeFrom']);
		if ($recData['timeTo'] !== '')
			$recData['timeToMin'] = utils::timeToMinutes($recData['timeTo']);

		/*
		if ($recData['workingDays'] || $recData['nonWorkingDays'])
		{
			for ($d = 1; $d <= 7; $d++)
				$recData['DOW'.$d] = 0;
		}
		*/
	}
}


/**
 * Class ViewScenariosSchedule
 * @package mac\access
 */
class ViewScenariosSchedule extends TableViewGrid
{
	var $scenarioNdx = 0;
	var $workingDays;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$g = [
			'days' => 'Dny a čas',
			'note' => 'Pozn.',
		];
		$this->setGrid ($g);

		$this->scenarioNdx = intval($this->queryParam('scenario'));
		$this->addAddParam ('scenario', $this->scenarioNdx);

		$this->workingDays = $this->app()->cfgItem('mac.base.workingDaysSchedule');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$days = [];
		if ($item['workingDays'])
			$days[] = ['text' => $this->workingDays[$item['workingDays']]['fn'], 'class' => 'label label-default'];
		for ($d = 1; $d <= 7; $d++)
		{
			if ($item['DOW'.$d])
				$days[] = ['text' => utils::$dayShortcuts[$d - 1], 'class' => 'label label-default'];
		}

		if ($item['timeFrom'] !=='' || $item['timeTo'] !== '')
			$days[] = ['text' => $item['timeFrom'].' - '.$item['timeTo'], 'icon' => 'icon-clock-o', 'class' => 'label label-default'];
		$listItem ['days'] = $days;

		$listItem ['note'] = $item['note'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [schedule].* ';

		array_push ($q, ' FROM [mac_iot_scenariosSchedule] AS [schedule]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND schedule.[scenario] = %i', $this->scenarioNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [note] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY [schedule].[rowOrder], [schedule].[ndx]');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailScenarioSchedule
 * @package mac\access
 */
class ViewDetailScenarioSchedule extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'cfg #'.$this->item['ndx']]]);
	}
}


/**
 * Class FormScenarioSchedule
 * @package mac\access
 */
class FormScenarioSchedule extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault viewerFormList');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_PARENT_FORM);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/iconCogs'];

		$dowColState = 0;//($this->recData['workingDays'] || $this->recData['nonWorkingDays']) ? self::coReadOnly : 0;

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addStatic('Dny a čas', self::coH2);
					$this->addColumnInput ('workingDays');
					$this->addColumnInput ('nonWorkingDays');
					$this->openRow();
						$this->addColumnInput ('DOW1', $dowColState);
						$this->addColumnInput ('DOW2', $dowColState);
						$this->addColumnInput ('DOW3', $dowColState);
						$this->addColumnInput ('DOW4', $dowColState);
						$this->addColumnInput ('DOW5', $dowColState);
						$this->addColumnInput ('DOW6', $dowColState);
						$this->addColumnInput ('DOW7', $dowColState);
					$this->closeRow();
					$this->addColumnInput ('timeFrom');
					$this->addColumnInput ('timeTo');

					$this->addSeparator(self::coH2);
					$this->addColumnInput ('note');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
