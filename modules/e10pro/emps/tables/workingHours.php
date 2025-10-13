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

		$recNdx = intval($recData['ndx'] ?? 0);
		if (!$recNdx)
			return;

		$workingHoursKind = $this->app()->cfgItem('e10pro.emps.workingHoursKinds.'.$recData['workingHoursKind'], NULL);
		if (!$workingHoursKind)
			return;

		if ($workingHoursKind['usePedagogicalActivity'] ?? 0)
		{
			if ($recData['wlWeeklyPedagogicalActivity'])
				$recData['wlWeeklyRatio'] = round($recData['wlWeekly1'] / $recData['wlWeeklyPedagogicalActivity'], 4);
			else
				$recData['wlWeeklyRatio'] = 0.0;

			$recData['wlWeekly'] = round($recData['wlWeeklyTotal'] * $recData['wlWeeklyRatio'], 2);
			$recData['wlWeekly2'] = round($recData['wlWeekly'] - $recData['wlWeekly1'], 2);
		}
		else
		{
			if ($workingHoursKind['useTwoHours'] ?? 0)
			{
			}
			if ($recData['wlWeekly'])
				$recData['wlWeeklyRatio'] = round($recData['wlWeekly'] / $recData['wlWeeklyTotal'], 4);
			else
				$recData['wlWeeklyRatio'] = 0.0;
		}

		$whInfo = new \e10pro\emps\libs\WorkingHoursInfo($this->app());
		$whInfo->setWorkingHours($recNdx);
		$whInfo->loadData();

		$recData['sumWeeklyHoursTotal'] = $whInfo->dataWeeklySum['hoursTotal'];
		$recData['sumWeeklyHours1'] = $whInfo->dataWeeklySum['hours1'];
		$recData['sumWeeklyHours2'] = $whInfo->dataWeeklySum['hours2'];
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

		$whk = $this->workingHoursKinds[$item['workingHoursKind']] ?? NULL;
		if ($whk)
			$listItem ['i2'] = $whk['sn'];

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
	var $workingHoursKind;

	public function renderForm ()
	{
		$this->workingHoursKind = $this->app()->cfgItem('e10pro.emps.workingHoursKinds.'.$this->recData['workingHoursKind'], NULL);

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
					if ($this->workingHoursKind['useTwoHours'] ?? 0)
					{
						$this->addColumnInput ('wlWeeklyTotal');
						if ($this->workingHoursKind['usePedagogicalActivity'] ?? 0)
							$this->addColumnInput ('wlWeeklyPedagogicalActivity');
						$this->addColumnInput ('wlWeekly1');
						$this->addSeparator(self::coH4);
						$this->addColumnInput ('wlWeekly2', self::coReadOnly);
						$this->addColumnInput ('wlWeekly', self::coReadOnly);
						$this->addSeparator(self::coH4);
						$this->addColumnInput ('wlWeeklyRatio', self::coReadOnly);
					}
					else
					{
						$this->addColumnInput ('wlWeeklyTotal');
						$this->addColumnInput ('wlWeekly');
						$this->addColumnInput ('wlWeeklyRatio', self::coReadOnly);
					}
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

	function columnLabel ($colDef, $options)
	{
		$this->workingHoursKind = $this->app()->cfgItem('e10pro.emps.workingHoursKinds.'.$this->recData['workingHoursKind'], NULL);

		switch ($colDef ['sql'])
		{
			case  'wlWeekly1': return ($this->workingHoursKind['wlWeekly1ColTitle'] !== '' ? $this->workingHoursKind['wlWeekly1ColTitle'] : $colDef['name']);
			case  'wlWeekly2': return ($this->workingHoursKind['wlWeekly2ColTitle'] !== '' ? $this->workingHoursKind['wlWeekly2ColTitle'] : $colDef['name']);
		}

		return parent::columnLabel ($colDef, $options);
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
