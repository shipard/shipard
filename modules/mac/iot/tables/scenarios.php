<?php

namespace mac\iot;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class TableSCPlacements
 * @package mac\iot
 */
class TableScenarios extends DbTable
{
	CONST
		sacIoTThingAction = 0,
		sacIotBoxIOPort = 4
	;


	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.scenarios', 'mac_iot_scenarios', 'Scénáře');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		//	$hdr ['info'][] = ['class' => 'info', 'value' => '#'.$recData['ndx'].'.'.$recData['uid']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if ($recData['icon'] !== '')
			return $recData['icon'];

		return parent::tableIcon($recData, $options);
	}
}


/**
 * Class ViewScenarios
 * @package mac\iot
 */
class ViewScenarios extends TableView
{
	public function init ()
	{
		parent::init();
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		if ($item['workplace'])
			$listItem ['t2'][] = ['text' => $item['workplaceName'], 'class' => 'label label-default', 'icon' => 'icon-sun-o'];
		if ($item['enableManualRun'])
			$listItem ['t2'][] = ['text' => 'Ručně', 'class' => 'label label-default', 'icon' => 'icon-power-off'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [scenarios].*,';
		array_push ($q, ' workplaces.name AS [workplaceName]');
		array_push ($q, ' FROM [mac_iot_scenarios] AS [scenarios]');
		array_push ($q, ' LEFT JOIN [terminals_base_workplaces] AS [workplaces] ON [scenarios].workplace = [workplaces].ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [scenarios].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [workplaces].[name] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'scenarios.', ['[order], [fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormScenario
 * @package mac\iot
 */
class FormScenario  extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Rozvrh', 'icon' => 'formSchedule'];
		$tabs ['tabs'][] = ['text' => 'Akce', 'icon' => 'formAction'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('enableManualRun');
					$this->addColumnInput ('workplace');
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addListViewer ('schedule', 'default');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addListViewer ('actions', 'default');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailScenario
 * @package mac\iot
 */
class ViewDetailScenario extends TableViewDetail
{
}

