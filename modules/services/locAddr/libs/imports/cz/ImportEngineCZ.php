<?php

namespace services\locAddr\libs\imports\cz;
use \Shipard\Utils\Wgs84;
use \Shipard\Utils\Json;

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

  protected function importLAUnits2()
  {
    echo "# importLAUnits2 - Okresy\n";

		$cnt = 0;
    $file = fopen($this->resDir.'/'.'UI_OKRES_UTF8.csv', "r");

    while ($cols = fgetcsv($file, null, ';'))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      // Kraj okresu
      $ownerLAUnit1 = $this->db()->query('SELECT * FROM [services_locAddr_laUnits] WHERE [laUnitId] = %i', $cols['2'],
                                        ' AND [level] = %i', 1, ' AND [country] = %i', 60)->fetch();

      $existedUnit = $this->db()->query('SELECT * FROM [services_locAddr_laUnits] WHERE [laUnitId] = %i', $cols['0'],
                                        ' AND [level] = %i', 2, ' AND [country] = %i', 60)->fetch();
      if (!$existedUnit)
      {
        $insert = [
          'laUnitId' => intval($cols['0']),
          'fullName' => $cols['1'],
          'country' => 60,
          'laUnitOwner1' => $ownerLAUnit1['ndx'] ?? 0,  // kraj
          'laUnitOwner0' => $ownerLAUnit1['laUnitOwner0'] ?? 0, // region
          'level' => 2 ,
        ];
        $this->db()->query('INSERT INTO [services_locAddr_laUnits] ', $insert);
      }

      $cnt++;
    }
  }

  protected function importLAUnits1()
  {
    echo "# importLAUnits1 - Kraje\n";

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
        // Region kraje
        $ownerLAUnit = $this->db()->query('SELECT * FROM [services_locAddr_laUnits] WHERE [laUnitId] = %i', $cols['2'],
                                          ' AND [level] = %i', 0, ' AND [country] = %i', 60)->fetch();

        $insert = [
          'laUnitId' => intval($cols['0']),
          'fullName' => $cols['1'],
          'country' => 60,
          'laUnitOwner0' => $ownerLAUnit['ndx'] ?? 0, // region
          'level' => 1,
        ];
        $this->db()->query('INSERT INTO [services_locAddr_laUnits] ', $insert);
      }

      $cnt++;
    }
  }

  protected function importLAUnits0()
  {
    /*
     *
     */

    echo "# importLAUnits0 - Regiony soudržnosti\n";

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
                                        ' AND [level] = %i', 0, ' AND [country] = %i', 60)->fetch();

      if (!$existedUnit)
      {
        $insert = [
          'laUnitId' => intval($cols['0']),
          'fullName' => $cols['1'],
          'country' => 60,
          'level' => 0,
        ];
        $this->db()->query('INSERT INTO [services_locAddr_laUnits] ', $insert);
      }

      $cnt++;
    }
  }

  protected function importLAUnits11()
  {
    /*
     * "kodjaz","typvaz","akrcis1","kodcis1","chodnota1","text1","akrcis2","kodcis2","chodnota2","text2"
     * "CS","Editační vazba","ZUJ",51,"500011","Želechovice nad Dřevnicí","CISOB",43,"500011","Želechovice nad Dřevnicí"
     * "CS","Editační vazba","ZUJ",51,"500020","Petrov nad Desnou","CISOB",43,"500020","Petrov nad Desnou"
     * "CS","Editační vazba","ZUJ",51,"500046","Libhošť","CISOB",43,"500046","Libhošť"
     * "CS","Editační vazba","ZUJ",51,"500054","Praha 1","CISOB",43,"554782","Praha"
     */
    echo "# importLAUnits11 - ZUJ / Základní územní jednotky\n";

		$cnt = 0;
    $file = fopen($this->resDir.'/'.'ZUJ_VAZ0051_0043_CS.csv', "r");

    while ($cols = fgetcsv($file, null, ','))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      $laUnitOwner0 = 0;
      $laUnitOwner1 = 0;
      $laUnitOwner2 = 0;
      $city = 0;
      $ownerCity = $this->db()->query('SELECT * FROM [services_locAddr_cities] WHERE [cityId] = %i', intval($cols['8']),
                                      ' AND [country] = %i', 60)->fetch();
      if ($ownerCity)
      {
        $laUnitOwner0 = $ownerCity['laUnit0'];
        $laUnitOwner1 = $ownerCity['laUnit1'];
        $laUnitOwner2 = $ownerCity['laUnit2'];
        $city = $ownerCity['ndx'];
      }


      $existedUnit = $this->db()->query('SELECT * FROM [services_locAddr_laUnits] WHERE [laUnitId] = %i', intval($cols['4']),
                                        ' AND [level] = %i', 11, ' AND [country] = %i', 60)->fetch();
      if (!$existedUnit)
      {
        $insert = [
          'laUnitId' => intval($cols['4']),
          'fullName' => $cols['5'],
          'country' => 60,
          'laUnitOwner0' => $laUnitOwner0,
          'laUnitOwner1' => $laUnitOwner1,
          'laUnitOwner2' => $laUnitOwner2,
          'city' => $city,
          'level' => 11,
        ];
        $this->db()->query('INSERT INTO [services_locAddr_laUnits] ', $insert);
      }
      else
      {
        $update = [
          'fullName' => $cols['5'],
          'laUnitOwner0' => $laUnitOwner0,
          'laUnitOwner1' => $laUnitOwner1,
          'laUnitOwner2' => $laUnitOwner2,
          'city' => $city,
        ];
        $this->db()->query('UPDATE [services_locAddr_laUnits] SET ', $update, ' WHERE [ndx] = %i', $existedUnit['ndx']);
      }

      $cnt++;
    }
  }

  protected function importLAUnits11Links()
  {
    /*
     * "kodjaz","typvaz","akrcis1","kodcis1","chodnota1","text1","akrcis2","kodcis2","chodnota2","text2"
     * "CS","Editační vazba","CISOB",43,"500011","Želechovice nad Dřevnicí","ZUJ",51,"500011","Želechovice nad Dřevnicí"
     * "CS","Editační vazba","CISOB",43,"500020","Petrov nad Desnou","ZUJ",51,"500020","Petrov nad Desnou"
     * "CS","Editační vazba","CISOB",43,"500046","Libhošť","ZUJ",51,"500046","Libhošť"
     * "CS","Editační vazba","CISOB",43,"500062","Krhová","ZUJ",51,"500062","Krhová"
     */
    echo "# importLAUnits11Links - Vazby ZUJ / Vazby Základní územní jednotky na Obce a Městské části\n";

		$cnt = 0;
    $file = fopen($this->resDir.'/'.'ZUJ_VAZ0043_0051_CS.csv', "r");

    while ($cols = fgetcsv($file, null, ','))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      $recCity = $this->db()->query('SELECT * FROM [services_locAddr_cities] WHERE [cityId] = %i', intval($cols['8']),
                                      ' AND [country] = %i', 60)->fetch();
      $recZUJ = $this->db()->query('SELECT * FROM [services_locAddr_laUnits] WHERE [laUnitId] = %i', intval($cols['8']),
                                        ' AND [level] = %i', 11, ' AND [country] = %i', 60)->fetch();

      if (!$recZUJ)
        continue;

      if ($recCity)
      {
        $update = [
          'laUnit11' => $recZUJ['ndx'],
        ];
        $this->db()->query('UPDATE [services_locAddr_cities] SET ', $update, ' WHERE [ndx] = %i', $recCity['ndx']);
      }
      else
      {
        $recCityPart = $this->db()->query('SELECT * FROM [services_locAddr_citiesParts] WHERE [cityPartId] = %i', intval($cols['8']),
                                          ' AND cityPartKind = %i', 1, ' AND [country] = %i', 60)->fetch();
        if ($recCityPart)
        {
          $update = [
            'laUnit11' => $recZUJ['ndx'],
          ];
          $this->db()->query('UPDATE [services_locAddr_citiesParts] SET ', $update, ' WHERE [ndx] = %i', $recCityPart['ndx']);
        }
      }

      $cnt++;
    }
  }

  protected function importLAUnits10()
  {
    /*
     * "kodjaz","typvaz","akrcis1","kodcis1","chodnota1","text1","akrcis2","kodcis2","chodnota2","text2"
     * "CS","Editační vazba","CISORP",65,"2101","Benešov","ZUJ",51,"529303","Benešov"
     * "CS","Editační vazba","CISORP",65,"2102","Beroun","ZUJ",51,"531057","Beroun"
     * "CS","Editační vazba","CISORP",65,"2103","Brandýs nad Labem-Stará Boleslav","ZUJ",51,"538094","Brandýs nad Labem-Stará Boleslav"
     * "CS","Editační vazba","CISORP",65,"2104","Čáslav","ZUJ",51,"534005","Čáslav"
     */
    echo "# importLAUnits10 - ORP / Obce s rozšířenou působností\n";

		$cnt = 0;
    $file = fopen($this->resDir.'/'.'ORP_SEZNAM.csv', "r");

    while ($cols = fgetcsv($file, null, ','))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      $laUnitOwner0 = 0;
      $laUnitOwner1 = 0;
      $laUnitOwner2 = 0;
      $cityPart2 = 0;
      $city = 0;
      $ownerCity = $this->db()->query('SELECT * FROM [services_locAddr_cities] WHERE [cityId] = %i', intval($cols['8']),
                                      ' AND [country] = %i', 60)->fetch();
      if (!$ownerCity)
      {
        $ownerCityPart2 = $this->db()->query('SELECT * FROM [services_locAddr_citiesParts] WHERE [cityPartId] = %i', $cols['8'],
                                            ' AND cityPartKind = %i', 1, ' AND [country] = %i', 60)->fetch();

        if ($ownerCityPart2)
        {
          $ownerCity2 = $this->db()->query('SELECT * FROM [services_locAddr_cities] WHERE [ndx] = %i', intval($ownerCityPart2['city']))->fetch();
          if ($ownerCity2)
          {
            $laUnitOwner0 = $ownerCity2['laUnit0'];
            $laUnitOwner1 = $ownerCity2['laUnit1'];
            $laUnitOwner2 = $ownerCity2['laUnit2'];
            $cityPart2 = $ownerCityPart2['ndx'];
            //$city = $ownerCity2['ndx'];
          }
        }
      }
      elseif ($ownerCity)
      {
        $laUnitOwner0 = $ownerCity['laUnit0'];
        $laUnitOwner1 = $ownerCity['laUnit1'];
        $laUnitOwner2 = $ownerCity['laUnit2'];
        $city = $ownerCity['ndx'];
      }


      $existedUnit = $this->db()->query('SELECT * FROM [services_locAddr_laUnits] WHERE [laUnitId] = %i', intval($cols['4']),
                                        ' AND [level] = %i', 10, ' AND [country] = %i', 60)->fetch();
      if (!$existedUnit)
      {
        $insert = [
          'laUnitId' => intval($cols['4']),
          'fullName' => $cols['5'],
          'country' => 60,
          'laUnitOwner0' => $laUnitOwner0,
          'laUnitOwner1' => $laUnitOwner1,
          'laUnitOwner2' => $laUnitOwner2,
          'cityPart2' => $cityPart2,
          'city' => $city,
          'level' => 10,
        ];
        $this->db()->query('INSERT INTO [services_locAddr_laUnits] ', $insert);
      }
      else
      {
        $update = [
          'fullName' => $cols['5'],
          'laUnitOwner0' => $laUnitOwner0,
          'laUnitOwner1' => $laUnitOwner1,
          'laUnitOwner2' => $laUnitOwner2,
          'cityPart2' => $cityPart2,
          'city' => $city,
        ];
        $this->db()->query('UPDATE [services_locAddr_laUnits] SET ', $update, ' WHERE [ndx] = %i', $existedUnit['ndx']);
      }

      $cnt++;
    }
  }

  protected function importLAUnits10Links()
  {
    /*
     * "kodjaz","typvaz","akrcis1","kodcis1","chodnota1","text1","akrcis2","kodcis2","chodnota2","text2"
     * "CS","Odvozená vazba","CISOB",43,"500011","Želechovice nad Dřevnicí","CISORP",65,"7213","Zlín"
     * "CS","Odvozená vazba","CISOB",43,"500020","Petrov nad Desnou","CISORP",65,"7111","Šumperk"
     * "CS","Odvozená vazba","CISOB",43,"500046","Libhošť","CISORP",65,"8115","Nový Jičín"
     * "CS","Odvozená vazba","CISOB",43,"500062","Krhová","CISORP",65,"7210","Valašské Meziříčí"
     */
    echo "# importLAUnits10Links - Vazby ORP / Vazby obcí na Obce s rozšířenou působností\n";

		$cnt = 0;
    $file = fopen($this->resDir.'/'.'ORP_VAZBA_NA_OBCE.csv', "r");

    while ($cols = fgetcsv($file, null, ','))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }


      $recCity = $this->db()->query('SELECT * FROM [services_locAddr_cities] WHERE [cityId] = %i', intval($cols['4']),
                                      ' AND [country] = %i', 60)->fetch();

      $recORP = $this->db()->query('SELECT * FROM [services_locAddr_laUnits] WHERE [laUnitId] = %i', intval($cols['8']),
                                        ' AND [level] = %i', 10, ' AND [country] = %i', 60)->fetch();

      if (!$recORP)
        continue;

      if ($recCity)
      {
        $update = [
          'laUnit10' => $recORP['ndx'],
        ];
        $this->db()->query('UPDATE [services_locAddr_cities] SET ', $update, ' WHERE [ndx] = %i', $recCity['ndx']);
      }

      $cnt++;
    }
  }

  protected function importCities()
  {
    /*
     * KOD;NAZEV;STATUS_KOD;POU_KOD;OKRES_KOD;CLENENI_SM_ROZSAH_KOD;CLENENI_SM_TYP_KOD;PLATI_OD;PLATI_DO;DATUM_VZNIKU
     * 554979;Abertamy;3;1121;3403;;;18.09.2025 00:00:00;;
     * 531367;Adamov;2;221;3205;;;30.11.2016 00:00:00;;24.11.1990 00:00:00
     * 535826;Adamov;2;647;3301;;;14.04.2024 00:00:00;;24.11.1990 00:00:00
    */

    echo "# importCities - Obce\n";

		$cnt = 0;
    $file = fopen($this->resDir.'/'.'UI_OBEC_UTF8.csv', "r");

    while ($cols = fgetcsv($file, null, ';'))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      // Okres města
      $ownerLAUnit = $this->db()->query('SELECT * FROM [services_locAddr_laUnits] WHERE [laUnitId] = %i', $cols['4'],
                                        ' AND [level] = %i', 2, ' AND [country] = %i', 60)->fetch();

      $existedCity = $this->db()->query('SELECT * FROM [services_locAddr_cities] WHERE [cityId] = %i', $cols['0'], ' AND [country] = %i', 60)->fetch();
      if (!$existedCity)
      {
        $insert = [
          'cityId' => intval($cols['0']),
          'fullName' => $cols['1'],
          'country' => 60,
          'laUnit2' => $ownerLAUnit['ndx'] ?? 0, // okres
          'laUnit1' => $ownerLAUnit['laUnitOwner1'] ?? 0, // kraj
          'laUnit0' => $ownerLAUnit['laUnitOwner0'] ?? 0, // region
        ];

        $this->db()->query('INSERT INTO [services_locAddr_cities] ', $insert);
      }

      $cnt++;
    }
  }

  protected function importStreets()
  {
    echo "# importStreets - Ulice\n";

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
    /*
     * KOD;NAZEV;OBEC_KOD;PLATI_OD;PLATI_DO;DATUM_VZNIKU
     * 19;Abertamy;554979;27.05.2020 00:00:00;;
     * 6548;Adamov;579025;05.06.2015 00:00:00;;15.05.2001 00:00:00
     * 35;Adamov;535826;04.10.2013 00:00:00;;
    */

    echo "# importCitiesParts - Části obcí\n";

		$cnt = 0;
    $file = fopen($this->resDir.'/'.'UI_CAST_OBCE_UTF8.csv', "r");

    while ($cols = fgetcsv($file, null, ';'))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      $existedCityPart = $this->db()->query('SELECT * FROM [services_locAddr_citiesParts] WHERE [cityPartId] = %i', $cols['0'],
                                            ' AND cityPartKind = %i', 0, ' AND [country] = %i', 60)->fetch();
      if (!$existedCityPart)
      {
        $ownerCity = $this->db()->query('SELECT * FROM [services_locAddr_cities] WHERE [cityId] = %i', $cols['2'],
                                       ' AND [country] = %i', 60)->fetch();

        $insert = [
          'cityPartKind' => 0,
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

  protected function importCitiesParts2()
  {
    /*
     * KOD;NAZEV;OBEC_KOD;MOP_KOD;SPRAVOBV_KOD;PORADI;PLATI_OD;PLATI_DO;DATUM_VZNIKU
     * 551082;Brno-Bohunice;582786;;;1;20.03.2025 00:00:00;;24.11.1990 00:00:00
     * 551325;Brno-Bosonohy;582786;;;2;03.04.2025 00:00:00;;24.11.1990 00:00:00
     * 551198;Brno-Bystrc;582786;;;3;10.09.2025 00:00:00;;24.11.1990 00:00:00
    */

    echo "# importCitiesParts2 - Městské části\n";

		$cnt = 0;
    $file = fopen($this->resDir.'/'.'UI_MOMC_UTF8.csv', "r");

    while ($cols = fgetcsv($file, null, ';'))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      $existedCityPart = $this->db()->query('SELECT * FROM [services_locAddr_citiesParts] WHERE [cityPartId] = %i', $cols['0'],
                                            ' AND cityPartKind = %i', 1, ' AND [country] = %i', 60)->fetch();
      if (!$existedCityPart)
      {
        $ownerCity = $this->db()->query('SELECT * FROM [services_locAddr_cities] WHERE [cityId] = %i', $cols['2'],
                                       ' AND [country] = %i', 60)->fetch();

        $insert = [
          'cityPartKind' => 1,
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
    echo "# importZIPCodes - PSČ\n";

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
    echo "# importAddrPlaces - Adresní místa\n";

    $cnt = 0;
		forEach (glob($this->resDir.'/'.'CSV/*.csv') as $fileName)
		{
      //echo "# {$cnt}: ".$fileName."\n";
      if ($cnt % 10 === 0)
        echo ".";
      $this->db()->begin();
      $this->importAddrPlaces_OneFile($fileName);
      $this->db()->commit();

      $cnt++;

      //if ($cnt > 10)
      //break;
		}
    echo "\n";
  }

  protected function importAddrPlaces_OneFile($fileName)
  {
    /*
     * Kód ADM;Kód obce;Název obce;Kód MOMC;Název MOMC;Kód obvodu Prahy;Název obvodu Prahy;Kód části obce;Název části obce;Kód ulice;Název ulice;Typ SO;Číslo domovní;Číslo orientační;Znak čísla orientačního;PSČ;Souřadnice Y;Souřadnice X;Platí Od
     * 8338752;599999;Trojanovice;;;;;168491;Trojanovice;;;č.p.;2;;;74401;479862.17;1133960.17;2022-08-24T00:00:00
     * 8338761;599999;Trojanovice;;;;;168491;Trojanovice;;;č.p.;3;;;74401;480123.51;1134001.84;2013-12-01T00:00:00
     * 8338779;599999;Trojanovice;;;;;168491;Trojanovice;;;č.p.;4;;;74401;480180.85;1134153.63;2013-12-01T00:00:00
    */
		$cnt = 0;
    $file = fopen($fileName, "r");

    while ($cols = fgetcsv($file, null, ';'))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      $laUnit10 = 0;
      $laUnit11 = 0;

      // -- houseNr
      $houserNr1Type = ($cols['11'] == 'č.p.') ? 0 : 1;
      $houseNr1 = intval($cols['12']);
      $houseNr2 = intval($cols['13']);
      $houseNrLetter = $cols['14'];

      $houseNr = strval($houseNr1);
      if ($houseNr2)
        $houseNr .= '/'.$houseNr2;
      if ($houseNrLetter)
        $houseNr .= $houseNrLetter;

      // -- prepare data item
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
        $laUnit11 = $ownerCity['laUnit11'];
        $laUnit10 = $ownerCity['laUnit10'];
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
        $cityPart = $this->db()->query('SELECT * FROM [services_locAddr_citiesParts] WHERE [cityPartId] = %i', $cityPartId,
                                        ' AND cityPartKind = %i', 0, ' AND [country] = %i', 60)->fetch();
        $insert['cityPart'] = $cityPart['ndx'] ?? 0;
      }

      // -- cityPart2 / MOMC (Městská část)
      $cityPart2Id = intval($cols['3']);
      if ($cityPart2Id)
      {
        $cityPart2 = $this->db()->query('SELECT * FROM [services_locAddr_citiesParts] WHERE [cityPartId] = %i', $cityPart2Id,
                                        ' AND cityPartKind = %i', 1, ' AND [country] = %i', 60)->fetch();
        $insert['cityPart2'] = $cityPart2['ndx'] ?? 0;

        if ($cityPart2['laUnit11'] !== 0)
          $laUnit11 = $cityPart2['laUnit11'];
      }

      $insert['laUnit11'] = $laUnit11;
      $insert['laUnit10'] = $laUnit10;

      $existedAddrPlace = $this->db()->query('SELECT * FROM [services_locAddr_addrPlaces] WHERE [addrPlaceId] = %i', intval($cols['0']), ' AND [country] = %i', 60)->fetch();
      if (!$existedAddrPlace)
      {
        $this->db()->query('INSERT INTO [services_locAddr_addrPlaces] ', $insert);
      }
      else
      {
        $this->db()->query('UPDATE [services_locAddr_addrPlaces] SET ', $insert, ' WHERE [ndx] = %i', $existedAddrPlace['ndx']);
      }

      $cnt++;
    }
  }

  public function download()
  {
    /*
     * - https://www.cuzk.gov.cz/ruian/Poskytovani-udaju-ISUI-RUIAN-VDP/Ciselniky-ISUI/Nizsi-uzemni-prvky-a-uzemne-evidencni-jednotky.aspx
     */
    $cwd = getcwd();
    chdir($this->resDir);

    if (is_dir ('CSV'))
      exec ("rm -rf CSV");
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

    // -- laUnits 10 - ORP / Obce s rozšířenou působností
    // -- https://apl2.czso.cz/iSMS/cisdata.jsp?kodcis=65
    $this->downloadFile('https://apl2.czso.cz/iSMS/do_cis_export?kodcis=65&typdat=1&cisvaz=51_887&cisjaz=203&format=2', 'ORP_SEZNAM.csv');
    // -- https://apl2.czso.cz/iSMS/cisdata.jsp?kodcis=43
    $this->downloadFile('https://apl2.czso.cz/iSMS/do_cis_export?kodcis=43&typdat=1&cisvaz=65_1182&cisjaz=203&format=2', 'ORP_VAZBA_NA_OBCE.csv');

    // -- laUnits 11 - ZUJ / Základní územní jednotky s vazbou na Obce
    // -- https://apl2.czso.cz/iSMS/cisdata.jsp?kodcis=51
    $this->downloadFile('https://apl2.czso.cz/iSMS/do_cis_export?kodcis=51&typdat=1&cisvaz=43_37&cisjaz=203&format=2', 'ZUJ_VAZ0051_0043_CS.csv');

    // -- laUnits 11 - ZUJ / Základní územní jednotky s vazbou na Obce a Městské části
    // -- https://apl2.czso.cz/iSMS/cisdata.jsp?kodcis=43
    $this->downloadFile('https://apl2.czso.cz/iSMS/do_cis_export?kodcis=43&typdat=1&cisvaz=51_37&cisjaz=203&format=2', 'ZUJ_VAZ0043_0051_CS.csv');

    // -- mayby useful?
    //https://vdp.cuzk.cz/vdp/ruian/obce?sort=&ohradaId=&nespravny=&kodVc=&kodOk=&kodPu=&nazevOb=&statusKod=&search=&mediaType=csv

    // -- cities
    $this->downloadFile('https://services.cuzk.cz/sestavy/cis/UI_OBEC.zip', 'UI_OBEC.zip');
    $this->convertFileToUTF8('UI_OBEC.csv', 'UI_OBEC_UTF8.csv');

    // -- citiesParts
    $this->downloadFile('https://services.cuzk.cz/sestavy/cis/UI_CAST_OBCE.zip', 'UI_CAST_OBCE.zip');
    $this->convertFileToUTF8('UI_CAST_OBCE.csv', 'UI_CAST_OBCE_UTF8.csv');

    // -- streets
    $this->downloadFile('https://services.cuzk.cz/sestavy/cis/UI_ULICE.zip', 'UI_ULICE.zip');
    $this->convertFileToUTF8('UI_ULICE.csv', 'UI_ULICE_UTF8.csv');

    //--- městské části a obvody
    $this->downloadFile('https://services.cuzk.cz/sestavy/cis/UI_MOMC.zip', 'UI_MOMC.zip');
    $this->convertFileToUTF8('UI_MOMC.csv', 'UI_MOMC_UTF8.csv');

    // -- addrPlaces
    $this->downloadAddrPlaces();

    chdir($cwd);
  }

  protected function downloadAddrPlaces()
  {
    // -- https://nahlizenidokn.cuzk.gov.cz/StahniAdresniMistaRUIAN.aspx
    $addrPlacesUrl = 'https://vdp.cuzk.gov.cz/vymenny_format/csv/20250930_OB_ADR_csv.zip';
    $this->downloadFile($addrPlacesUrl, 'LASTEST_OB_ADR_csv.zip');
    $cnt = 0;
    echo "   ";
		forEach (glob('CSV/*.csv') as $oneFile)
		{
      if ($cnt % 100 == 0)
        echo ".";
      rename($oneFile, $oneFile.'.orig');
      $this->convertFileToUTF8($oneFile.'.orig', $oneFile);
			unlink($oneFile.'.orig');

      $cnt++;
		}

    echo "\n";
  }

  public function importAll()
  {
    //$this->db()->query('DELETE FROM [services_locAddr_laUnits] WHERE [country] = %i', 60);
    $this->importLAUnits0(); // Regiony soudržnosti
    $this->importLAUnits1(); // Kraje
    $this->importLAUnits2(); // Okresy

    //$this->db()->query('DELETE FROM [services_locAddr_cities] WHERE [country] = %i', 60);
    $this->importCities();

    //$this->db()->query('DELETE FROM [services_locAddr_citiesParts] WHERE [country] = %i', 60);
    $this->importCitiesParts();   // části obcí
    $this->importCitiesParts2();  // městské části


    $this->importLAUnits10();     // ORP
    $this->importLAUnits11();     // ZUJ
    $this->importLAUnits10Links();// Vazby ORP --> Obce
    $this->importLAUnits11Links();// Vazby ZUJ --> Obce a Městské části

    //$this->db()->query('DELETE FROM [services_locAddr_streets] WHERE [country] = %i', 60);
    $this->importStreets();

    //$this->db()->query('DELETE FROM [services_locAddr_zipCodes] WHERE [country] = %i', 60);
    $this->importZIPCodes();

    //$this->db()->query('DELETE FROM [services_locAddr_addrPlaces] WHERE [country] = %i', 60);
    $this->importAddrPlaces();
  }

  protected function convertFileToUTF8($srvFileName, $dstFileName)
  {
    $cmd = 'iconv -f CP1250 -t UTF-8 '.$srvFileName.' -o '.$dstFileName;
    exec($cmd);
  }
}
