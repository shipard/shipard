<?php

namespace e10pro\vendms;

use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Utils\Str;

/**
 * Class TableCredits
 */
class TableCredits extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.vendms.credits', 'e10pro_vendms_credits', 'Kredity osob');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (Utils::dateIsBlank($recData ['created'] ?? NULL))
		{
			$recData ['created'] = new \DateTime();
		}

		$mt = $this->app()->cfgItem('e10pro.vendms.creditMoveTypes.'.$recData['moveType'], NULL);
		if ($mt)
		{
			$recData ['title'] = $mt['fn'];
			if ($recData['person'])
			{
				$personRecData = $this->app()->loadItem($recData['person'], 'e10.persons.persons');
				if ($personRecData)
				$recData ['title'] .= ': '.$personRecData['fullName'];

				$recData ['title'] = Str::upToLen($recData ['title'], 120);
			}
		}
  }

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}
}


/**
 * class ViewCredits
 */
class ViewCredits extends TableView
{
	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		if ($item['personFullName'])
			$listItem ['t1'] = $item['personFullName'];
		else
		{
			$listItem ['t1'] = 'Chybí osoba!';
			$listItem['class'] = 'e10-error';
		}

		$listItem ['t2'] = $item['title'];

		$listItem ['i1'] = ['text' => Utils::nf($item['amount'], 2), 'class' => 'h2'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['i2'] = Utils::datef($item['created'], '%S %T');

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [credits].*,');
    array_push ($q, ' [persons].fullName AS personFullName');
    array_push ($q, ' FROM [e10pro_vendms_credits] AS [credits]');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [credits].[person] = [persons].ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [persons].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[credits].', ['[created] DESC', '[moveType], [ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class ViewDetailCredit
 */
class ViewDetailCredit extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * class FormCredit
 */
class FormCredit extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Kredit', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
          $this->addColumnInput ('person');
          $this->addColumnInput ('amount');
					$this->addColumnInput ('moveType');
				$this->closeTab ();

				$this->openTab ();
					$this->addColumnInput ('doc');
					//$this->addColumnInput ('bankTransId');
					//$this->addColumnInput ('bankTransNdx');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}
