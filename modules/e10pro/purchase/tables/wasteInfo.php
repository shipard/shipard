<?php

namespace e10pro\purchase;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * Class TableWasteInfo
 */
class TableWasteInfo extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.purchase.wasteInfo', 'e10pro_purchase_wasteInfo', 'Informace o odpadech', 1463);
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		//$recData ['author'] = $this->app()->userNdx();
		$recData ['validFrom'] = Utils::today();
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

	public function getRecordInfo ($recData, $options = 0)
	{
		$info = parent::getRecordInfo ($recData, $options);
		$info ['persons']['to'][] = $recData['person'];

		return $info;
	}
}


/**
 * class ViewWasteInfo
 */
class ViewWasteInfo extends TableView
{
	public function init ()
	{
		//$this->setMainQueries ();
		$this->addTopTabs();

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
		$listItem ['t1'] = $item['personName'];
		$listItem ['t2'] = ['text' => $item['wasteCodeText'], 'suffix' => $item['wasteCodeFullName']];

		if ($item['addressMode'] == 0)
		{ // office
			if ($item['personOfficeID1'] !== NULL)
			{
				$id = $item['personOfficeID1'];
				if ($id === '')
					$id = '---';
				$listItem ['i2'] = ['text' => 'IČP', 'suffix' => $id];
			}
		}
		else
		{ // city
			$listItem ['i2'] = ['text' => 'ZUJ', 'suffix' => $item['admUnitId11Id'] ?? '---'];
		}
		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		//$btId = $this->bottomTabId ();

		$wasteReturnNdx = intval($this->mainQueryId ());
		$wasteReturn = $this->app()->loadItem($wasteReturnNdx, 'e10doc.waster.wasteReturns');

		$q = [];
		array_push($q, 'SELECT [wi].*, persons.fullName AS personName, nomenc.fullName AS wasteCodeFullName,');
		array_push($q, ' personOffices.id1 AS personOfficeID1,');
		array_push($q, ' admUnits11.admUnitId AS admUnitId11Id');
		array_push($q, ' FROM [e10pro_purchase_wasteInfo] AS [wi]');
		array_push($q, ' LEFT JOIN e10_persons_persons AS persons ON wi.person = persons.ndx');
		array_push($q, ' LEFT JOIN e10_base_nomencItems AS nomenc ON wi.wasteCodeNomenc = nomenc.ndx');
		array_push($q, ' LEFT JOIN e10_persons_personsContacts AS personOffices ON wi.personOffice = personOffices.ndx');
		array_push($q, ' LEFT JOIN e10_world_admUnits AS admUnits11 ON wi.personNomencCity = admUnits11.ndx');
		array_push($q, ' WHERE 1');

		if ($wasteReturn)
		{
			array_push ($q, ' AND [wi].[validFrom] >= %d', $wasteReturn['dateFrom'], ' AND [wi].[validFrom] <= %d', $wasteReturn['dateTo']);
		}

		// -- fulltext
		if ($fts != '')
		{

			array_push ($q, ' AND (');
			array_push ($q, ' [persons].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [nomenc].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');

		}

		$this->queryMain ($q, '[wi].', ['[persons].[lastName]', '[ndx] DESC']);
		$this->runQuery ($q);
	}
}


/**
 * class ViewDetailWasteInfo
 */
class ViewDetailWasteInfo extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.purchase.libs.dc.WasteInfoIn');
	}
}


/**
 * class FormWasteInfo
 */
class FormWasteInfo extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openTabs ($tabs);
			$this->openTab ();
				$this->addColumnInput ('addressMode');
				$this->addColumnInput ('person');
        $this->addColumnInput ('personOffice');
				$this->addColumnInput ('personNomencCity');
        $this->addColumnInput ('wasteCodeNomenc');
			$this->closeTab ();

			$this->openTab ();
				$this->addColumnInput ('validFrom');
				$this->addColumnInput ('validTo');
				$this->addColumnInput ('srcDocument');

				$this->addColumnInput ('owner');
				$this->addColumnInput ('ownerOffice');
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();
		$this->closeTabs ();

		$this->closeForm ();
	}
}
