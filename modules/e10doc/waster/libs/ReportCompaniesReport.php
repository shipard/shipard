<?php

namespace e10doc\waster\libs;
use \Shipard\Utils\Utils;


/**
 * class ReportCompaniesReport
 */
class ReportCompaniesReport extends \e10doc\core\libs\reports\DocReportBase
{
  var $codeKindNdx = 1;

	var $dir = 0;
	var $wasteReturnNdx = 0;
	var $wasteReturnRecData = NULL;
	var ?\e10doc\waster\libs\WasteCompanyInfo $wasteCompanyInfo = NULL;

  var $periodBegin = NULL;
  var $periodEnd = NULL;


	function init ()
	{
		parent::init();
		$this->setReportId('e10doc.waster.companiesReport');

		$this->paperOrientation = 'portrait';
	}

	public function loadData ()
	{
		parent::loadData();

    $this->wasteReturnNdx = $this->recData['wasteReturn'];
		if ($this->wasteReturnNdx)
		{
			$this->wasteReturnRecData = $this->app()->loadItem($this->wasteReturnNdx, 'e10doc.waster.wasteReturns');
		}

		$this->wasteCompanyInfo = new \e10doc\waster\libs\WasteCompanyInfo($this->app());
		$this->wasteCompanyInfo->dir = $this->dir;
		$this->wasteCompanyInfo->setPerson($this->recData['companyPerson']);
		$this->wasteCompanyInfo->setPeriod($this->wasteReturnRecData['dateFrom'], $this->wasteReturnRecData['dateTo']);
		$this->wasteCompanyInfo->loadData();


		$this->loadData_MainPerson('companyPerson');
		$this->loadData_DocumentOwner (0);

    $this->data['wasteReturn'] = $this->app()->loadItem($this->recData['wasteReturn'], 'e10doc.waster.wasteReturns');
    $this->periodBegin = $this->data['wasteReturn']['dateFrom'];
    $this->periodEnd = $this->data['wasteReturn']['dateTo'];



    $this->loadData_MainPerson('ownerPerson', $this->data['wasteReturn']['person']);
    $this->data['ownerPerson']['office'] = $this->app()->loadItem($this->data['wasteReturn']['personOffice'], 'e10.persons.personsContacts');
    $this->data['ownerPerson']['ownerOffice'] = $this->loadPersonAddress(0, 0, $this->data['wasteReturn']['personOffice']);//$this->app()->loadItem($this->data['wasteReturn']['personOffice'], 'e10.persons.personsContacts');

    $this->data['person']['addressMain'] = $this->loadPersonAddress($this->data['wasteReturn']['person'], 1);
		$this->data['person']['addressOffice'] = $this->loadPersonAddress($this->data['wasteReturn']['person'], 0, $this->data['wasteReturn']['personOffice']);

    //$this->loadWastes();

	}

  public function loadWastes()
  {

    $q = [];
    array_push ($q, 'SELECT [rows].wasteCodeNomenc, SUM([rows].quantityKG) as quantityKG,');
    array_push ($q, ' nomencItems.fullName, nomencItems.itemId');
		array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
    array_push ($q, ' LEFT JOIN [e10doc_core_heads] AS heads ON [rows].document = heads.ndx');
    array_push ($q, ' LEFT JOIN [e10_world_admUnits] AS admUnits ON [heads].wasteOriginAdmUnit = admUnits.ndx');
    array_push ($q, ' LEFT JOIN [e10_persons_personsContacts] AS ownerOffices ON [heads].ownerOffice = ownerOffices.ndx');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [heads].wasteOriginAdmUnit = %i', $this->recData['wasteOriginAdmUnit']);
    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $this->codeKindNdx);
		array_push ($q, ' AND [rows].personType = %i', 1);
    array_push ($q, ' AND [rows].[dir] = %i', 0);
    array_push ($q, ' AND [heads].[docState] = %i', 4000);
    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);

    array_push ($q, ' GROUP BY wasteCodeNomenc');
    array_push ($q, ' ORDER BY wasteCodeNomenc');

		$rows = $this->app->db()->query ($q);
		$data = [];
		forEach ($rows as $r)
		{
      $item = [
        'wasteCode' => $r['itemId'],
        'wasteName' => $r['fullName'],
        'quantity' => round($r['quantityKG'] / 1000.0, 6),
      ];
      $data[] = $item;
		}

    $header = [
      '#' => '#',
      'wasteCode' => 'Katalogové číslo',
      'wasteName' => 'Název odpadu',
      'quantity' => '+Množství odpadu [t]',
    ];

		$content = ['type' => 'table', 'header' => $header, 'table' => $data, 'params' => ['precision' => 6]];
    $this->data['wasteRows'] = [$content];
  }
}
