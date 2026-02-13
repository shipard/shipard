<?php

namespace e10pro\purchase\libs;


class ReportWasteInfo extends \e10doc\core\libs\reports\DocReportBase
{
	function init ()
	{
		parent::init();

		$this->setReportId('e10pro.purchase.wasteInfo');
	}

	public function loadData ()
	{
		//$this->sendReportNdx = 2000;

		parent::loadData();

		$this->loadData_MainPerson('person');
		$this->loadData_DocumentOwner ();

    $this->data ['personsAddress']['ownerOffice'] = $this->loadPersonAddress(0, 0, $this->recData['ownerOffice']);
    $this->data ['flags']['useAddressOwnerOffice'] = 1;
    $this->data ['flags']['usePersonsAddress'] = 1;

    if ($this->recData['personOffice'])
    {
      $this->data ['personsAddress']['personOffice'] = $this->loadPersonAddress(0, 0, $this->recData['personOffice']);
      $this->data ['flags']['useAddressPersonOffice'] = 1;
    }

		if ($this->recData ['addressMode'] == 1)
		{ // city & code
			$admUnit11Data = $this->app()->loadItem($this->recData['personNomencCity'], 'e10.world.admUnits'); // ZUJ
			$zujId = strval($admUnit11Data['admUnitId'] ?? '!!!');
			$admUnit10Data = $this->app()->loadItem($admUnit11Data['admUnitOwner10'], 'e10.world.admUnits'); // ORP
			$orpId = strval($admUnit10Data['admUnitId'] ?? '!!!');

			$this->data ['flags']['useORP'] = 1;
			$this->data ['ORP']['code'] = $orpId;
			$this->data ['ORP']['name'] = $admUnit10Data['fullName'] ?? '!!!';
			$this->data ['ZUJ']['code'] = $zujId;
			$this->data ['ZUJ']['name'] = $admUnit11Data['fullName'] ?? '!!!';

			$this->data ['flags']['useAddressPersonOffice'] = 0;
			$this->data ['flags']['usePersonsAddress'] = 1;
		}


		$wasteCodeRecData = $this->app()->loadItem($this->recData['wasteCodeNomenc'], 'e10.base.nomencItems');
		$this->data['wasteCode'] = $wasteCodeRecData;

		$documentRecData = $this->app()->loadItem($this->recData['srcDocument'], 'e10doc.core.heads');
		$this->data['document'] = $documentRecData;

		$authorRecData = $this->app()->loadItem($documentRecData['author'], 'e10.persons.persons');
		$this->data['author'] = $authorRecData;


		/** @var \e10doc\core\TableHeads */
		$tableDocsHeads = $this->app()->table('e10doc.core.heads');
		$wri = new \e10pro\purchase\libs\WasteInfoInReport($tableDocsHeads, $documentRecData);
		$wri->loadData();

		foreach ($wri->data['infoWasteCodes'] as $iwc)
		{
			if ($iwc['wc'] === $wasteCodeRecData['itemId'])
			{
				$this->data['wasteNotes'] = $iwc['wasteNotes'];
				break;
			}
		}
	}

	public function createToolbarSaveAs (&$printButton)
	{
	}

	public function saveAsFileName ($type)
	{
		$fn = 'PIO'.'-';
		$fn .= $this->recData['ndx'].'.pdf';
		return $fn;
	}
}
