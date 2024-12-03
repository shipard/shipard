<?php

namespace e10pro\emps;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class TableWorkingHours
 */
class TableWorkingHours extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.emps.workingHours', 'e10pro_emps_workingHours', 'Evidence pracovní doby');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['note']];

		return $hdr;
	}
}


/**
 * class ViewWorkingHours
 */
class ViewWorkingHours extends TableView
{
	var $workingHoursKinds;

	public function init ()
	{
		//$this->objectSubType = TableView::vsDetail;

		$this->workingHoursKinds = $this->app()->cfgItem('e10pro.emps.workingHoursKinds', NULL);

		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['personName'];
    $listItem ['t2'] = Utils::dateFromTo($item['validFrom'], $item['validTo'], NULL);

		$ak = $this->absencesKinds[$item['absenceKind']] ?? NULL;
		if ($ak)
			$listItem ['i2'] = $ak['sn'];

    if ($item['note'] !== '')
      $listItem ['t3'] = $item['note'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];

    array_push ($q, 'SELECT [wh].*, ');
    array_push ($q, ' [persons].fullName AS personName');
    array_push ($q, ' FROM [e10pro_emps_workingHours] AS [wh]');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [wh].[person] = [persons].ndx');
    array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
    {
      array_push ($q, 'AND (');
			array_push ($q, '[persons].[fullName] LIKE %s ', '%'.$fts.'%');
      array_push ($q, ')');
    }

		$this->queryMain ($q, '[wh].', ['[persons.fullName]', 'validFrom', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormWorkingHours
 */
class FormWorkingHours extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Řádky', 'icon' => 'system/formRows'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('person');
					$this->addColumnInput ('workingHoursKind');
					$this->addSeparator(self::coH3);
					$this->addColumnInput ('wlWeeklyTotal');
					$this->addColumnInput ('wlWeeklyRation');
					$this->addColumnInput ('wlWeekly1');
					$this->addSeparator(self::coH3);
					$this->addColumnInput ('validFrom');
					$this->addColumnInput ('validTo');
					$this->addSeparator(self::coH3);
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addList ('rows');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailWorkingHours
 */
class ViewDetailWorkingHours extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.emps.libs.dc.DCWorkingHours');
	}
}
