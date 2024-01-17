<?php

namespace services\persons\libs\cz;
use \Shipard\Utils\Json, \Shipard\Utils\Utils;
use \services\persons\libs\LogRecord;

/**
 * @class OnlinePersonRegsDownloaderCZ
 */
class OnlinePersonRegsDownloaderCZ extends \services\persons\libs\OnlinePersonRegsDownloader
{
  var $primaryTAXID = '';

  CONST vatNone = 0, vatStandard = 1, vatGroup = 2;
  var $useVAT = self::vatNone;
  var $useRZP = 0;

  /**
   * https://wwwinfo.mfcr.cz/ares/ares_xml_basic.html.cz
   */
  function loadARESCore()
  {
    if ($this->app()->debug)
      echo "  * loadARESCore; ";

    $logRecord = $this->log->newLogRecord();
    $logRecord->init(LogRecord::liDownloadRegisterData, 'services.persons.persons', $this->personNdx);

		$downloadUrl = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/'.$this->personData->personId;
    $logRecord->addItem('ares-download-init', '', ['url' => $downloadUrl]);

    if ($this->app()->debug)
      echo "  url: `{$downloadUrl}`; ";

    $file = @file_get_contents ($downloadUrl);

    if (!$file)
    {
      $logRecord->addItem('ares-download-failed', '', ['result' => $file]);
      if ($this->app()->debug)
      echo "FAILED; ";
    }
		else
    {
			$data = json_decode($file, TRUE);
      if ($data)
      {
        if ($data['ico'] == $this->personData->personId)
        {
          $flags = $data['seznamRegistraci'] ?? [];
          if ($flags['stavZdrojeRzp'] ?? '' === 'AKTIVNI')
            $this->useRZP = 1;
          //if ($flags[3] === 'A')
          //  $this->useRZP = 1;
          if ($flags['stavZdrojeDph'] === 'AKTIVNI')
            $this->useVAT = self::vatStandard;
          //elseif ($flags[5] === 'S') // "dicSkDph":"N/A"
          //  $this->useVAT = self::vatGroup;

          $this->primaryTAXID = $data['dic'] ?? '';
          if ($this->useVAT === self::vatGroup)
            $this->primaryTAXID = 'CZ'.$this->personData->personId;

          $this->saveRegisterData ($this->personNdx, self::prtCZAresCore, Json::lint($data), sha1($file), $this->personData->personId);
          $logRecord->setStatus(LogRecord::lstInfo);

          if ($this->app()->debug)
            echo "OK; ";
        }
        else
        {
          $logRecord->addItem('ares-download-invalid-id', '', ['xml-text' => $file]);
          $logRecord->setStatus(LogRecord::lstError);
        }
      }
      else
      {
        $logRecord->addItem('ares-download-parse-error', '', ['xml-text' => $file]);
        $logRecord->setStatus(LogRecord::lstError);
      }
    }
    $logRecord->save();
    if ($this->app()->debug)
      echo "\n";
  }

  function loadARESRzp()
  {
    if ($this->app()->debug)
      echo "  * loadARESRzp; ";

    if (!$this->useRZP)
    {
      if ($this->app()->debug)
        echo "  DISABLED\n";
      return;
    }
    $logRecord = $this->log->newLogRecord();
    $logRecord->init(LogRecord::liDownloadRegisterData, 'services.persons.persons', $this->personNdx);

    $url = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty-rzp/'.$this->personData->personId;
    if ($this->app()->debug)
      echo "  url: `{$url}`; ";

    $logRecord->addItem('ares-rzp-download-init', '', ['url' => $url]);
    $file = @file_get_contents ($url);

		if (!$file)
    {
      if ($this->app()->debug)
        echo "ERROR-DOWNLOAD-FAILED; ";
      $logRecord->addItem('ares-rzp-download-failed', '', ['result' => $file]);
      $logRecord->setStatus(LogRecord::lstError, TRUE);
      return;
    }

    $data = json_decode($file, TRUE);
		if (!$data)
		{
      if ($this->app()->debug)
        echo "ERROR-PARSE; ";

      $logRecord->addItem('ares-rzp-download-parse-error', '', ['json-text' => $file]);
      $logRecord->setStatus(LogRecord::lstError, TRUE);
      return;
    }

    if ($this->app()->debug)
      echo "OK; ";

    $this->saveRegisterData ($this->personNdx, self::prtCZAresRZP, Json::lint($data), sha1($file), $this->personData->personId);

    $logRecord->setStatus(LogRecord::lstInfo, TRUE);

    if ($this->app()->debug)
      echo "\n";
  }


