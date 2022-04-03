<?php

namespace services\persons\libs\cz;
use \Shipard\Utils\Json, \Shipard\Utils\Utils;

class OnlinePersonRegsDownloaderCZ extends \services\persons\libs\OnlinePersonRegsDownloader
{
  function loadARESCore()
  {
		$downloadUrl = 'http://wwwinfo.mfcr.cz/cgi-bin/ares/darv_bas.cgi?ico=';
		$file = @file_get_contents ($downloadUrl . $this->personId);

		if ($file)
			$xml = @simplexml_load_string ($file);
		if (isset($xml) && $xml)
		{
			$ns = $xml->getDocNamespaces();
			$data = $xml->children($ns['are']);
			$el = $data->children($ns['D'])->VBAS;
			if (strval($el->ICO) == $this->personId)
			{
				$this->srcData['aresCore']['personId'] = strval ($el->ICO);
				$this->srcData['aresCore']['vatId'] = strval ($el->DIC);
				$this->srcData['aresCore']['fullName'] = strval ($el->OF);
        $aresData = json_decode (json_encode($el), TRUE);
        $this->addAddress($aresData['AA'], $this->srcData['aresCore']);
				$this->srcData['aresCore']['state'] = 'ok';

        $this->saveRegisterData ($this->personData->data['ndx'], self::prtCZAresCore, $file, sha1($file), $this->personId);
			}
			else
			{
				$this->srcData['aresCore']['state'] = 'nonex';
			}
		}
		else
		{
			$this->srcData['aresCore']['state'] = 'error';
		}
  }

  function loadARESRzp()
  {
    $url = 'https://wwwinfo.mfcr.cz/cgi-bin/ares/darv_rzp.cgi?rozsah=2';
    $url .= '&ico='.$this->personId;

    $file = @file_get_contents ($url);

		if (!$file)
    {
      echo "load error!\n";
      $this->srcData['RZP']['state'] = 'error';
      return;
    }
		
    $xml = @simplexml_load_string ($file);
		if (!$xml)
		{
      echo "parse error!\n";
      $this->srcData['RZP']['state'] = 'error';
      return;
    }

    $this->saveRegisterData ($this->personData->data['ndx'], self::prtCZAresRZP, $file, sha1($file), $this->personId);

    /*
    $ns = $xml->getDocNamespaces();
    $data = $xml->children($ns['are']);
    $el = $data->children($ns['D'])->Vypis_RZP;
		$rzpData = json_decode (json_encode($el), TRUE);


    //echo Json::lint($rzpData)."\n";
    //print_r ($srcData);


    $this->srcData['RZP']['fullName'] = $rzpData['ZAU']['OF'];

    // -- address
    foreach ($rzpData['Adresy'] as $addrId => $addr)
    {
      $this->addAddress ($addr, $this->srcData['RZP']);
      break;
    }

    // -- provozovny
    foreach ($rzpData['ZI']['Z'] as $aaId => $aa)
    {
      foreach ($aa['PRY'] as $bbId => $bb)
      {
        //  {"Zahajeni":"2015-01-05","Typ_provozovny":"6","AP":{"IDA":"40613897","N":"Zl\u00edn","NCO":"Pr\u0161tn\u00e9","NU":"Jate\u010dn\u00ed","PSC":"76001"},"ICP":"1010063481"}
        //echo " ### ".json_encode($bb)."\n";
        $office = [];
        $officeId = $bb['ICP'] ?? $bbId;
        if (isset($this->srcData['RZP']['offices'][$officeId]))
          continue;
        $this->addAddress ($bb['AP'], $office);
        if (isset($bb['ICP']))
          $office['natId'] = $bb['ICP'];
        if (isset($bb['Zahajeni']))
          $office['validFrom'] = $bb['Zahajeni'];
        $this->srcData['RZP']['offices'][$officeId] = $office;
      }
    }
    */
  }

