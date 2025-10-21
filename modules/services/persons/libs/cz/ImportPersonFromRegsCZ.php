<?php

namespace services\persons\libs\cz;

use services\persons\libs\ImportPersonFromRegs;
use \Shipard\Utils\Utils, \Shipard\Utils\Str, \Shipard\Utils\Json;
use \services\persons\libs\LogRecord;

/**
 * @class ImportPersonFromRegsCZ
 */
class ImportPersonFromRegsCZ extends ImportPersonFromRegs
{
  var string $primaryVATID = '';
  var string $primaryTAXID = '';

  CONST vatNone = 0, vatStandard = 1, vatGroup = 2, vatUnknown = 99;

  var $useVAT = self::vatNone;
  var $useRZP = 0;

  function fillAddress(array $data, array &$dest)
  {
    $dest['addressId'] = $data['addressId'];

    $street = trim($data['street'] ?? '');
    if ($street == '')
      $street = trim($data['city'] ?? '');

    $streetNumber = $data['streetNumber'] ?? '';
    if (isset($data['streetNumber2']) && $data['streetNumber2'] !== '')
    {
      if ($streetNumber !== '')
        $streetNumber .= '/';
      $streetNumber .= $data['streetNumber2'];
    }

    if ($streetNumber != '')
      $street .= ' '.$streetNumber;

    $dest['street'] = trim($street);
    $dest['city'] = trim($data['city'] ?? '');
    $dest['zipcode']= trim($data['zipcode'] ?? '');
    $dest['specification'] = Str::upToLen(trim($data['specification'] ?? ''), 160);

    $dest['country'] = $data['country'] ?? 60; // CZ

    if (isset($data['validFrom']))
      $dest['validFrom'] = $data['validFrom'];
    if (isset($data['validTo']))
      $dest['validTo'] = $data['validTo'];

    $dest['natAddressGeoId'] = intval($data['natAddressGeoId'] ?? 0);
    $dest['source'] = intval($data['source'] ?? 0);
  }