  /**
   * https://adisspr.mfcr.cz/pmd/dokumentace/webove-sluzby-spolehlivost-platcu
   */
  function loadVAT()
  {
    if ($this->app()->debug)
      echo "  * loadVAT; ";

    if ($this->useVAT === self::vatNone)
    {
      if ($this->app()->debug)
        echo "  DISABLED\n";
      return;
    }
    $vatId = $this->primaryTAXID;
    if ($this->useVAT === self::vatGroup)
    {
      $vatId = $this->personData->data['person']['vatID'];
    }

    if ($vatId === '')
    {
      if ($this->app()->debug)
        echo "  ERROR; vatId is blank\n";
      return;
    }

    $logRecord = $this->log->newLogRecord();
    $logRecord->init(LogRecord::liDownloadRegisterData, 'services.persons.persons', $this->personNdx);
    $logRecord->addItem('cz-vat-download-init', '', []);

    if ($this->app()->debug)
      echo "vatId: `$vatId`; ";

    try {
      $client = new \SoapClient('https://adisrws.mfcr.cz/adistc/axis2/services/rozhraniCRPDPH.rozhraniCRPDPHSOAP?wsdl');
      $response = $client->__soapCall('getStatusNespolehlivyPlatceRozsireny', [0 => [$vatId]]);

      //print_r($response);
    }
    catch (\Exception $e)
    {
      $logRecord->addItem('cz-vat-download-failed', '', ['msg' => $e->getMessage()]);
      $logRecord->setStatus(LogRecord::lstError, TRUE);
      if ($this->app()->debug)
        echo "ERROR; download failed\n";

      return;
    }
    $vatData = json_decode(json_encode($response), TRUE);

    if (!isset($vatData['statusPlatceDPH']))
    {
      $logRecord->addItem('cz-vat-invalid-content', '', ['data' => $vatData]);
      $logRecord->setStatus(LogRecord::lstError, TRUE);
      if ($this->app()->debug)
        echo "ERROR; invalid content\n";
      return;
    }

    $jsonDataStr = Json::lint($vatData);
    $this->saveRegisterData ($this->personNdx, self::prtCZVAT, $jsonDataStr, sha1($jsonDataStr), $this->personData->personId);

    if ($this->app()->debug)
      echo "OK; ";

    $logRecord->setStatus(LogRecord::lstInfo, TRUE);

    if ($this->app()->debug)
      echo "\n";
  }

  function loadRZP()
  {
    if ($this->app()->debug)
      echo "  * loadRZP; ";

    if (!$this->useRZP)
    {
      if ($this->app()->debug)
        echo "  DISABLED\n";
      return;
    }

    $logRecord = $this->log->newLogRecord();
    $logRecord->init(LogRecord::liDownloadRegisterData, 'services.persons.persons', $this->personNdx);
    $logRecord->addItem('rzp-download-init', '', []);

    $qryString = "";
    $qryString .= "<?xml version=\"1.0\" encoding=\"ISO-8859-2\"?>\n";
    $qryString .="<VerejnyWebDotaz \n xmlns=\"urn:cz:isvs:rzp:schemas:VerejnaCast:v1\" \n version=\"2.8\">\n";
    $qryString .= "<Kriteria>\n";
    $qryString .= "<IdentifikacniCislo>".$this->personData->personId."</IdentifikacniCislo>\n";
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
      $logRecord->addItem('rzp-invalid-content', 'Invalid `podnikatelID` value', ['xml-data' => $result]);
      $logRecord->setStatus(LogRecord::lstError, TRUE);
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

    $this->saveRegisterData ($this->personNdx, self::prtCZRZP, $jsonDataStr, sha1($jsonDataStr), $this->personData->personId);

    $logRecord->setStatus(LogRecord::lstInfo, TRUE);

    if ($this->app()->debug)
      echo "\n";
  }

  function addAddress ($src, &$dest)
  {
    $street = $src['NU'] ?? '';
    if ($street === '')
      $street = $src['NCO'] ?? '';
     if (isset($src['CD']))
      $dest['street'] = $street . ' ' . $src['CD'];
     else
      $dest['street'] = $street;
    if (isset($src['CO']) && $src['CO'] !== '')
      $dest['street'] .= '/' . $src['CO'];

    $dest['city']= $src['N'];
    $dest['zipcode']= $src['PSC'] ?? '';
  }

  function loadFromRegisters ()
  {
    $this->loadARESCore();
    $this->loadARESRzp();
    $this->loadRZP();
    $this->loadVAT();

    if ($this->cntUpdates)
    {
      $this->db()->query('UPDATE [services_persons_persons] SET [newDataAvailable] = %i', $this->newDataAvailable, ' WHERE [ndx] = %i', $this->personData->data['ndx']);
    }
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

    $this->cntUpdates++;
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