  function loadVAT()
  {
    $vatId = $this->srcData['aresCore']['vatId'] ?? '';
    if ($vatId === '')
      return;

    $client = new \SoapClient('http://adisrws.mfcr.cz/adistc/axis2/services/rozhraniCRPDPH.rozhraniCRPDPHSOAP?wsdl');
		$response = $client->__soapCall('getStatusNespolehlivyPlatce', [0 => [$vatId]]);

    $vatData = json_decode(json_encode($response), TRUE);

    if (!isset($vatData['statusPlatceDPH']))
    {
      return;
    }

    $jsonDataStr = Json::lint($vatData);
    $this->saveRegisterData ($this->personData->data['ndx'], self::prtCZVAT, $jsonDataStr, sha1($jsonDataStr), $this->personId);

    /*
    $this->srcData['VAT']['vatId'] = $vatData['statusPlatceDPH']['dic'];
    $this->srcData['VAT']['nespolehlivyPlatce'] = intval($vatData['statusPlatceDPH']['nespolehlivyPlatce'] !== 'NE');

    foreach ($vatData['statusPlatceDPH']['zverejneneUcty']['ucet'] as $ba)
    {
      echo ' ==> '.json_encode($ba)."\n";
      //==> {"standardniUcet":{"cislo":"6220012","kodBanky":"0800"},"datumZverejneni":"2014-10-21"}
      //==> {"nestandardniUcet":{"cislo":"CZ7920100000002801278933"},"datumZverejneni":"2017-09-05"}

      $bankAccount = ['validFrom' => $ba['datumZverejneni']];
      if (isset($ba['nestandardniUcet']))
        $bankAccount['bankAccount'] = $ba['nestandardniUcet']['cislo'];
       elseif (isset($ba['standardniUcet']))
        $bankAccount['bankAccount'] = $ba['standardniUcet']['cislo'].'/'.$ba['standardniUcet']['kodBanky'];
       else
        continue; 

      $this->srcData['VAT']['bankAccounts'][] = $bankAccount;  
    }
    */
    //print_r($vatData);
  }

