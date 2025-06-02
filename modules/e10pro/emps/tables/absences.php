<?php

namespace e10pro\emps;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class TableAbsences
 */
class TableAbsences extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.emps.absences', 'e10pro_emps_absences', 'Nepřítomnosti');
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
 * class ViewAbsences
 */
class ViewAbsences extends TableView
{
	var $absencesKinds;

	public function init ()
	{
		//$this->objectSubType = TableView::vsDetail;

		$this->absencesKinds = $this->app()->cfgItem('e10pro.emps.absencesKinds', NULL);

		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['personName'];
    $listItem ['t2'] = Utils::dateFromTo($item['dateBegin'], $item['dateEnd'], NULL);

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

    array_push ($q, 'SELECT [absences].*, ');
    array_push ($q, ' [persons].fullName AS personName');
    array_push ($q, ' FROM [e10pro_emps_absences] AS [absences]');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [absences].[person] = [persons].ndx');
    array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
    {
      array_push ($q, 'AND (');
			array_push ($q, '[persons].[fullName] LIKE %s ', '%'.$fts.'%');
			array_push ($q, ' OR [absences].[note] LIKE %s ', '%'.$fts.'%');
      array_push ($q, ')');
    }

		$this->queryMain ($q, '[absences].', ['[dateBegin]', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormAbsence
 */
class FormAbsence extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('person');
					$this->addColumnInput ('absenceKind');
					$this->addColumnInput ('note');
					$this->addSeparator(self::coH3);
					$this->addColumnInput ('dateBegin');
					$this->addColumnInput ('dateEnd');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailAbsence
 */
class ViewDetailAbsence extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.emps.libs.dc.DCAbsence');
	}
}
