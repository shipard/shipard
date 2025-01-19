<?php

namespace services\locAddr\libs\imports\cz;
use \Shipard\Utils\Wgs84;


/**
 * class ImportEngineCZ
 */
class ImportEngineCZ extends \services\locAddr\libs\imports\ImportEngineCore
{
  var $resDir = '';

  public function init()
  {
    $this->resDir = 'res/locAddr-cz';
    if (!is_dir($this->resDir))
    {
      mkdir($this->resDir, 0770, true);
    }
  }

  protected function importLAUnits0()
  { // Okresy
		$cnt = 0;
    $file = fopen($this->resDir.'/'.'UI_OKRES_UTF8.csv', "r");

    while ($cols = fgetcsv($file, null, ';'))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      $existedUnit = $this->db()->query('SELECT * FROM [services_locAddr_laUnits] WHERE [laUnitId] = %i', $cols['0'],
                                        ' AND [level] = %i', 0, ' AND [country] = %i', 60)->fetch();

      if (!$existedUnit)
      {
        $ownerLAUnit1 = $this->db()->query('SELECT * FROM [services_locAddr_laUnits] WHERE [laUnitId] = %i', $cols['2'],
                                          ' AND [level] = %i', 1, ' AND [country] = %i', 60)->fetch();

        $insert = [
          'laUnitId' => intval($cols['0']),
          'fullName' => $cols['1'],
          'country' => 60,
          'laUnitOwner1' => $ownerLAUnit1['ndx'] ?? 0,
          'laUnitOwner2' => $ownerLAUnit1['laUnitOwner2'] ?? 0,
          'level' => 0,
        ];
        $this->db()->query('INSERT INTO [services_locAddr_laUnits] ', $insert);
      }

      $cnt++;
    }
  }

  protected function importLAUnits1()
  { // Kraje
		$cnt = 0;
    $file = fopen($this->resDir.'/'.'UI_VUSC_UTF8.csv', "r");

    while ($cols = fgetcsv($file, null, ';'))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      $existedUnit = $this->db()->query('SELECT * FROM [services_locAddr_laUnits] WHERE [laUnitId] = %i', $cols['0'],
                                        ' AND [level] = %i', 1, ' AND [country] = %i', 60)->fetch();

      if (!$existedUnit)
      {
        $ownerLAUnit = $this->db()->query('SELECT * FROM [services_locAddr_laUnits] WHERE [laUnitId] = %i', $cols['2'],
                                          ' AND [level] = %i', 2, ' AND [country] = %i', 60)->fetch();

        $insert = [
          'laUnitId' => intval($cols['0']),
          'fullName' => $cols['1'],
          'country' => 60,
          'laUnitOwner2' => $ownerLAUnit['ndx'] ?? 0,
          'level' => 1,
        ];
        $this->db()->query('INSERT INTO [services_locAddr_laUnits] ', $insert);
      }

      $cnt++;
    }
  }

  protected function importLAUnits2()
  { // Regiony
		$cnt = 0;
    $file = fopen($this->resDir.'/'.'UI_REGION_SOUDRZNOSTI_UTF8.csv', "r");

    while ($cols = fgetcsv($file, null, ';'))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      $existedUnit = $this->db()->query('SELECT * FROM [services_locAddr_laUnits] WHERE [laUnitId] = %i', $cols['0'],
                                        ' AND [level] = %i', 2, ' AND [country] = %i', 60)->fetch();

      if (!$existedUnit)
      {
        $insert = [
          'laUnitId' => intval($cols['0']),
          'fullName' => $cols['1'],
          'country' => 60,
          'level' => 2,
        ];
        $this->db()->query('INSERT INTO [services_locAddr_laUnits] ', $insert);
      }

      $cnt++;
    }
  }

  protected function importCities()
  {
		$cnt = 0;
    $file = fopen($this->resDir.'/'.'UI_OBEC_UTF8.csv', "r");

    while ($cols = fgetcsv($file, null, ';'))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      $existedCity = $this->db()->query('SELECT * FROM [services_locAddr_cities] WHERE [cityId] = %i', $cols['0'], ' AND [country] = %i', 60)->fetch();
      if (!$existedCity)
      {
        $ownerLAUnit = $this->db()->query('SELECT * FROM [services_locAddr_laUnits] WHERE [laUnitId] = %i', $cols['4'],
                                          ' AND [level] = %i', 0, ' AND [country] = %i', 60)->fetch();

        $insert = [
          'cityId' => intval($cols['0']),
          'fullName' => $cols['1'],
          'country' => 60,
          'laUnitOwner0' => $ownerLAUnit['ndx'] ?? 0,
          'laUnitOwner1' => $ownerLAUnit['laUnitOwner1'] ?? 0,
          'laUnitOwner2' => $ownerLAUnit['laUnitOwner2'] ?? 0,
        ];

        $this->db()->query('INSERT INTO [services_locAddr_cities] ', $insert);
      }

      $cnt++;
    }
  }

  protected function importStreets()
  {
		$cnt = 0;
    $file = fopen($this->resDir.'/'.'UI_ULICE_UTF8.csv', "r");

    while ($cols = fgetcsv($file, null, ';'))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      $existedStreet = $this->db()->query('SELECT * FROM [services_locAddr_streets] WHERE [streetId] = %i', $cols['0'], ' AND [country] = %i', 60)->fetch();
      if (!$existedStreet)
      {
        $ownerCity = $this->db()->query('SELECT * FROM [services_locAddr_cities] WHERE [cityId] = %i', $cols['2'],
                                        ' AND [country] = %i', 60)->fetch();

        $insert = [
          'streetId' => intval($cols['0']),
          'fullName' => $cols['1'],
          'country' => 60,
          'city' => $ownerCity['ndx'] ?? 0,
        ];

        $this->db()->query('INSERT INTO [services_locAddr_streets] ', $insert);
      }

      $cnt++;
    }
  }

  protected function importCitiesParts()
  {
		$cnt = 0;
    $file = fopen($this->resDir.'/'.'UI_CAST_OBCE_UTF8.csv', "r");

    while ($cols = fgetcsv($file, null, ';'))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      $existedStreet = $this->db()->query('SELECT * FROM [services_locAddr_citiesParts] WHERE [cityPartId] = %i', $cols['0'], ' AND [country] = %i', 60)->fetch();
      if (!$existedStreet)
      {
        $ownerCity = $this->db()->query('SELECT * FROM [services_locAddr_cities] WHERE [cityId] = %i', $cols['2'],
                                        ' AND [country] = %i', 60)->fetch();

        $insert = [
          'cityPartId' => intval($cols['0']),
          'fullName' => $cols['1'],
          'country' => 60,
          'city' => $ownerCity['ndx'] ?? 0,
        ];

        $this->db()->query('INSERT INTO [services_locAddr_citiesParts] ', $insert);
      }

      $cnt++;
    }
  }

  protected function importZIPCodes()
  {
		$cnt = 0;
    $file = fopen($this->resDir.'/'.'UI_ADRESNI_POSTA_UTF8.csv', "r");

    while ($cols = fgetcsv($file, null, ';'))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      $zipCodeId = intval(substr($cols['0'], 0, 3).substr($cols['0'], 4));

      $existedZIPCode = $this->db()->query('SELECT * FROM [services_locAddr_zipCodes] WHERE [zipCodeId] = %i', $zipCodeId,
                                        ' AND [country] = %i', 60)->fetch();

      if (!$existedZIPCode)
      {
        $insert = [
          'zipCodeId' => $zipCodeId,
          'idName' => $cols['0'],
          'fullName' => $cols['1'],
          'country' => 60,
        ];

        if ($cols['2'] !== '')
          $insert['validFrom'] = \DateTime::createFromFormat('d.m.Y', substr($cols['2'], 0, 10));
        if ($cols['3'] !== '')
          $insert['validTo'] = \DateTime::createFromFormat('d.m.Y', $cols['3']);

        $this->db()->query('INSERT INTO [services_locAddr_zipCodes] ', $insert);
      }

      $cnt++;
    }
  }

  protected function importAddrPlaces()
  {
    $cnt = 0;
		forEach (glob($this->resDir.'/'.'CSV/*.csv') as $fileName)
		{
      echo "# {$cnt}: ".$fileName."\n";

      $this->db()->begin();
      $this->importAddrPlaces_OneFile($fileName);
      $this->db()->commit();

      $cnt++;

      //if ($cnt > 10)
      //break;
		}
  }

  protected function importAddrPlaces_OneFile($fileName)
  {
		$cnt = 0;
    $file = fopen($fileName, "r");

    while ($cols = fgetcsv($file, null, ';'))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      $existedAddrPlace = $this->db()->query('SELECT * FROM [services_locAddr_addrPlaces] WHERE [addrPlaceId] = %i', $cols['0'], ' AND [country] = %i', 60)->fetch();
      if (!$existedAddrPlace)
      {
        $houserNr1Type = ($cols['11'] == 'č.p.') ? 0 : 1;
        $houseNr1 = intval($cols['12']);
        $houseNr2 = intval($cols['13']);
        $houseNrLetter = $cols['14'];

        $houseNr = strval($houseNr1);
        if ($houseNr2)
          $houseNr .= '/'.$houseNr2;
        if ($houseNrLetter)
          $houseNr .= $houseNrLetter;

        $insert = [
          'addrPlaceId' => intval($cols['0']),
          'country' => 60,
          'houseNr1Type' => $houserNr1Type,
          'houseNr1' => $houseNr1,
          'houseNr2' => $houseNr2,
          'houseNrLetter' => $houseNrLetter,
          'houseNr' => $houseNr,
        ];

        // -- WGS84 coordinates
        if ($cols['17'] !== '' && $cols['16'] !== '')
        {
          $jtskX = floatval($cols['17']);
          $jtskY = floatval($cols['16']);
          if ($jtskX != 0 && $jtskY != 0)
          {
            $insert['natGeoCoordY'] = $cols['16'];
            $insert['natGeoCoordX'] = $cols['17'];

            $gps = Wgs84::SJTSK_WGS84 ($jtskX, $jtskY);
            $insert['wgs84lat'] = $gps['lat'];
            $insert['wgs84lng'] = $gps['lng'];
          }
        }

        // -- validFrom
        if ($cols['18'] !== '')
          $insert['validFrom'] = \DateTime::createFromFormat('Y-m-d', substr($cols['18'], 0, 10));

        // -- city
        $ownerCity = $this->db()->query('SELECT * FROM [services_locAddr_cities] WHERE [cityId] = %i', $cols['1'], ' AND [country] = %i', 60)->fetch();
        if ($ownerCity)
        {
          $insert['city'] = $ownerCity['ndx'];
        }

        // -- zipCode
        $ownerZIPCode = $this->db()->query('SELECT * FROM [services_locAddr_zipCodes] WHERE [zipCodeId] = %i', $cols['15'], ' AND [country] = %i', 60)->fetch();
        if ($ownerZIPCode)
        {
          $insert['zipCode'] = $ownerZIPCode['ndx'];
        }

        // -- street
        $streetId = intval($cols['9']);
        if ($streetId)
        {
          $street = $this->db()->query('SELECT * FROM [services_locAddr_streets] WHERE [streetId] = %i', $streetId, ' AND [country] = %i', 60)->fetch();
          $insert['street'] = $street['ndx'] ?? 0;
        }

        // -- cityPart
        $cityPartId = intval($cols['7']);
        if ($cityPartId)
        {
          $cityPart = $this->db()->query('SELECT * FROM [services_locAddr_citiesParts] WHERE [cityPartId] = %i', $cityPartId, ' AND [country] = %i', 60)->fetch();
          $insert['cityPart'] = $cityPart['ndx'] ?? 0;
        }

        $this->db()->query('INSERT INTO [services_locAddr_addrPlaces] ', $insert);
      }

      $cnt++;
    }
  }

  public function download()
  {
    $cwd = getcwd();
    chdir($this->resDir);

    array_map('unlink', array_filter((array) glob("*")));

    // -- zip codes
    $this->downloadFile('https://services.cuzk.cz/sestavy/cis/UI_ADRESNI_POSTA.zip', 'UI_ADRESNI_POSTA.zip');
    $this->convertFileToUTF8('UI_ADRESNI_POSTA.csv', 'UI_ADRESNI_POSTA_UTF8.csv');

    // -- laUnits 0 - Okresy
    $this->downloadFile('https://services.cuzk.cz/sestavy/cis/UI_OKRES.zip', 'UI_OKRES.zip');
    $this->convertFileToUTF8('UI_OKRES.csv', 'UI_OKRES_UTF8.csv');

    // -- laUnits 1 - Kraje
    $this->downloadFile('https://services.cuzk.cz/sestavy/cis/UI_VUSC.zip', 'UI_VUSC.zip');
    $this->convertFileToUTF8('UI_VUSC.csv', 'UI_VUSC_UTF8.csv');

    // -- laUnits 2 - Regiony
    $this->downloadFile('https://services.cuzk.cz/sestavy/cis/UI_REGION_SOUDRZNOSTI.zip', 'UI_REGION_SOUDRZNOSTI.zip');
    $this->convertFileToUTF8('UI_REGION_SOUDRZNOSTI.csv', 'UI_REGION_SOUDRZNOSTI_UTF8.csv');

    // -- cities
    $this->downloadFile('https://services.cuzk.cz/sestavy/cis/UI_OBEC.zip', 'UI_OBEC.zip');
    $this->convertFileToUTF8('UI_OBEC.csv', 'UI_OBEC_UTF8.csv');

    // -- citiesParts
    $this->downloadFile('https://services.cuzk.cz/sestavy/cis/UI_CAST_OBCE.zip', 'UI_CAST_OBCE.zip');
    $this->convertFileToUTF8('UI_CAST_OBCE.csv', 'UI_CAST_OBCE_UTF8.csv');

    // -- streets
    $this->downloadFile('https://services.cuzk.cz/sestavy/cis/UI_ULICE.zip', 'UI_ULICE.zip');
    $this->convertFileToUTF8('UI_ULICE.csv', 'UI_ULICE_UTF8.csv');

    // -- addrPlaces
    $this->downloadAddrPlaces();

    chdir($cwd);
  }

  protected function downloadAddrPlaces()
  {
    $this->downloadFile('https://vdp.cuzk.gov.cz/vymenny_format/csv/20241231_OB_ADR_csv.zip', '20241231_OB_ADR_csv.zip');
    $this->convertFileToUTF8('UI_ULICE.csv', 'UI_ULICE_UTF8.csv');
		forEach (glob('CSV/*.csv') as $oneFile)
		{
      rename($oneFile, $oneFile.'.orig');
      $this->convertFileToUTF8($oneFile.'.orig', $oneFile);
			unlink($oneFile.'.orig');
		}
  }

  public function importAll()
  {
    $this->db()->query('DELETE FROM [services_locAddr_laUnits] WHERE [country] = %i', 60);
    $this->importLAUnits2();
    $this->importLAUnits1();
    $this->importLAUnits0();

    $this->db()->query('DELETE FROM [services_locAddr_cities] WHERE [country] = %i', 60);
    $this->importCities();


    $this->db()->query('DELETE FROM [services_locAddr_citiesParts] WHERE [country] = %i', 60);
    $this->importCitiesParts();

    $this->db()->query('DELETE FROM [services_locAddr_streets] WHERE [country] = %i', 60);
    $this->importStreets();


    $this->db()->query('DELETE FROM [services_locAddr_zipCodes] WHERE [country] = %i', 60);
    $this->importZIPCodes();

    $this->db()->query('DELETE FROM [services_locAddr_addrPlaces] WHERE [country] = %i', 60);
    $this->importAddrPlaces();
  }

  protected function convertFileToUTF8($srvFileName, $dstFileName)
  {
    $cmd = 'iconv -f CP1250 -t UTF-8 '.$srvFileName.' -o '.$dstFileName;
    exec($cmd);
  }
}
