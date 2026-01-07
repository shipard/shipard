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
		$this->setName ('e10pro.purchase.wasteInfo', 'e10pro_purchase_wasteInfo', 'Informace o odpadech');
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
}


/**
 * class ViewWasteInfo
 */
class ViewWasteInfo extends TableView
{
	public function init ()
	{
		$this->setMainQueries ();

		parent::init();
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
			$listItem ['i2'] = ['text' => 'ORP', 'suffix' => $item['personNomencCityId'] ?? '---'];
		}
		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push($q, 'SELECT [wi].*, persons.fullName AS personName, nomenc.fullName AS wasteCodeFullName,');
		array_push($q, ' personOffices.id1 AS personOfficeID1,');
		array_push($q, ' cities.itemId AS personNomencCityId');
		array_push($q, ' FROM [e10pro_purchase_wasteInfo] AS [wi]');
		array_push($q, ' LEFT JOIN e10_persons_persons AS persons ON wi.person = persons.ndx');
		array_push($q, ' LEFT JOIN e10_base_nomencItems AS nomenc ON wi.wasteCodeNomenc = nomenc.ndx');
		array_push($q, ' LEFT JOIN e10_persons_personsContacts AS personOffices ON wi.personOffice = personOffices.ndx');
		array_push($q, ' LEFT JOIN e10_base_nomencItems AS cities ON wi.personNomencCity = cities.ndx');
		array_push($q, ' WHERE 1');

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
