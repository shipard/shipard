<?php

namespace e10doc\waster;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Utils\Wgs84;

/**
 * Class TableWasteInfo
 */
class TableMuniReports extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.waster.muniReports', 'e10doc_waster_muniReports', 'Hlášení obcím o odpadech');
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
		$info ['persons']['to'][] = $recData['muniPerson'];

		return $info;
	}
}


/**
 * class ViewMuniReports
 */
class ViewMuniReports extends TableView
{
	public function init ()
	{
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
    $listItem ['i1'] = ['text' => Utils::nf($item['admUnitId']), 'class' => 'id'];
		$listItem ['t1'] = $item['admUnitName'];

    $mpProps = [];
    if ($item['municipalityPersonOid'] !== '')
      $mpProps[] = ['text' => $item['municipalityPersonOid'], 'class' => 'label label-default'];

    if ($item['muniPersonName'] !== '')
      $mpProps[] = ['text' => $item['muniPersonName'], 'class' => ''];

    $listItem ['t2'] = $mpProps;

		$listItem ['t3'] = [];
		if ($item['admUnitOwner2FullName'])
			$listItem ['t3'][] = ['text' => $item['admUnitOwner2FullName'], 'class' => 'label label-info'];
		if ($item['admUnitOwner1FullName'])
			$listItem ['t3'][] = ['text' => $item['admUnitOwner1FullName'], 'class' => 'label label-primary'];
		if ($item['admUnitOwner0FullName'])
			$listItem ['t3'][] = ['text' => $item['admUnitOwner0FullName'], 'class' => 'label label-warning'];

		$distance = round(Wgs84::computeDistance($item['ownerLat'], $item['ownerLon'], $item['admUnitLat'], $item['admUnitLon']) / 1000, 0);
		$listItem ['i2'] = ['text' => $distance.' km', 'class' => 'label label-default'];
		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push($q, 'SELECT [mr].*, muniPersons.fullName AS muniPersonName,');
		array_push($q, ' admUnits.admUnitId AS admUnitId, admUnits.fullName AS admUnitName, admUnits.municipalityPersonOid,');
		array_push($q, ' [admUnits2].[fullName] AS [admUnitOwner2FullName], [admUnits1].[fullName] AS [admUnitOwner1FullName], [admUnits0].[fullName] AS [admUnitOwner0FullName],');
		array_push($q, ' [admUnits].wgs84lat AS admUnitLat, [admUnits].wgs84lng AS admUnitLon,');
		array_push($q, ' ownerOffices.adrLocLat AS ownerLat, ownerOffices.adrLocLon AS ownerLon');
		array_push($q, ' FROM [e10doc_waster_muniReports] AS [mr]');
		array_push($q, ' LEFT JOIN e10_persons_persons AS muniPersons ON mr.muniPerson = muniPersons.ndx');
		array_push($q, ' LEFT JOIN [e10_world_admUnits] AS [admUnits] ON mr.wasteOriginAdmUnit = admUnits.ndx');
		array_push($q, ' LEFT JOIN [e10_world_admUnits] AS [admUnits2] ON admUnits.admUnitOwner2 = admUnits2.ndx');
		array_push($q, ' LEFT JOIN [e10_world_admUnits] AS [admUnits1] ON admUnits.admUnitOwner1 = admUnits1.ndx');
		array_push($q, ' LEFT JOIN [e10_world_admUnits] AS [admUnits0] ON admUnits.admUnitOwner0 = admUnits0.ndx');
		array_push($q, ' LEFT JOIN [e10doc_waster_wasteReturns] AS [wr] ON mr.wasteReturn = wr.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_personsContacts] AS [ownerOffices] ON wr.personOffice = ownerOffices.ndx');

		array_push($q, ' WHERE 1');

		$bottomTabId = $this->mainQueryId ();
		$wrNdx = intval($bottomTabId);
		if ($wrNdx !== 0)
			array_push($q, ' AND [mr].wasteReturn = %i', $wrNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [muniPersons].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [admUnits].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[mr].', ['[admUnits].[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * class ViewDetailMuniReport
 */
class ViewDetailMuniReport extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.waster.libs.dc.DCMuniReport');
	}
}


/**
 * class FormMuniReport
 */
class FormMuniReport extends TableForm
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
          $this->addColumnInput ('wasteOriginAdmUnit');
          $this->addColumnInput ('muniPerson');
        $this->closeTab ();
      $this->closeTabs ();
		$this->closeForm ();
	}
}