  function createAddressARES(array $data, array &$dest)
  {
    if ($this->app()->debug > 1)
      echo Json::lint($data)."\n";

    $dest['natAddressGeoId'] = intval($data['kodAdresnihoMista'] ?? 0);
    $dest['standardized'] = 0;

    $partlyStandardized = 0;

    // -- standardized mode
    if (isset($data['standardizaceAdresy']) && $data['standardizaceAdresy'])
    {
      $dest['standardized'] = 1;
      $dest['addressPlaceNdx'] = 0;
      $addrPlaceRec = $this->db()->query('SELECT ndx FROM [services_locAddr_addrPlaces] WHERE [addrPlaceId] = %i', $dest['natAddressGeoId'],
                          ' AND [country] = %i', 60 /* CZ */)->fetch();
      if ($addrPlaceRec)
        $dest['addressPlaceNdx'] = $addrPlaceRec['ndx'];
    }

    $saLaUnit11Id = 0;
    $saLaUnit11Ndx = 0;
    $saLaUnit10Ndx = 0;

    $dest['saStreetName'] = $data['nazevUlice'] ?? '';
    $dest['saStreetId'] = intval($data['kodUlice'] ?? 0);
    if ($dest['saStreetId'])
    {
      $streetRec = $this->db()->query('SELECT ndx FROM [services_locAddr_streets] WHERE [streetId] = %i', $dest['saStreetId'],
                          ' AND [country] = %i', 60 /* CZ */)->fetch();
      if ($streetRec)
      {
        $dest['saStreetNdx'] = $streetRec['ndx'];
        $partlyStandardized = 1;
      }
    }

    $dest['saCityName'] = $data['nazevObce'] ?? '';
    $dest['saCityId'] = intval($data['kodObce'] ?? 0);
    if ($dest['saCityId'])
    {
      $cityRec = $this->db()->query('SELECT ndx, laUnit11, laUnit10 FROM [services_locAddr_cities] WHERE [cityId] = %i', $dest['saCityId'],
                          ' AND [country] = %i', 60 /* CZ */)->fetch();
      if ($cityRec)
      {
        $dest['saCityNdx'] = $cityRec['ndx'];
        $saLaUnit11Ndx = $cityRec['laUnit11'];
        $saLaUnit10Ndx = $cityRec['laUnit10'];
        $partlyStandardized = 1;
      }
    }

    $dest['saCityPartName'] = $data['nazevCastiObce'] ?? '';
    $dest['saCityPartId'] = intval($data['kodCastiObce'] ?? 0);
    if ($dest['saCityPartId'])
    {
      $cityRec = $this->db()->query('SELECT ndx FROM [services_locAddr_citiesParts] WHERE [cityPartId] = %i', $dest['saCityPartId'],
                          ' AND [country] = %i', 60 /* CZ */)->fetch();
      if ($cityRec)
      {
        $dest['saCityPartNdx'] = $cityRec['ndx'];
        $partlyStandardized = 1;
      }
    }

    $dest['saCityPart2Name'] = $data['nazevMestskehoObvodu'] ?? $data['nazevMestskeCastiObvodu'] ?? '';
    $dest['saCityPart2Id'] = intval($data['kodMestskeCastiObvodu'] ?? 0);
    if ($dest['saCityPart2Id'])
    {
      $cityPartRec = $this->db()->query('SELECT ndx, laUnit11 FROM [services_locAddr_citiesParts] WHERE [cityPartId] = %i', $dest['saCityPart2Id'],
                          ' AND [cityPartKind] = %i', 1, ' AND [country] = %i', 60 /* CZ */)->fetch();
      if ($cityPartRec)
      {
        $dest['saCityPart2Ndx'] = $cityPartRec['ndx'];
        if ($cityPartRec['laUnit11'])
          $saLaUnit11Ndx = $cityPartRec['laUnit11'];
        $partlyStandardized = 1;
      }
    }

    $dest['saZipCodeId'] = strval($data['psc'] ?? $data['pscTxt'] ?? '');
    $zipCodeNumber = intval($dest['saZipCodeId']);
    if ($zipCodeNumber)
    {
      $zipCodeRec = $this->db()->query('SELECT ndx FROM [services_locAddr_zipCodes] WHERE [zipCodeId] = %i', $zipCodeNumber,
                          ' AND [country] = %i', 60 /* CZ */)->fetch();
      if ($zipCodeRec)
      {
        $dest['saZipCodeNdx'] = $zipCodeRec['ndx'];
        $partlyStandardized = 1;
      }
    }

    $houseNRTypeARES = intval($data['typCisloDomovni'] ?? 1);
    $dest['saHouseNr1'] = 0;
    $dest['saHouseNr2'] = 0;
    $dest['saHouseNrLetter'] = '';
    if ($houseNRTypeARES == 1) // číslo popisné (1)
    {
      $dest['saHouseNr1Type'] = 0; // číslo popisné
      $dest['saHouseNr1'] = intval($data['cisloDomovni'] ?? 0);
      $dest['saHouseNr2'] = intval($data['cisloOrientacni'] ?? 0);
      $dest['saHouseNrLetter'] = strval($data['cisloOrientacniPismeno'] ?? '');

    }
    else
    { // číslo evidenční (2)
      $dest['saHouseNr1Type'] = 1; // číslo evidenční
      $dest['saHouseNr1'] = intval($data['cisloDomovni'] ?? 0);
      $dest['saHouseNr'] = strval($dest['saHouseNr1']);
    }

    $dest['saHouseNr'] = '';
    if ($dest['saHouseNr1'] != 0)
    {
      //if ($dest['saHouseNr1Type'] === 1)
      //  $dest['saHouseNr'] .= 'ev.č. ';
      $dest['saHouseNr'] .= strval($dest['saHouseNr1']);
      if ($dest['saHouseNr2'] != 0)
        $dest['saHouseNr'] .= '/'.$dest['saHouseNr2'];
      if ($dest['saHouseNrLetter'] != '')
        $dest['saHouseNr'] .= $dest['saHouseNrLetter'];
    }

    if ($saLaUnit11Ndx)
    {
      $dest['saLaUnit11Ndx'] = $saLaUnit11Ndx;
      $laUnitRec = $this->db()->query('SELECT ndx, laUnitId FROM [services_locAddr_laUnits] WHERE [ndx] = %i', $saLaUnit11Ndx,
                          ' ANd [level] = %i', 11, ' AND [country] = %i', 60 /* CZ */)->fetch();
      if ($laUnitRec)
        $dest['saLaUnit11Id'] = $laUnitRec['laUnitId'];
    }
    if ($saLaUnit10Ndx)
    {
      $dest['saLaUnit10Ndx'] = $saLaUnit10Ndx;
      $laUnitRec = $this->db()->query('SELECT ndx, laUnitId FROM [services_locAddr_laUnits] WHERE [ndx] = %i', $saLaUnit10Ndx,
                          ' ANd [level] = %i', 10, ' AND [country] = %i', 60 /* CZ */)->fetch();
      if ($laUnitRec)
        $dest['saLaUnit10Id'] = $laUnitRec['laUnitId'];
    }

    if (!$dest['standardized'] && $partlyStandardized)
      $dest['standardized'] = 2;

    // -- OLD mode
    $dest['street'] = $dest['saStreetName'] ?? '';
    if ($dest['street'] === '')
      $dest['street'] = $dest['saCityPartName'] ?? '';
    $streetNumber = $dest['saHouseNr'];

    if ($streetNumber !== '')
    {
      if ($dest['street'] !== '')
        $dest['street'] .= ' ';
      if ($dest['saHouseNr1Type'] === 1)
        $dest['street'] .= 'ev.č. ';
      $dest['street'] .= $streetNumber;
    }

    $dest['city'] = $data['saCityName'] ?? '';
    $dest['zipcode'] = $data['psc'] ?? $data['pscTxt'] ?? '';
  }

