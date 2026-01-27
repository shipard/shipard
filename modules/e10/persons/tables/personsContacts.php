<?php

namespace e10\persons;


use \Shipard\Table\DbTable, \Shipard\Form\TableForm, \Shipard\Viewer\TableView, \Shipard\Utils\Str;
use \Shipard\Utils\World;
use \e10\base\libs\UtilsBase;


/**
 * class TablePersonsContacts
 */
class TablePersonsContacts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.persons.personsContacts', 'e10_persons_personsContacts', 'Adresy Osob');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$recData['systemOrder'] = 99;
		if ($recData['flagMainAddress'] ?? 0)
			$recData['systemOrder'] = 1;
		elseif ($recData['flagOffice'] ?? 0)
			$recData['systemOrder'] = 20;

		if (($recData['onTop'] ?? 0) == 0)
			$recData['onTop'] = 99;

		if ($recData['flagAddress'] ?? 0)
		{
			if (is_string($recData['addrPlaceInReg'] ?? '') && ($recData['addrPlaceInReg'][0] ?? '') === '{')
			{
				$regData = json_decode($recData['addrPlaceInReg'], TRUE);
				$this->applyAddrPlaceInReg($recData, $regData);
			}

			$recData['addrPlaceInReg'] = 0;

			if (!($recData['flagStandardized'] ?? 0))
			{
				$newLocHash = $this->geoCodeLocHash ($recData);
				if ($newLocHash !== $recData['adrLocHash'])
				{
					$recData['adrLocHash'] = $newLocHash;
					$recData['adrLocState'] = 0;
				}
			}

			if ($recData['adrLocManual'] ?? 0)
			{
				$recData['adrLocState'] = 1;
			}

			if (!($recData['flagStandardized'] ?? 0))
			{
				if (isset($recData['saAdmUnit10Ndx']) && $recData['saAdmUnit10Ndx'] != 0)
				{
					$admUnitRecData = $this->app()->loadItem($recData['saAdmUnit10Ndx'], 'e10.world.admUnits');
					$recData['saAdmUnit10Id'] = $admUnitRecData['admUnitId'] ?? 0;
				}
				if (isset($recData['saAdmUnit11Ndx']) && $recData['saAdmUnit11Ndx'] != 0)
				{
					$admUnitRecData = $this->app()->loadItem($recData['saAdmUnit11Ndx'], 'e10.world.admUnits');
					$recData['saAdmUnit11Id'] = $admUnitRecData['admUnitId'] ?? 0;
				}
			}
		}

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec($recData);

		if (!isset($recData['adrCountry']) || $recData['adrCountry'] == 0)
		{
			$thc = $this->app()->cfgItem ('options.core.ownerDomicile', 'cz');
			$recData['adrCountry'] = World::countryNdx($this->app(), $thc);
		}
		if (!isset($recData['onTop']) || $recData['onTop'] == 0)
			$recData['onTop'] = 99;
	}

	public function columnRefInputTitle ($form, $srcColumnId, $inputPrefix)
	{
		$pk = isset ($form->recData [$srcColumnId]) ? $form->recData [$srcColumnId] : 0;
		if (!$pk)
			return '';

		$recData = $this->loadItem($pk);
		if (!$recData)
			return '';

		$refTitle = [];
		if ($recData['adrStreet'] !== '')
			$refTitle[] = ['text' => $recData['adrStreet']];
		if ($recData['adrCity'] !== '')
			$refTitle[] = ['text' => $recData['adrCity']];
		if ($recData['adrZipCode'] !== '')
			$refTitle[] = ['text' => $recData['adrZipCode']];

		if ($recData['id1'] !== '')
			$refTitle[] = ['text' => ' IČP: '.$recData['id1'], 'class' => ''];
		if ($recData['id2'] !== '')
			$refTitle[] = ['text' => ' IČZ: '.$recData['id2'], 'class' => ''];

		return $refTitle;
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		/** @var \e10\persons\TablePersons $tablePersons */
		$tablePersons = $this->app()->table('e10.persons.persons');
		$personRecData = $tablePersons->loadItem($recData['person']);

		if ($personRecData)
		{
			$hdr ['info'][] = ['class' => 'title', 'value' => [
				[
					'text' => ($personRecData ['fullName'] !== '') ? $personRecData ['fullName'] : '!!!'.$recData['person'],
					'icon' => $tablePersons->tableIcon($personRecData),
					'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk' => strval($recData['person'])
				],
				['text' => '#'.$personRecData['id'], 'class' => 'pull-right']
			],
		];
		}

		return $hdr;
	}

	public function geoCode ($recData, $debugLevel = 0)
	{
		$googleMapsApiKey = $this->app()->cfgServer['googleMapsApiKey'] ?? '';

		if ($googleMapsApiKey === '')
		{
			return FALSE;
		}

		$locHash = $this->geoCodeLocHash($recData);
		$logEvent = ['tableid' => $this->tableId(), 'recid' => $recData['ndx'], 'eventType' => 3];

		if ($recData['adrStreet'] === '' && $recData['adrCity'] === '' && $recData['adrZipCode'] === '')
		{
			$rec = [ 'adrLocLat'=>0, 'adrLocLon'=>0, 'adrLocState' => 2, 'adrLocTime' => new \DateTime(), 'adrLocHash' => $locHash];
			$this->db()->query ('UPDATE [e10_persons_personsContacts] SET ', $rec, ' WHERE ndx = %i', $recData['ndx']);
			return TRUE;
		}

		$country = World::country($this->app(), $recData['adrCountry']);
		$addressParam = urlencode($recData['adrStreet'].', '.$recData['adrCity'].' '.$recData['adrZipCode'].', '.strtoupper($country['i']).' - '.$country['t']);
		$logEvent['eventSubtitle'] = str::upToLen('GPS: '.$recData['adrStreet'].', '.$recData['adrCity'].' '.$recData['adrZipCode'].', '.strtoupper($country['i']), 130);

		$url = 'https://maps.googleapis.com/maps/api/geocode/json?address='.$addressParam.'&key='.$googleMapsApiKey;

		if ($debugLevel > 1)
			echo "\n    -> ".$url."\n      ";

		$opts = ['http'=> ['timeout' => 1, 'method'=>'GET', 'header'=> "Connection: close\r\n"]];
		$context = stream_context_create($opts);
		$resultString = file_get_contents ($url, FALSE, $context);

		if (!$resultString)
		{
			$logEvent['eventResult'] = 3;
			$this->app()->addLogEvent($logEvent);
			return FALSE;
		}
		$logEvent['eventData'] = $resultString;
		$resultData = json_decode ($resultString, TRUE);

		if ($resultData['status'] === 'OK')
		{
			if ($debugLevel > 0)
				echo '; OK: '.$resultData['results'][0]['geometry']['location']['lat'].' x '.$resultData['results'][0]['geometry']['location']['lng'].'; ';

			$rec = [
					'adrLocLat'=>$resultData['results'][0]['geometry']['location']['lat'],
					'adrLocLon'=>$resultData['results'][0]['geometry']['location']['lng'],
					'adrLocState' => 1, 'adrLocTime' => new \DateTime(), 'adrLocHash' => $locHash
			];
			$this->db()->query ('UPDATE [e10_persons_personsContacts] SET ', $rec, ' WHERE ndx = %i', $recData['ndx']);
			$logEvent['eventResult'] = 1;
			$this->app()->addLogEvent($logEvent);
			return TRUE;
		}

		if ($resultData['status'] === 'ZERO_RESULTS')
		{
			$rec = ['adrLocLat'=>0, 'adrLocLon'=>0, 'adrLocState' => 2, 'adrLocTime' => new \DateTime(), 'adrLocHash' => $locHash];
			$this->db()->query ('UPDATE [e10_persons_personsContacts] SET ', $rec, ' WHERE ndx = %i', $recData['ndx']);
			$logEvent['eventResult'] = 2;
			$this->app()->addLogEvent($logEvent);
			return TRUE;
		}

		if ($debugLevel > 0)
			echo '; INVALID: '.json_encode($resultData).'; ';

		$logEvent['eventResult'] = 3;
		$this->app()->addLogEvent($logEvent);

		return FALSE;
	}

	public function geoCodeLocHash ($recData)
	{
		return md5(($recData['adrStreet'] ?? '').'_'.($recData['adrCity'] ?? '').'_'.($recData['adrZipCode'] ?? '').'_'.($recData['adrCountry'] ?? ''));
	}

	public function applyAddrPlaceInReg(&$recData, $regAddrPlace)
	{
		$recData['natAddressGeoId'] = $regAddrPlace['addrPlaceId'] ?? 0;
		$recData['saStreetName'] = $regAddrPlace['streetFullName'] ?? '';
		$recData['saStreetId'] = $regAddrPlace['saStreetId'] ?? 0;
		$recData['saHouseNr1Type'] = $regAddrPlace['houseNr1Type'] ?? 0;
		$recData['saHouseNr'] = $regAddrPlace['houseNr'] ?? '';
		$recData['saCityPartName'] = $regAddrPlace['cityPartFullName'] ?? '';
		$recData['saCityPartId'] = $regAddrPlace['saCityPartId'] ?? 0;
		$recData['saCityPart2Name'] = $regAddrPlace['cityPart2FullName'] ?? '';
		$recData['saCityPart2Id'] = $regAddrPlace['saCityPart2Id'] ?? 0;
		$recData['saCityName'] = $regAddrPlace['cityFullName'] ?? '';
		$recData['saCityId'] = $regAddrPlace['saCityId'] ?? 0;
		$recData['saZipCodeId'] = $regAddrPlace['zipCodeIdName'] ?? '';

		$recData['saAdmUnit10Ndx'] = $this->admUnitNdx($regAddrPlace['admUnit10Id'] ?? 0, 10);
		$recData['saAdmUnit11Ndx'] = $this->admUnitNdx($regAddrPlace['admUnit11Id'] ?? 0, 11);

		$recData['saAdmUnit10Id'] = $regAddrPlace['admUnit10Id'] ?? 0;
		$recData['saAdmUnit11Id'] = $regAddrPlace['admUnit11Id'] ?? 0;


		// -- 'old' columns
		$recData['adrStreet'] = $regAddrPlace['streetFullName'] ?? '';
		if ($recData['saHouseNr'] !== '')
		{
			if ($recData['adrStreet'] === '')
			{
				$recData['adrStreet'] = $recData['saHouseNr1Type'] == 1 ? 'č.ev. ' : 'č.p. ';
			}
			else
				$recData['adrStreet'] .= ' ';
			$recData['adrStreet'] .= $recData['saHouseNr'];
		}
		$recData['adrCity'] = $regAddrPlace['cityFullName'] ?? '';

		$recData['adrZipCode'] = $regAddrPlace['zipCodeIdName'] ?? '';

		// -- geo loc
		$recData['adrLocLat'] = $regAddrPlace['wgs84lat'] ?? 0.0;
		$recData['adrLocLon'] = $regAddrPlace['wgs84lng'] ?? 0.0;
		$recData['adrLocState'] = 1;
		$recData['adrLocHash'] = $this->geoCodeLocHash ($recData);
		$recData['adrLocTime'] = NULL;
	}

  protected function admUnitNdx($admUnitId, $level)
  {
    $unit = $this->app()->db()->query('SELECT ndx FROM [e10_world_admUnits] WHERE country = %i', 60,
                                        ' AND admUnitId = %s ', $admUnitId,
                                        ' AND level = %i', $level)->fetch();
    if ($unit)
      return $unit['ndx'];
    return 0;
  }

	public function addressTextOneLine($recData)
	{
		$txt = '';
		if ($recData['adrStreet'] !== '')
			$txt .= $recData['adrStreet'];
		if ($recData['adrCity'] !== '')
		{
			if ($txt !== '')
				$txt .= ', ';
			$txt .= $recData['adrCity'];
		}
		if ($recData['adrZipCode'] !== '')
		{
			if ($txt !== '')
				$txt .= ', ';
			$txt .= $recData['adrZipCode'];
		}

		return $txt;
	}

	public function addressTextRow($recData)
	{
		$ap = [];
		if ($recData['adrSpecification'] != '')
			$ap[] = $recData['adrSpecification'];
		if ($recData['adrStreet'] != '')
			$ap[] = $recData['adrStreet'];
		if ($recData['adrCity'] != '')
			$ap[] = $recData['adrCity'];
		if ($recData['adrZipCode'] != '')
			$ap[] = $recData['adrZipCode'];

		//$country = World::country($this->app(), $recData['adrCountry']);
		//$ap[] = /*$country['f'].' '.*/$country['t'];

		$address = implode(', ', $ap);
		return $address;
	}
}


