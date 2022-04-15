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
    $dest['specification'] = trim($data['specification'] ?? '');

    $dest['country'] = $data['country'] ?? 60; // CZ
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
          'originalName' => strval ($el->OF),
          'fullName' => $this->clearFullName(strval ($el->OF)),
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
      if (isset($aa['PRY']))
      {
        foreach ($aa['PRY'] as $bbId_1 => $bb_1)
        {
          foreach ($bb_1 as $bbId => $bb)
          {
            if (!isset($bb['ICP']) || $bb['ICP'] === '')
              continue;
            $officeId = $bb['ICP'] ?? $bbId;

            $officeAddress = [];
            $this->fillAddress ([
                'addressId' => 'O'.$officeId,
                'street' => $bb['AP']['NU'] ?? '',
                'streetNumber' => $bb['AP']['CD'] ?? '',
                'streetNumber2' => $bb['AP']['CO'] ?? '',
                'city' => $bb['AP']['N'] ?? '',
                'zipcode' => $bb['AP']['PSC'] ?? '',
                'specification' => $bb['AP']['NPR'] ?? '',
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
        }
      }  
    }
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
        elseif (isset($pp['Provozovna']))
          $officesList = $pp['Provozovna'];
          
        foreach ($officesList as $p)
        {
          $officeId = $p['IdentifikacniCisloProvozovny'];
          $addressId = 'O'.$officeId;
          $addrParts = explode(',', $p['ZmenaAdresy']['TextAdresy']);

          $officeAddress = [];
          $this->fillAddress ([
              'addressId' => $addressId,
              'street' => $addrParts[0] ?? '',
              'streetNumber' => '',
              'streetNumber2' => '',
              'city' => $addrParts[2] ?? '',
              'zipcode' => $addrParts[1] ?? '',
              'specification' => $p['NazevProvozovny'] ?? '',
            ], $officeAddress);
          
          $officeAddress['natId'] = $officeId;
          $officeAddress['type'] = 1;

          if (!isset($this->personDataImport->data['address'][$addressId]))
            $this->personDataImport->addAddress($officeAddress);
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