  function doImport_ARES_Core()
  {
    if ($this->app()->debug)
      echo "* doImport_ARES_Core; ";
    $regData = $this->regData(self::prtCZAresCore, $this->personDataCurrent->personId);
    if (!$regData)
    {
      if ($this->app()->debug > 1)
        echo "ERROR; no ARES regs data found\n";
      return;
    }

    $data = json_decode($regData['srcData'], TRUE);
    //print_r($data);
		if (isset($data['ico']))
		{
			if ($data['ico'] == $this->personDataCurrent->personId)
			{
        $oid = $data['ico'];
        $corePersonInfo = [
          'oid' => $oid,
          'originalName' => Str::upToLen(strval ($data['obchodniJmeno']), 240),
          'fullName' => Str::upToLen($this->clearFullName(strval ($data['obchodniJmeno'])), 240),
        ];

        $flags = $data['seznamRegistraci'] ?? [];
        $this->useRZP = 0;
        if ($flags['stavZdrojeRzp'] ?? '' === 'AKTIVNI')
          $this->useRZP = 1;
        if ($flags['stavZdrojeDph'] === 'AKTIVNI')
          $this->useVAT = self::vatStandard;
        //elseif ($flags[5] === 'S') // "dicSkDph":"N/A"
        //  $this->useVAT = self::vatGroup;


        $this->primaryTAXID = $data['dic'] ?? '';
        if ($this->useVAT === self::vatGroup)
          $this->primaryTAXID = 'CZ'.$oid;

        $corePersonInfo['vatState'] = $this->useVAT;
        if ($this->useVAT === self::vatStandard)
          $corePersonInfo['vatID'] = $data['dic'] ?? '';

        if (isset($data['datumVzniku']))
        {
          $corePersonInfo['validFrom'] = $data['datumVzniku'];
        }

        if (isset($data['datumZaniku']))
        {
          $corePersonInfo['validTo'] = strval($data['datumZaniku']);
        }
        else
          $corePersonInfo['validTo'] = NULL;

        $legalTypeStr = $data['pravniForma'] ?? '';
        $legalTypeRecData = $this->db()->query('SELECT * FROM [e10_base_nomencItems] WHERE [id] = %s', 'cz-tobe-'.$legalTypeStr)->fetch();
        if ($legalTypeRecData)
          $corePersonInfo['natLegalType'] = $legalTypeRecData['ndx'];

        $this->personDataImport->setCoreInfo($corePersonInfo);

        $this->personDataImport->addID(['idType' => self::idtOIDPrimary, 'id' => $oid]);

        $primaryAddress = [
          'addressId' => 'P'.$oid,
          'source' => 1,
          'type' => 0,
        ];
        $this->createAddressARES($data['sidlo'], $primaryAddress);
        $this->personDataImport->addAddress($primaryAddress);
			}
      else
      {
        if ($this->app()->debug)
          echo "ERROR; invalid personId\n";
      }
    }
    else
    {
      if ($this->app()->debug)
        echo "ERROR; data parse\n";
    }

    if ($this->app()->debug)
      echo "OK\n";
  }

