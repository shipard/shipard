<?php

namespace e10doc\waster\libs\dc;


/**
 * class DCCompanyIn
 */
class DCCompanyIn extends \Shipard\Base\DocumentCard
{
	var $dir = 0;

	var $wasteReturnNdx = 0;
	var $wasteReturnRecData = NULL;
	var ?\e10doc\waster\libs\WasteCompanyInfo $wasteCompanyInfo = NULL;

	public function createContentBody ()
	{
		if ($this->dir === 0)
			$this->addWasteInfo();
    $this->addItemRows ();
	}

	public function addWasteInfo()
	{
		/** @var \e10\persons\TablePersonsContacts $tablePersonsContacts */
		$tablePersonsContacts = $this->app()->table('e10.persons.personsContacts');

		$t = [];

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
				'date' => $r['validFrom'],
				'wc' => [
						['text' => $r['wasteCodeText'], 'class' => 'e10-bold'],
						['text' => $r['wasteCodeFullName'], 'class' => 'break e10-small'],
				],
				'wcName' => $r['wasteCodeFullName'],
				'from' => [],
			];

		if ($r['addressMode'] == 0)
		{ // office
			if ($r['personOfficeID1'] !== NULL)
			{
				$id = $r['personOfficeID1'];
				if ($id === '')
					$id = '1';
				$item['from'][] = ['text' => 'IČP: ', 'suffix' => $id, 'class' => 'label label-default'];

				if ($r['saAdmUnit11Id'])
				{
					$item['from'][] = ['text' => 'IČZUJ: ', 'suffix' => $r['saAdmUnit11Id'], 'class' => 'label label-default'];
				}

				$addrText = $tablePersonsContacts->addressTextRow($r);
				if ($addrText !== '')
					$item['from'][] = ['text' => $addrText, 'class' => 'break e10-small'];
			}
			else
				$item['from'][] = ['text' => 'IČP: ', 'suffix' => '!!!1'];
		}
		else
		{ // city
			//$listItem ['i2'] = ['text' => 'ORP', 'suffix' => $item['personNomencCityId'] ?? '---'];
		}


			$t[] = $item;
		}

		$h = ['#' => '#', 'date' => 'Datum', 'wc' => 'Kód odpadu', 'from' => 'IČP / ORP', 'state' => 'Stav'];
		$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'paneTitle' => 'PIO', 'type' => 'table', 'header' => $h, 'table' => $t], );
	}

  public function addItemRows ()
	{

		$cp = $this->wasteCompanyInfo->data['sumRows'][0];
		$cp['pane'] = 'e10-pane e10-pane-table';
		$this->addContent('body', $cp);


		$cp = $this->wasteCompanyInfo->data['itemsRows'][0];
		$cp['pane'] = 'e10-pane e10-pane-table';
		$this->addContent('body', $cp);

	}

	public function createContent ()
	{
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

		$this->createContentBody ();
	}
}

