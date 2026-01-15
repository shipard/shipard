<?php

namespace e10doc\waster\libs;
use \Shipard\Utils\Utils;


/**
 * class ReportCompaniesReportIn
 */
class ReportCompaniesReportIn extends \e10doc\core\libs\reports\DocReportBase
{
  var $codeKindNdx = 1;

	var $dir = 0;
	var $wasteReturnNdx = 0;
	var $wasteReturnRecData = NULL;
	var ?\e10doc\waster\libs\WasteCompanyInfo $wasteCompanyInfo = NULL;

  var $periodBegin = NULL;
  var $periodEnd = NULL;

  var $wasteInfos = [];


	function init ()
	{
    parent::init();
		$this->setReportId('e10doc.waster.companiesReport');

		$this->paperOrientation = 'portrait';
	}

	public function loadData ()
	{
    $this->dir = $this->recData['dir'];

    if (!$this->dir === 0)
      $this->sendReportNdx = 2700;
    else
      $this->sendReportNdx = 2700;

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

		$this->data['sumRows'] = $this->wasteCompanyInfo->data['sumRows'];
		$this->data['itemsRows'] = $this->wasteCompanyInfo->data['itemsRows'];

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

    $this->data['info']['reportTitle'] = ($this->dir === 0) ? 'Přehled odebraných odpadů' : 'Přehled dodaných odpadů';

    if ($this->dir === 0)
    {
      $this->loadWasteInfos();
    }
	}

  protected function loadWasteInfos()
  {
		$q = [];
		array_push($q, 'SELECT wi.*, ');
		array_push($q, ' nomenc.fullName AS wasteCodeFullName,');
		array_push($q, ' personOffices.id1 AS personOfficeID1, personOffices.saAdmUnit11Id, personOffices.adrStreet, personOffices.adrCity, personOffices.adrZipCode, personOffices.adrCountry');
		array_push($q, ' FROM [e10pro_purchase_wasteInfo] AS [wi]');
		array_push($q, ' LEFT JOIN e10_base_nomencItems AS nomenc ON wi.wasteCodeNomenc = nomenc.ndx');
		array_push($q, ' LEFT JOIN e10_persons_personsContacts AS personOffices ON wi.personOffice = personOffices.ndx');
		array_push($q, ' WHERE wi.person = %i', $this->recData['companyPerson']);
		array_push($q, ' ORDER BY wi.validFrom, wi.ndx');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
        'wiNdx' => $r['ndx'],
				'wc' => $r['wasteCodeText'],
				'wcName' => $r['wasteCodeFullName'],
        'wiFromId' => '',
			];

		  if ($r['addressMode'] == 0)
		  { // office
			  if ($r['personOfficeID1'] !== NULL)
			  {
				  $id = $r['personOfficeID1'];
				  if ($id === '')
					  $id = '1';

          $item['wiFromId'] = $id;
        }
			}
      else
      { // city
        $item['wiFromId'] = $r['personNomencCity'];
      }

			$this->wasteInfos[] = $item;
		}
  }

	public function addMessageAttachments(\Shipard\Report\MailMessage $msg)
	{
    /** @var \e10pro\purchase\TableWasteInfo $tableWasteInfo */
    $tableWasteInfo = $this->app()->table('e10pro.purchase.wasteInfo');

    $cnt = 1;
    foreach ($this->wasteInfos as $wi)
    {
			/** @var \e10pro\purchase\libs\WasteInfoOutReport $wiReport */
			$wiReport = $tableWasteInfo->getReportData ('e10pro.purchase.libs.ReportWasteInfo', $wi['wiNdx']);
			if ($wiReport)
			{
				$wiReport->renderReport();
				$wiReport->createReport();
				$wiReport->saveReportAs();

        $attName = 'pio-'.$cnt.'-'.$wi['wc'].'-'.$wi['wiFromId'].'.pdf';
        $attName = Utils::safeChars($attName);
        $mimeType = 'application/pdf';
        $msg->addAttachment($wiReport->fullFileName, $attName, $mimeType);
			}
      $cnt++;
    }
	}

	public function reportWasSent(\Shipard\Report\MailMessage $msg)
	{
    parent::reportWasSent($msg);

    $this->db()->query('UPDATE [e10doc_waster_companiesReports] SET [sentState] = %i', 1, ', [sentDate] = NOW()', ' WHERE [ndx] = %i', $this->recData['ndx']);
	}
}