  function doImport_ARES_RZP()
  {
    if ($this->app()->debug)
      echo "* doImport_ARES_RZP; ";

    if (!$this->useRZP)
    {
      if ($this->app()->debug)
        echo "disabled\n";

      return;
    }

    $regData = $this->regData(self::prtCZAresRZP, $this->personDataCurrent->personId);
    if (!$regData)
    {
      if ($this->app()->debug)
        echo "ERROR; no regs data found\n";
      return;
    }

    $rzpData = Json::decode($regData['srcData']);
		if (!$rzpData)
		{
      if ($this->app()->debug)
        echo "parse ERROR!\n";
      return;
    }

    if (isset($rzpData['zaznamy']))
    {
      foreach ($rzpData['zaznamy'] as $zaznam)
      {
        if (!isset($zaznam['zivnosti']))
          continue;
        foreach ($zaznam['zivnosti'] as $z)
        {
          if (!isset($z['provozovny']))
            continue;
          foreach ($z['provozovny'] as $provozovna)
          {
            $this->doImport_ARES_RZP_Provozovna($provozovna);
          }
        }
      }
    }

    if ($this->app()->debug)
      echo "\n";
  }

  protected function doImport_ARES_RZP_Provozovna($bb)
  {
    $officeId = strval($bb['icp'] ?? '');
    if ($officeId === '')
    {
      //echo "ERROR: no ICP in RZP provozovna data: ".json_encode($bb)."\n";
      return;
    }
    $officeAddress = [
      'addressId' => 'O'.$officeId,
      'specification' => $bb['umisteniProvozovny'] ?? '',
      'source' => 2,
      'type' => 1, // provozovna
    ];
    $this->createAddressARES($bb['sidloProvozovny'], $officeAddress);

    if (isset($bb['icp']))
      $officeAddress['natId'] = strval($bb['icp']);

    if (isset($bb['platnostOd']))
      $officeAddress['validFrom'] = $bb['platnostOd'];
    if (isset($bb['platnostDo']))
      $officeAddress['validTo'] = $bb['platnostDo'];

    $this->personDataImport->addAddress($officeAddress);
  }