  function loadRZP()
  {
    $qryString = "";
    $qryString .= "<?xml version=\"1.0\" encoding=\"ISO-8859-2\"?>\n";
    $qryString .="<VerejnyWebDotaz \n xmlns=\"urn:cz:isvs:rzp:schemas:VerejnaCast:v1\" \n version=\"2.8\">\n";
    $qryString .= "<Kriteria>\n";
    $qryString .= "<IdentifikacniCislo>".$this->personId."</IdentifikacniCislo>\n";
    $qryString .= "<PlatnostZaznamu>0</PlatnostZaznamu>\n";
    $qryString .= "</Kriteria>\n";
    $qryString .= "</VerejnyWebDotaz>\n";

    
    $queryfileName = Utils::tmpFileName('xml');
    file_put_contents($queryfileName, $qryString);


    $uploadUrl = 'https://www.rzp.cz/cgi-bin/aps_cacheWEB.sh';
    $post = ['VSS_SERV' => 'ZVWSBJXML', 'filename' => curl_file_create($queryfileName, 'text/xml', 'dotaz.xml')];
		$ch = curl_init();
		curl_setopt ($ch, CURLOPT_HEADER, 0);
		curl_setopt ($ch, CURLOPT_URL, $uploadUrl);
		curl_setopt ($ch, CURLOPT_VERBOSE, 0);
		curl_setopt ($ch, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data']);
    curl_setopt ($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible;)");
		curl_setopt ($ch, CURLOPT_POST, 1);
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt ($ch, CURLOPT_POSTFIELDS, $post);
		$result = curl_exec ($ch);
		curl_close ($ch);
    unset ($ch);

    //print_r($result);

    $xml = new \SimpleXMLElement($result);
    $xmlData = json_decode(json_encode($xml), TRUE);
    $podnikatelID = $xmlData['PodnikatelSeznam']['PodnikatelID'] ?? '';
    if ($podnikatelID === '')
    {
      return;
    }
    //echo "TEST: !$podnikatelID! `\n".Json::lint($xmlData)."`\n";

    $qryString = "";
    $qryString .= "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $qryString .="<VerejnyWebDotaz \n xmlns=\"urn:cz:isvs:rzp:schemas:VerejnaCast:v1\" \n version=\"2.8\">\n";
    $qryString .= "<PodnikatelID>".$podnikatelID."</PodnikatelID>\n";
    $qryString .= "<Historie>0</Historie>\n";
    $qryString .= "</VerejnyWebDotaz>\n";

    $queryfileName = Utils::tmpFileName('xml');
    file_put_contents($queryfileName, $qryString);

    $uploadUrl = 'https://www.rzp.cz/cgi-bin/aps_cacheWEB.sh';
    $post = ['VSS_SERV' => 'ZVWSBJXML', 'filename' => curl_file_create($queryfileName, 'text/xml', 'dotaz2.xml')];
		$ch = curl_init();
		curl_setopt ($ch, CURLOPT_HEADER, 0);
		curl_setopt ($ch, CURLOPT_URL, $uploadUrl);
		curl_setopt ($ch, CURLOPT_VERBOSE, 0);
		curl_setopt ($ch, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data']);
    curl_setopt ($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible;)");
		curl_setopt ($ch, CURLOPT_POST, 1);
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt ($ch, CURLOPT_POSTFIELDS, $post);
		$result = curl_exec ($ch);
		curl_close ($ch);

    //echo $result."\n";

    $xml = new \SimpleXMLElement($result);
    $xmlData = json_decode(json_encode($xml), TRUE);
    $jsonDataStr = Json::lint($xmlData);

    $this->saveRegisterData ($this->personData->data['ndx'], self::prtCZRZP, $jsonDataStr, sha1($jsonDataStr), $this->personId);
  }

  function addAddress ($src, &$dest)
  {
    $street = $src['NU'];
    if ($street === '')
      $street = $src['NU'];
     if (isset($src['CD'])) 
      $dest['street'] = $street . ' ' . $src['CD'];
     else
      $dest['street'] = $street;
    if (isset($src['CO']) && $src['CO'] !== '')
      $dest['street'] .= '/' . $src['CO'];

    $dest['city']= $src['N'];
    $dest['zipcode']= $src['PSC'];
  }

  function loadFromRegisters ()
  {
     $this->loadARESCore();
     $this->loadARESRzp();
     $this->loadRZP();
     $this->loadVAT();

     print_r($this->srcData);
  }

  protected function saveRegisterData ($personNdx, $regType, string $regData, string $checkSum, string $subId)
  {
    $exist = $this->db()->query('SELECT * FROM [services_persons_regsData] WHERE [person] = %i', $personNdx, 
    ' AND [subId] = %s', $subId, ' AND [regType] = %i', $regType)->fetch();

    if (!$exist)
    {
      $insert = [
        'person' => $personNdx, 
        'regType' => $regType, 
        'subId' => $subId, 
        'srcData' => $regData, 
        'timeUpdated' => new \DateTime(),
        'srcDataCheckSum' => $checkSum,
        'imported' => 0,
        'importedCheckSum' => '',
      ];
      $this->db()->query ('INSERT INTO [services_persons_regsData]', $insert);
    }
    else
    {
      $update = [
        'srcData' => $regData, 
        'timeUpdated' => new \DateTime(),
        'srcDataCheckSum' => $checkSum,
        'imported' => 0,
      ];
      $this->db()->query ('UPDATE [services_persons_regsData] SET ', $update, ' WHERE [ndx] = %i', $exist['ndx']);
    }

    /*
    {"id": "person", "name": "Osoba", "type": "int"},
		{"id": "regType", "name": "Registr", "type": "enumInt", "len": 2,
		  "enumCfg": {"cfgItem": "services.persons.registers", "cfgValue": "", "cfgText": "name"}},
		{"id": "subId", "name": "ID", "type": "string", "len": 20, "options": ["ascii"]},
		{"id": "srcData", "name": "Zdrojová data", "type": "memo"},

		{"id": "imported", "name": "Naimportováno", "type": "logical"},
		{"id": "timeUpdated", "name": "Poslední aktualizace", "type": "timestamp"},

		{"id": "srcDataCheckSum", "name": "Kontrolní součet stažených dat", "type": "string", "len": 40, "options": ["ascii"]},
		{"id": "importedCheckSum", "name": "Kontrolní součet importovaných dat", "type": "string", "len": 40, "options": ["ascii"]}
    */
  }

  public function run()
  {
    parent::run();

    if (!$this->personData->data)
    {
      return;
    }

    $this->loadFromRegisters();
  }
}