/**
 * class ViewPersonsContactsCombo
 */

class ViewPersonsContactsCombo extends TableView
{
	var $personNdx = 0;
	var $classification = [];

	public function init ()
	{
		$this->enableDetailSearch = TRUE;
		$this->objectSubType = TableView::vsDetail;

    $this->personNdx = intval($this->queryParam('personNdx'));
		$this->addAddParam('person', $this->personNdx);

		$this->toolbarTitle = ['text' => 'Adresy', 'class' => 'h2 e10-bold'/*, 'icon' => 'system/iconMapMarker'*/];
		$this->setMainQueries();

		parent::init();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [contacts].* ';
		array_push ($q, ' FROM [e10_persons_personsContacts] AS [contacts]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [contacts].[person] = %i', $this->personNdx);
		array_push ($q, ' AND [contacts].[flagAddress] = %i', 1);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [contacts].adrCity LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [contacts].adrStreet LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [contacts].adrSpecification LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[contacts].', ['[adrCity]', '[ndx]']);
		$this->runQuery ($q);

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);
	}

	public function renderRow ($item)
	{
		//$at = $this->addressTypes[$item['type']];

		$listItem ['pk'] = $item ['ndx'];
		//$listItem ['icon'] = $this->table->tableIcon ($item);

    $address = '';
    $addressFlags = [];

    if ($item['flagAddress'])
    {
      $ap = [];

      if ($item['adrSpecification'] != '')
        $ap[] = $item['adrSpecification'];
      if ($item['adrStreet'] != '')
        $ap[] = $item['adrStreet'];
      if ($item['adrCity'] != '')
        $ap[] = $item['adrCity'];
      if ($item['adrZipCode'] != '')
        $ap[] = $item['adrZipCode'];

      $country = World::country($this->app(), $item['adrCountry']);
      $ap[] = /*$country['f'].' '.*/$country['t'];

      $address = implode(', ', $ap);

      if ($item['flagMainAddress'])
        $addressFlags[] = ['text' => 'Sídlo', 'class' => 'label label-default'];
      if ($item['flagPostAddress'])
        $addressFlags[] = ['text' => 'Korespondenční', 'class' => 'label label-default'];
      if ($item['flagOffice'])
        $addressFlags[] = ['text' => 'Provozovna', 'class' => 'label label-default'];

      if ($item['id1'] !== '')
        $addressFlags[] = ['text' => 'IČP: '.$item['id1'], 'class' => 'label label-default'];

      if ($item['id2'] !== '')
        $addressFlags[] = ['text' => 'IČZ: '.$item['id2'], 'class' => 'label label-default'];

			$listItem['t1'] = $address;

      if (count($addressFlags))
        $listItem['t2'] = $addressFlags;
    }

    if ($item['flagContact'])
    {
      $cf = [];
      if ($item['contactName'] != '')
        $cf[] = ['text' => $item['contactName'], 'class' => 'label label-default'];
      if ($item['contactRole'] != '')
        $cf[] = ['text' => $item['contactRole'], 'class' => 'label label-default'];
      if ($item['contactEmail'] != '')
        $cf[] = ['text' => $item['contactEmail'], 'class' => 'label label-default', 'icon' => 'system/iconEmail'];
      if ($item['contactPhone'] != '')
        $cf[] = ['text' => $item['contactPhone'], 'class' => 'label label-default', 'icon' => 'system/iconPhone'];

      if (count($addressFlags))
        $listItem['t3'] = $cf;
    }

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{

			forEach ($this->classification [$item ['pk']] as $clsfGroup)
			{
				if (!isset($item ['t2']))
					$item ['t2'] = [];
				$item ['t2'] = array_merge ($item ['t2'], $clsfGroup);
			}
		}
	}
}