  function doImport_RZP()
  {
    if (!$this->useRZP)
      return;

    $regData = $this->regData(self::prtCZRZP, $this->personDataCurrent->personId);
    if (!$regData)
    {
      return;
    }

    $rzpData = json_decode($regData['srcData'], TRUE);
    if (!$rzpData)
    {
      return;
    }

    // -- provozovny
    if (isset($rzpData['PodnikatelVypis']['PodnikatelDetail']['Provozovny']))
    {
      foreach ($rzpData['PodnikatelVypis']['PodnikatelDetail']['Provozovny'] as $pp)
      {
        $officesList = [];
        if (isset($pp['Provozovna']['IdentifikacniCisloProvozovny']))
          $officesList = [$pp['Provozovna']];
        elseif (isset($pp['IdentifikacniCisloProvozovny']))
          $officesList = [$pp];
        elseif (isset($pp[0]['IdentifikacniCisloProvozovny']))
          $officesList = $pp;
        elseif (isset($pp['Provozovna']))
          $officesList = $pp['Provozovna'];

        foreach ($officesList as $p)
        {
          $officeId = $p['IdentifikacniCisloProvozovny'];
          $addressId = 'O'.$officeId;

          $addrParts = explode(',', $p['ZmenaAdresy']['TextAdresy']);

          if (count($addrParts) === 2)
          {
            $city = $addrParts[1] ?? '';
            $zipcode = $addrParts[0] ?? '';
            if (Str::strlen($zipcode) > 15)
            {
              $street = $zipcode;
              $zipcode = '';
            }
            else
              $street = '';
          }
          else
          {
            $street = $addrParts[0] ?? '';
            $city = $addrParts[2] ?? '';
            $zipcode = $addrParts[1] ?? '';
          }

          $specification = $p['NazevProvozovny']['NazevProvozovny'] ?? '';
          if (isset($p['UmisteniProvozovny']) && $p['UmisteniProvozovny'] !== '')
          {
            if ($specification !== '')
              $specification .= ' - ';
            $specification .= $p['UmisteniProvozovny'];
          }

          $newAddress = [
            'addressId' => $addressId,
            'street' => $street,
            'streetNumber' => '',
            'streetNumber2' => '',
            'city' => $city,
            'zipcode' => Str::upToLen(str_replace(' ', '', $zipcode), 20),
            'specification' => Str::upToLen($specification, 160),
          ];


          if (isset($p['ZahajeniProvozovani']))
          {
            $dp = explode('.', $p['ZahajeniProvozovani']);
            $newAddress['validFrom'] = $dp[2].'-'.$dp[1].'-'.$dp[0];
          }
          if (isset($p['UkonceniCinnosti']))
          {
            $dp = explode('.', $p['UkonceniCinnosti']);
            $newAddress['validTo'] = $dp[2].'-'.$dp[1].'-'.$dp[0];
          }

          $newAddress['source'] = 3;

          $officeAddress = [];
          $this->fillAddress ($newAddress, $officeAddress);

          $officeAddress['natId'] = strval($officeId);
          $officeAddress['type'] = 1;

          if (!isset($this->personDataImport->data['address'][$addressId]))
          {
            $this->personDataImport->addAddress($officeAddress);
          }
          else
          {
            //if ((!isset($this->personDataImport->data['address'][$addressId]) || $this->personDataImport->data['address'][$addressId]['specification'] === '' && $specification !== ''))
            $this->personDataImport->data['address'][$addressId]['specification'] = Str::upToLen($specification, 160);
          }
        }
      }
    }


    // -- ukoncene provozovny
    if (isset($rzpData['PodnikatelVypis']['PodnikatelDetail']['VyporadaniZavazku']))
    {
      if (isset($rzpData['PodnikatelVypis']['PodnikatelDetail']['VyporadaniZavazku']['VyporadaniProvozovna']))
      {
        if (isset($rzpData['PodnikatelVypis']['PodnikatelDetail']['VyporadaniZavazku']['VyporadaniProvozovna']['IdentifikacniCisloProvozovny']))
          $list1 = [$rzpData['PodnikatelVypis']['PodnikatelDetail']['VyporadaniZavazku']['VyporadaniProvozovna']];
        else
          $list1 = $rzpData['PodnikatelVypis']['PodnikatelDetail']['VyporadaniZavazku']['VyporadaniProvozovna'];

        foreach ($list1 as $vpzItem)
        {
          if (isset($vpzItem['IdentifikacniCisloProvozovny']))
          {
            $addressId = 'O'.$vpzItem['IdentifikacniCisloProvozovny'];
            if (!isset($this->personDataImport->data ['address'][$addressId]['addressId']))
            {
              $this->personDataImport->data	['address'][$addressId]['type'] = 1;
              $this->personDataImport->data	['address'][$addressId]['addressId'] = $addressId;
              $this->personDataImport->data	['address'][$addressId]['country'] = 60;
              $this->personDataImport->data	['address'][$addressId]['natId'] = strval($vpzItem['IdentifikacniCisloProvozovny']);
              if (isset($vpzItem['ZmenaAdresy']['TextAdresy']))
                $this->parseOneLineAddress($vpzItem['ZmenaAdresy']['TextAdresy'], $this->personDataImport->data	['address'][$addressId]);
            }

            if (isset($vpzItem['UkonceniCinnosti']))
            {
              if (isset($this->personDataImport->data	['address'][$addressId]))
              {
                $dp = explode('.', $vpzItem['UkonceniCinnosti']);
                $this->personDataImport->data	['address'][$addressId]['validTo'] = $dp[2].'-'.$dp[1].'-'.$dp[0];
              }
            }
            $specification = $vpzItem['NazevProvozovny'] ?? '';
            if (isset($vpzItem['UmisteniProvozovny']) && $vpzItem['UmisteniProvozovny'] !== '')
            {
              if ($specification !== '')
                $specification .= ' - ';
              $specification .= $vpzItem['UmisteniProvozovny'];
            }
            if ($specification !== '')
              $this->personDataImport->data	['address'][$addressId]['specification'] = Str::upToLen($specification, 160);
          }
        }
      }
      else
      {
        foreach ($rzpData['PodnikatelVypis']['PodnikatelDetail']['VyporadaniZavazku'] as $vpz)
        {
          foreach ($vpz as $vpzItem)
          {
            if (isset($vpzItem['IdentifikacniCisloProvozovny']))
            {
              $addressId = 'O'.$vpzItem['IdentifikacniCisloProvozovny'];
              if (isset($vpzItem['UkonceniCinnosti']))
              {
                if (isset($this->personDataImport->data	['address'][$addressId]))
                {
                  $dp = explode('.', $vpzItem['UkonceniCinnosti']);
                  $this->personDataImport->data	['address'][$addressId]['validTo'] = $dp[2].'-'.$dp[1].'-'.$dp[0];
                }
              }
              $specification = $vpzItem['NazevProvozovny'] ?? '';
              if (isset($vpzItem['UmisteniProvozovny']) && $vpzItem['UmisteniProvozovny'] !== '')
              {
                if ($specification !== '')
                  $specification .= ' - ';
                $specification .= $vpzItem['UmisteniProvozovny'];
              }
              if ($specification !== '')
                $this->personDataImport->data	['address'][$addressId]['specification'] = Str::upToLen($specification, 160);
            }
            elseif (isset($vpzItem[0]['IdentifikacniCisloProvozovny']))
            {
              foreach ($vpzItem as $vpzItem2)
              {
                if (!isset($vpzItem2['IdentifikacniCisloProvozovny']) || !isset($vpzItem2['UkonceniCinnosti']))
                  continue;

                $addressId = 'O'.$vpzItem2['IdentifikacniCisloProvozovny'];
                if (isset($this->personDataImport->data	['address'][$addressId]))
                {
                  $dp = explode('.', $vpzItem2['UkonceniCinnosti']);
                  $this->personDataImport->data	['address'][$addressId]['validTo'] = $dp[2].'-'.$dp[1].'-'.$dp[0];
                }
              }
            }
          }
        }
      }
    }
  }

