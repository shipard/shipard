<?php

namespace e10doc\waster;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Utils\Wgs84;


/**
 * Class TableCompaniesReports
 */
class TableCompaniesReports extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.waster.companiesReports', 'e10doc_waster_companiesReports', 'Přehledy firmám o odpadech', 1469);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$props = [];

    //$props[] = ['text' => $recData['title'], 'icon' => 'system/iconCalendar'];


		//$hdr ['info'][] = ['class' => 'info', 'value' => $props];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		return ($recData['dir'] === 0) ? 'system/personCompany' : 'system/iconDelivery';
	}

	public function getRecordInfo ($recData, $options = 0)
	{
		$info = parent::getRecordInfo ($recData, $options);
		$info ['persons']['to'][] = $recData['companyPerson'];

		return $info;
	}
}


/**
 * class ViewCompaniesReports
 */
class ViewCompaniesReports extends TableView
{
	var $dir = 0;

	public function init ()
	{
		$this->addTopTabs();

		$bt = [];
		$bt [] = ['id' => 'ALL', 'title' => 'Vše', 'active' => 1];
		$bt [] = ['id' => 'sent', 'title' => 'Odeslané', 'active' => 0];
		$bt [] = ['id' => 'unsent', 'title' => 'Neodeslané', 'active' => 0];
		$this->setBottomTabs ($bt);

		parent::init();
	}

  public function addTopTabs()
  {
		$mq = [];

		$q = [];
		array_push ($q, 'SELECT wasteReturns.* FROM [e10doc_waster_wasteReturns] AS wasteReturns');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND wasteReturns.docState = 4000');
		array_push ($q, ' ORDER BY wasteReturns.[year] DESC');
		$rows = $this->table->db()->query ($q);
		foreach ($rows as $r)
		{
			$mq[] = ['id' => strval($r['ndx']), 'title' => $r['tabTitle'] === '' ? $r['year'] : $r['tabTitle'], 'icon' => 'system/filterActive', 'side' => 'left'];
			//$this->wasteReturns[$r['ndx']] = $r->toArray();
		}

		$mq[] = ['id' => 'all', 'title' => 'Vše', 'icon' => 'system/filterAll'];
		$this->setMainQueries ($mq);
  }

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['companyPersonName'];

		if ($item['sentState'] == 1)
			$listItem ['i2'][] = ['text' => Utils::datef($item['sentDate'], '%k %t'), 'icon' => 'system/iconPaperPlane', 'class' => 'label label-success'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$btId = $this->bottomTabId ();

		$q = [];
		array_push($q, 'SELECT [cr].*, companyPersons.fullName AS companyPersonName');
		array_push($q, ' FROM [e10doc_waster_companiesReports] AS [cr]');
		array_push($q, ' LEFT JOIN e10_persons_persons AS companyPersons ON cr.companyPerson = companyPersons.ndx');
		array_push($q, ' LEFT JOIN [e10doc_waster_wasteReturns] AS [wr] ON cr.wasteReturn = wr.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_personsContacts] AS [ownerOffices] ON wr.personOffice = ownerOffices.ndx');
		array_push($q, ' WHERE 1');

		array_push($q, ' AND [cr].[dir] = %i', $this->dir);

		$bottomTabId = $this->mainQueryId ();
		$wrNdx = intval($bottomTabId);
		if ($wrNdx !== 0)
			array_push($q, ' AND [cr].wasteReturn = %i', $wrNdx);

		if ($btId === 'sent')
			array_push ($q, ' AND [cr].[sentState] = %i', 1);
		elseif ($btId === 'unsent')
			array_push ($q, ' AND [cr].[sentState] = %i', 0);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [companyPersons].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[cr].', ['[companyPersons].[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * class ViewCompaniesReportsIn
 */
class ViewCompaniesReportsIn extends ViewCompaniesReports
{
	public function init ()
	{
		parent::init();
	}
}

/**
 * class ViewCompaniesReportsOut
 */
class ViewCompaniesReportsOut extends ViewCompaniesReports
{
	public function init ()
	{
		$this->dir = 1;
		parent::init();
	}
}


/**
 * class ViewDetailCompaniesReport
 */
class ViewDetailCompaniesReport extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.waster.libs.dc.DCCompanyIn');
	}
}


/**
 * class FormCompaniesReport
 */
class FormCompaniesReport extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
		  $tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
      $this->openTabs ($tabs);
        $this->openTab ();
          $this->addColumnInput ('wasteReturn');
          $this->addColumnInput ('companyPerson');
          $this->addColumnInput ('dir');
        $this->closeTab ();
      $this->closeTabs ();
		$this->closeForm ();
	}
}