/**
 * class FormPersonContact
 */
class FormPersonContact extends TableForm
{
	var $idsOptions = NULL;
	var $useStandardizedAddress = 0;

	public function renderForm ()
	{
		$useOfficesIds = intval($this->app()->cfgItem ('options.persons.useOfficesIds', 0));
		$useAdmUnits11 = intval($this->app()->cfgItem ('options.persons.useAdmUnits11', 0));
		$this->useStandardizedAddress = intval($this->app()->cfgItem ('options.persons.useStandardizedAddress', 0));

		$this->loadContactIdsOptions();

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('sidebarWidth', '0.30');

		$tabs ['tabs'][] = ['text' => 'Kontakt', 'icon' => 'formContacts'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		if ($this->recData['flagStandardized'])
			$tabs ['tabs'][] = ['text' => 'Info', 'icon' => 'system/iconMapMarker'];
		$tabs ['tabs'][] = ['text' => 'Historie', 'icon' => 'system/formHistory'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('flagAddress', self::coRightCheckbox);
					$this->openRow();
						if ($this->recData['flagAddress'])
						{
							$this->addColumnInput ('flagMainAddress', self::coRightCheckbox);
							$this->addColumnInput ('flagPostAddress', self::coRightCheckbox);
							$this->addColumnInput ('flagOffice', self::coRightCheckbox);
							if ($this->useStandardizedAddress)
								$this->addColumnInput ('flagStandardized', self::coRightCheckbox);
						}
					$this->closeRow();
					$needSep = 0;
					if ($this->recData['flagAddress'])
					{
						if ($this->recData['flagStandardized'])
							$this->renderForm_addrStandardized();
						else
						{
							$this->addColumnInput ('adrSpecification');
							$this->addColumnInput ('adrStreet');
							$this->addColumnInput ('adrCity');
							$this->addColumnInput ('adrZipCode');
							$this->addColumnInput ('adrCountry');
							if ($useAdmUnits11)
							{
								$this->addColumnInput ('saAdmUnit11Ndx');
							}
						}
						if ($useOfficesIds && $this->idsOptions && (isset($this->idsOptions['id1']) || isset($this->idsOptions['id2'])))
						{
							$this->addSeparator(self::coH4);
							if (isset($this->idsOptions['id1']))
								$this->addColumnInput ('id1');
							if (isset($this->idsOptions['id2']))
								$this->addColumnInput ('id2');
							$needSep = 1;
						}
					}

					$this->renderForm_contact();

					$this->addSeparator(self::coH4);
					$this->addList ('clsf', '', TableForm::loAddToFormLayout);
					$this->addColumnInput ('onTop');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('validFrom');
					$this->addColumnInput ('validTo');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('adrLocManual');
					if ($this->recData['adrLocManual'])
					{
						$this->addColumnInput ('adrLocLat');
						$this->addColumnInput ('adrLocLon');
					}
				$this->closeTab ();
				if ($this->recData['flagStandardized'])
				{
					$this->openTab (self::ltNone);
						$this->addDocumentCard('e10.persons.libs.dc.DCAddress');
					$this->closeTab();
				}
				$this->openTab(self::ltNone);
					$params = ['tableid' => $this->tableId(),'recid' => $this->recData['ndx']];
					$this->addViewerWidget('e10.base.docslog', 'e10.base.libs.ViewDocsLogDocHistory', $params);
				$this->closeTab();
			$this->closeTabs ();
		$this->closeForm ();
	}

	protected function renderForm_contact()
	{
		$this->addSeparator(self::coH4);
		$this->addColumnInput ('flagContact', self::coRightCheckbox);
		if ($this->recData['flagContact'])
		{
			$this->addColumnInput ('contactName');
			$this->addColumnInput ('contactRole');
			$this->addColumnInput ('contactEmail');
			$this->addColumnInput ('contactPhone');
			$this->addSeparator(self::coH4);
			$this->addList ('sendReports', '', TableForm::loAddToFormLayout);
		}
	}

	protected function renderForm_addrStandardized()
	{
		$this->addSeparator(self::coH4);
		if (!$this->readOnly)
			$this->addColumnInput ('addrPlaceInReg', self::coFocus);

		$addInputsOption = 0;
		if ($this->recData['flagStandardized'])
			$addInputsOption = self::coReadOnly;

		$this->addColumnInput ('adrSpecification');
		$this->addColumnInput ('saStreetName', $addInputsOption);
		$this->addColumnInput ('saHouseNr', $addInputsOption);
		$this->addColumnInput ('saCityPartName', $addInputsOption);
		$this->addColumnInput ('saCityPart2Name', $addInputsOption);
		$this->addColumnInput ('saCityName', $addInputsOption);
		$this->addColumnInput ('saZipCodeId', $addInputsOption);
		$this->addColumnInput ('adrCountry');
		$this->addColumnInput ('saAdmUnit10Ndx');
		$this->addColumnInput ('saAdmUnit11Ndx');
//		$this->addColumnInput ('saAdmUnit10Id');
//		$this->addColumnInput ('saAdmUnit11Id');
	}

	public function loadContactIdsOptions()
	{
		if (!($this->recData['flagOffice'] ?? 0))
			return;

		$cid = World::countryId($this->app(), $this->recData['adrCountry']);
		if ($cid === '')
			return;

		$idsOptions = $this->app()->cfgItem('e10.persons.contactsIds.'.$cid, NULL);
		if (!$idsOptions)
			return;

		$personTypeRec = $this->app()->db()->query('SELECT [company] FROM [e10_persons_persons] WHERE [ndx] = %i', $this->recData['person'])->fetch();
		if (!$personTypeRec)
			return;

		if ($personTypeRec['company'] && isset($idsOptions['company']))
		{
			$this->idsOptions = $idsOptions['company'];
			return;
		}
	}

	function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case	'id1': if ($this->idsOptions && isset($this->idsOptions['id1'])) return $this->idsOptions['id1']['label']; break;
      case	'id2': if ($this->idsOptions && isset($this->idsOptions['id2'])) return $this->idsOptions['id2']['label']; break;
    }

		return parent::columnLabel ($colDef, $options);
  }
}