  function doImport_VAT()
  {
    if ($this->useVAT === self::vatNone)
      return;

    if ($this->useVAT === self::vatGroup && $this->personDataCurrent->data['person']['vatID'] === '')
      return;

    $regData = $this->regData(self::prtCZVAT, $this->personDataCurrent->personId);
    if (!$regData)
    {
      return;
    }

    $vatData = json_decode($regData['srcData'], TRUE);
    if (!$vatData)
    {
      return;
    }

    //$this->srcData['VAT']['nespolehlivyPlatce'] = intval($vatData['statusPlatceDPH']['nespolehlivyPlatce'] !== 'NE');

    /*
    $primaryVatIDRec = $this->personDataImport->data['ids'][1] ?? NULL;
    if ($primaryVatIDRec)
    {
      $this->personDataImport->data['ids'][1]['validFrom'] =
    }
    */

    if (isset($vatData['statusPlatceDPH']['zverejneneUcty']))
    {
      $bankAccounts = isset($vatData['statusPlatceDPH']['zverejneneUcty']['ucet']['datumZverejneni']) ? [$vatData['statusPlatceDPH']['zverejneneUcty']['ucet']] : $vatData['statusPlatceDPH']['zverejneneUcty']['ucet'];
      foreach ($bankAccounts as $ba)
      {
        $bankAccount = ['validFrom' => $ba['datumZverejneni']];
        if (isset($ba['nestandardniUcet']))
          $bankAccount['bankAccount'] = $ba['nestandardniUcet']['cislo'];
        elseif (isset($ba['standardniUcet']))
        {
          $bankAccount['bankAccount'] = '';
          if (isset($ba['standardniUcet']['predcisli']) && $ba['standardniUcet']['predcisli'] !== '')
            $bankAccount['bankAccount'] .= $ba['standardniUcet']['predcisli'].'-';
          $bankAccount['bankAccount'] .= $ba['standardniUcet']['cislo'].'/'.$ba['standardniUcet']['kodBanky'];
        }
        else
          continue;

        $bankAccount['bankAccount'] = Str::upToLen($bankAccount['bankAccount'], 40);
        $this->personDataImport->addBankAccount($bankAccount);
      }
    }
    $regVatId = $vatData['statusPlatceDPH']['dic'] ?? '';
    if ($regVatId !== '')
      $this->personDataImport->addID(['idType' => self::idtVATPrimary, 'id' => $regVatId]);
  }

