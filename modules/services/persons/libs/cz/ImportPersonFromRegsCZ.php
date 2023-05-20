<?php

namespace services\persons\libs\cz;

use services\persons\libs\ImportPersonFromRegs;
use \Shipard\Utils\Utils ,\Shipard\Utils\Str;
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
  }

  function doImport_ARES_Core()
  {
    $regData = $this->regData(self::prtCZAresCore, $this->personDataCurrent->personId);
    if (!$regData)
    {
      return;
    }

		$xml = @simplexml_load_string ($regData['srcData']);
		if (isset($xml) && $xml)
		{
			$ns = $xml->getDocNamespaces();
			$data = $xml->children($ns['are']);
			$el = $data->children($ns['D'])->VBAS;
			if (strval($el->ICO) == $this->personDataCurrent->personId)
			{
        $oid = strval ($el->ICO);
        $corePersonInfo = [
          'oid' => $oid,
          'originalName' => Str::upToLen(strval ($el->OF), 240),
          'fullName' => Str::upToLen($this->clearFullName(strval ($el->OF)), 240),
        ];

        $flags = strval ($el->PSU);
        if ($flags[3] === 'A')
          $this->useRZP = 1;
        if ($flags[3] === 'A')
          $this->useRZP = 1;
        if ($flags[5] === 'A')
          $this->useVAT = self::vatStandard;
        elseif ($flags[5] === 'S')
          $this->useVAT = self::vatGroup;

        $this->primaryTAXID = strval($el->DIC);
        if ($this->useVAT === self::vatGroup)
          $this->primaryTAXID = 'CZ'.$oid;

        $corePersonInfo['vatState'] = $this->useVAT;
        if ($this->useVAT === self::vatStandard)
          $corePersonInfo['vatID'] = strval($el->DIC);

        if (isset($el->DV))
        {
          $corePersonInfo['validFrom'] = strval($el->DV);
        }

        if (isset($el->DZ))
        {
          $corePersonInfo['validTo'] = strval($el->DZ);
        }

        $legalTypeStr = strval($el->PF->KPF);
        $legalTypeRecData = $this->db()->query('SELECT * FROM [e10_base_nomencItems] WHERE [id] = %s', 'cz-tobe-'.$legalTypeStr)->fetch();
        if ($legalTypeRecData)
          $corePersonInfo['natLegalType'] = $legalTypeRecData['ndx'];

        $this->personDataImport->setCoreInfo($corePersonInfo);

        $this->personDataImport->addID(['idType' => self::idtOIDPrimary, 'id' => $oid]);

        $primaryAddress = [];
        $this->fillAddress ([
            'addressId' => 'P'.$oid,
            'street' => strval ($el->AA->NU),
            'streetNumber' => strval($el->AA->CD),
            'streetNumber2' => strval($el->AA->CO),
            'city' => strval ($el->AA->N),
            'zipcode' => strval ($el->AA->PSC),
          ], $primaryAddress);

        $primaryAddress['type'] = 0;

        $this->personDataImport->addAddress($primaryAddress);
			}
      else
      {

      }
    }
  }

  function doImport_ARES_RZP()
  {
    if (!$this->useRZP)
      return;

    $regData = $this->regData(self::prtCZAresRZP, $this->personDataCurrent->personId);
    if (!$regData)
    {
      return;
    }

    $xml = @simplexml_load_string ($regData['srcData']);
		if (!$xml)
		{
      echo "parse error!\n";
      return;
    }

    $ns = $xml->getDocNamespaces();
    $data = $xml->children($ns['are']);
    $el = $data->children($ns['D'])->Vypis_RZP;
		$rzpData = json_decode (json_encode($el), TRUE);

    if (!isset($rzpData['Adresy']))
    {
      //echo "invalid ARES-RZP data!\n";
      return;
    }

    // -- primary address
    foreach ($rzpData['Adresy'] as $addrId => $addr)
    {
      //$this->addAddress ($addr, $this->srcData['RZP']);
      break;
    }

    // -- provozovny
    foreach ($rzpData['ZI']['Z'] as $aaId => $aa)
    {
      if (isset($aa['PRY']['PR']['ICP']))
      {
        $this->doImport_ARES_RZP_Provozovna($aa['PRY']['PR']);
        continue;
      }
      if (isset($aa['PRY']))
      {
        foreach ($aa['PRY'] as $bbId_1 => $bb_1)
        {
          foreach ($bb_1 as $bbId => $bb)
          {
            if (!isset($bb['ICP']) || $bb['ICP'] === '')
              continue;
            $this->doImport_ARES_RZP_Provozovna($bb);
          }
        }
      }
    }
  }

  protected function doImport_ARES_RZP_Provozovna($bb)
  {
    $officeId = strval($bb['ICP']);

    $officeAddress = [];
    $this->fillAddress ([
        'addressId' => 'O'.$officeId,
        'street' => $bb['AP']['NU'] ?? '',
        'streetNumber' => $bb['AP']['CD'] ?? '',
        'streetNumber2' => $bb['AP']['CO'] ?? '',
        'city' => $bb['AP']['N'] ?? '',
        'zipcode' => $bb['AP']['PSC'] ?? '',
        'specification' => $bb['NPR'] ?? '',
      ], $officeAddress);

    $officeAddress['type'] = 1;

    if (isset($bb['ICP']))
      $officeAddress['natId'] = $bb['ICP'];

    if (isset($bb['Zahajeni']))
      $officeAddress['validFrom'] = $bb['Zahajeni'];
    if (isset($bb['Ukonceni']))
      $officeAddress['validTo'] = $bb['Ukonceni'];

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
            $street = '';
          }
          else
          {
            $street = $addrParts[0] ?? '';
            $city = $addrParts[2] ?? '';
            $zipcode = $addrParts[1] ?? '';
          }

          $specification = $p['NazevProvozovny'] ?? '';
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

          $officeAddress = [];
          $this->fillAddress ($newAddress, $officeAddress);

          $officeAddress['natId'] = $officeId;
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
              $this->personDataImport->data	['address'][$addressId]['natId'] = $vpzItem['IdentifikacniCisloProvozovny'];
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