  function clearFullName ($originalName)
	{
		$s = str_replace('"', '', $originalName);
		$s = str_replace("'", '', $s);
		$s = preg_replace("/ {4,}/", " ", $s);

		if (str_starts_with($s, ',,'))
			$s = substr($s, 2);
		if (str_ends_with($s, ",,"))
			$s = substr($s, 0, -2);
		if (str_ends_with($s, "´´"))
			$s = Str::substr($s, 0, -2);
		$s = trim ($s);

		// -- check words with spaces
		$newString = '';

		$wp = mb_str_split($s, 1, 'UTF-8');
		$pos = 0;
		$len = count($wp);
		$disableSpaceCheck = 0;
		while ($pos < $len)
		{
			if (isset($wp[5]) && $wp[5] === ' ')
			{
				if (!$disableSpaceCheck && isset($wp[$pos + 3]) && $wp[$pos + 1] === ' ' && $wp[$pos + 3] === ' ')
				{
					$newString .= $wp[$pos];
					$newString .= $wp[$pos + 2];
					if ($wp[$pos + 2] === ',')
						$newString .= ' ';
					$pos += 4;
					continue;
				}
			}

			$disableSpaceCheck = 1;
			$newString .= $wp[$pos];
			$pos++;
		}
		$s = str_replace("-", '', $s);
		$s = preg_replace("/ {2,}/", " ", $newString);
		$s = trim($s);

		return $s;
	}

  protected function parseOneLineAddress($addText, &$dest)
  {
    $addrParts = explode(',', $addText);

    if (count($addrParts) === 2)
    {
      $dest['city'] = trim($addrParts[1] ?? '');
      $dest['zipcode'] = trim($addrParts[0] ?? '');
      $dest['street'] = '';

      if (Str::strlen($dest['zipcode']) > 15)
      {
        $dest['street'] = $dest['zipcode'];
        $dest['zipcode'] = '';
      }
    }
    else
    {
      $dest['street'] = trim($addrParts[0] ?? '');
      $dest['city'] = trim($addrParts[2] ?? '');
      $dest['zipcode'] = trim($addrParts[1] ?? '');
    }

    if (isset($dest['zipcode']))
      $dest['zipcode'] = str_replace(' ', '', trim($dest['zipcode']));
  }

  protected function doImport()
  {
    if ($this->app()->debug)
      echo "* doImport\n";
    $this->doImport_ARES_Core();
    $this->doImport_ARES_RZP();
    $this->doImport_RZP();
    $this->doImport_VAT();
  }

  public function saveChanges()
  {
    $this->personDataCurrent->saveChanges($this->personDataImport, $this->logRecord);
  }

  public function run()
  {
    parent::run();

    $this->doImport();

    //print_r($this->personDataImport->data);

    $this->saveChanges();

    $this->logRecord->setStatus(LogRecord::lstInfo);
    $this->logRecord->save();
  }
}
