<?php

namespace services\persons\libs\cz;
use \Shipard\Base\Utility;
use \Shipard\Utils\Json;


/**
 * class RegsChangesCZ
 */
class RegsChangesCZ extends Utility
{
  var $regNumId = 2;

  function http_post ($url, $data)
	{
    $strData = json_encode($data);
		$data_len = strlen ($strData);
		$context = stream_context_create (
			[
				'http'=> [
					'method'=>'POST',
					'header'=>"Content-type: application/json\r\nConnection: close\r\nContent-Length: $data_len\r\n",
					'content'=>$strData,
					'timeout' => 30
				]
			]
		);

		$result = @file_get_contents ($url, FALSE, $context);
		$responseHeaders = $http_response_header ?? [];
		return ['content'=> $result, 'headers'=> $responseHeaders];
	}

  public function downloadChangeSets()
  {
    $url = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty-notifikace/vyhledat';
    $data = ['datovyZdroj' => 'res'];

    $result = $this->http_post($url, $data);

    if (!$result)
    {
      echo "ERROR: invalid result\n";
      return;
    }

    if (!isset($result['content']))
    {
      echo "ERROR: no content\n";
      return;
    }

    $content = Json::decode($result['content']);

    if (!$content)
    {
      echo "ERROR: no data\n";
      return;
    }

    foreach ($content['notifikacniDavky'] as $nd)
    {
      $exist = $this->db()->query('SELECT * FROM [services_persons_regsChanges] WHERE [regType] = %i', $this->regNumId,
            ' AND [changeDay] = %d', $nd['datumUvolneniDavky'],
            ' AND [changeSetId] = %i', $nd['cisloDavky']
            )->fetch();
      if ($exist)
        continue;

      //echo "* ".json_encode($nd)."\n";

      $newItem = [
        'regType' => $this->regNumId,
        'changeDay' => $nd['datumUvolneniDavky'],
        'changeSetId' => intval($nd['cisloDavky']),
        'cntChanges' => intval($nd['pocetZmen']),
        'tsDownload' => new \DateTime(),
      ];

      $this->db()->query('INSERT INTO [services_persons_regsChanges] ', $newItem);
    }
  }

  public function downloadChangeSetsContents()
  {
    $q = [];
    array_push($q, 'SELECT * FROM [services_persons_regsChanges]');
    array_push($q, ' WHERE [changeState] = %i', 0);
    array_push($q, ' AND [regType] = %i', $this->regNumId);
    array_push($q, ' ORDER BY [ndx]');

    $cnt = 0;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      if ($cnt)
        sleep(3);

      $url = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty-notifikace/datovy-zdroj/res/cislo-davky/'.$r['changeSetId'];
      $dataStr = file_get_contents($url);
      if (!$dataStr)
      {
        echo "ERROR: `$url`\n";
      }
      $data = Json::decode($dataStr);
      if (!$data)
      {
        echo "ERROR: wrong data\n";
      }

      $update = [
        'srcData' => Json::lint($data),
        'changeState' => 1,
      ];

      $this->db()->query('UPDATE [services_persons_regsChanges] SET ', $update, ' WHERE [ndx] = %i', $r['ndx']);
      $cnt++;
    }
  }

  public function prepareChangeSetsItems()
  {
    $q = [];
    array_push($q, 'SELECT * FROM [services_persons_regsChanges]');
    array_push($q, ' WHERE [changeState] = %i', 1);
    array_push($q, ' AND [regType] = %i', $this->regNumId);
    array_push($q, ' ORDER BY [ndx]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $data = Json::decode($r['srcData']);
      if (!$data)
        continue;
      if (!isset($data['seznamNotifikaci']))
        continue;

      foreach ($data['seznamNotifikaci'] as $oneChange)
      {
        $this->addChangeSetItem($r['ndx'], $r['changeDay'], $oneChange);
      }

      $this->db()->query('UPDATE [services_persons_regsChanges] SET [changeState] = %i', 2, ' WHERE [ndx] = %i', $r['ndx']);

      break;
    }
  }

  protected function addChangeSetItem($changeSetNdx, $changeSetDay, $item)
  {
    //echo "* ".$item['icoId']."\n";

    $newItem = [
      'regsChangeSet' => $changeSetNdx,
      'country' => 60, // CZ
      'oid' => $item['icoId'],
      'changeType' => 2,
    ];

    if ($item['typZmeny'] === 'DEL')
      $newItem['changeType'] = 1;
    elseif ($item['typZmeny'] === 'INS')
      $newItem['changeType'] = 0;

    $existedPerson = $this->db()->query('SELECT * FROM [services_persons_persons]',
          ' WHERE [country] = %i', $newItem['country'],
          ' AND [oid] = %s', $newItem['oid'])->fetch();

    if ($existedPerson)
    {
      $newItem['person'] = $existedPerson['ndx'];
      if ($newItem['changeType'] === 1)
      { // DELETE
        $update = [
          'validTo' => $changeSetDay,
          'valid' => 0,
          'newDataAvailable' => 0,
          'updated' => new \DateTime(),
        ];
        $this->db()->query('UPDATE [services_persons_persons] SET ', $update,
                           ' WHERE [ndx] = %i', $existedPerson['ndx']);

        $newItem['done'] = 1;
      }
      else
      {
        $this->db()->query('UPDATE [services_persons_persons] SET [newDataAvailable] = %i', 1,
                            ', [newDataAvailable] = 1 WHERE [ndx] = %i', $existedPerson['ndx']);
      }
    }

    $this->db()->query('INSERT INTO [services_persons_regsChangesItems] ', $newItem);
  }

  public function doChangeSetItems($maxCount = 10, $addOnly = 0)
  {
    /** @var \services\persons\TableRegsChangesItems */
    $table = $this->app()->table('services.persons.regsChangesItems');
    $changeTypes = $table->columnInfoEnum ('changeType', 'cfgText');

    $q = []; //
    array_push($q, 'SELECT changeItems.*');
    array_push($q, ' FROM [services_persons_regsChangesItems] AS changeItems');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [done] = %i', 0);
    if ($addOnly)
      array_push($q, ' AND [changeType] = %i', 0);
    array_push($q, ' ORDER BY ndx');

    $cnt = 0;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      if ($r['changeType'] == 1)
        continue; // deleted

      if ($this->app->debug)
        echo "=== #".$r['ndx'].": ".$r['oid']."; ".$changeTypes[$r['changeType']]." ===\n";

      if ($r['changeType'] == 0)
      { // new
        $e = new \services\persons\libs\PersonData($this->app());
        $e->addPersonFromReg($r['oid'], $r['country']);
      }
      elseif ($r['changeType'] == 2)
      { // update
        $e = new \services\persons\libs\PersonData($this->app());
        $e->refreshImport($r['person']);
      }

      $this->db()->query('UPDATE [services_persons_regsChangesItems] SET [done] = 1 WHERE [ndx] = %i', $r['ndx']);

      $cnt++;
      if ($cnt >= $maxCount)
        break;

      sleep(1);
    }
  }

  public function doChangeSetItemsFromFiles($maxCount = 10)
  {
    $cntMissingFiles = 0;
    $cntExists = 0;
    $cntTotal = 0;
    $cntAdded = 0;

		$archiveFileName = __APP_DIR__.'/res/ares_vreo_all.tar';
		ini_set('memory_limit', '1024M');
		$archive = new \PharData($archiveFileName);


    /** @var \services\persons\TableRegsChangesItems */
    $table = $this->app()->table('services.persons.regsChangesItems');
    $changeTypes = $table->columnInfoEnum ('changeType', 'cfgText');

    $q = [];
    array_push($q, 'SELECT changeItems.*');
    array_push($q, ' FROM [services_persons_regsChangesItems] AS changeItems');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND ',
        ' NOT EXISTS (SELECT ndx FROM services_persons_persons WHERE ',
        'changeItems.oid = services_persons_persons.oid AND changeItems.country = services_persons_persons.country',
    ')');
    array_push($q, ' AND [done] = %i', 0);
    array_push($q, ' AND [changeType] = %i', 0);
    array_push($q, ' ORDER BY ndx');

    $cnt = 0;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $cntTotal++;

      if ($this->app->debug)
        echo "=== ".sprintf('%10d', $cntTotal).": ".$r['oid']."; ".$changeTypes[$r['changeType']].": ";

      $exist = $this->db()->query('SELECT * FROM [services_persons_persons] WHERE [oid] = %s', $r['oid'], ' AND [country] = %i', 60)->fetch();
      if ($exist)
      {
        if ($this->app->debug)
          echo "record exist...\n";
        $cntExists++;
        continue;
      }

      $oid = sprintf('%08d', intval($r['oid']));
      $oneTarFileName = 'VYSTUP/DATA/'.$oid.'.xml';
      $xmlTarFileName = __APP_DIR__.'/tmp/'.$oneTarFileName;

      try
      {
        $archive->extractTo(__APP_DIR__.'/tmp/', './'.$oneTarFileName, TRUE);
      }
      catch (\Exception $e){}

      if (!is_readable($xmlTarFileName))
      {
        if ($this->app->debug)
          echo "file `$oneTarFileName` not found\n";
        $cntMissingFiles++;
        continue;
      }

      $data = file_get_contents($xmlTarFileName);

      $ii = new \services\persons\libs\cz\InitialImportPersonsCZ($this->app());
      $newPersonNdx = $ii->importOnePersonARES($data, $oid);
      if (!$newPersonNdx)
      {
        if ($this->app->debug)
          echo "save person failed\n";
        continue;
      }

      $now = new \DateTime();
      $this->db()->query('UPDATE [services_persons_regsChangesItems] SET [done] = 1, [doneAt] = %t', $now,
                          ' WHERE [oid] = %s', $r['oid'], ' AND country = %i', 60);

      echo "SUCCESS; chsndx: {$r['regsChangeSet']}!\n";

      $cnt++;
      if ($cnt >= $maxCount)
        break;
    }

    echo "#### DONE; ADDED: {$cnt}; total scanned: ".$cntTotal.'; '. $cntMissingFiles . ' files missing'."\n";
  }

  public function doChangeSetItemsFromRES($maxCount = 10)
  {
		$fn = __APP_DIR__.'/res/res_data.csv';
		$cnt = 0;
		$cntNew = 0;
		$rowNumber = 0;

		if ($file = fopen($fn, "r"))
		{
			while(!feof($file))
			{
				$line = fgets($file);
				if ($line === '')
					continue;
				if ($cnt === 0)
				{
					$cnt = 1;
					continue;
				}
				if ($line === '')
					continue;

				$rowNumber++;

				$cols = str_getcsv($line, ',');
				$id = ltrim($cols[0], " \t\n\r\0\x0B0");
				$oid = sprintf('%08d', intval($id));
				$count = 0;
				$exist = $this->db()->query('SELECT * FROM [services_persons_persons] WHERE [country] = %i', 60, ' AND [oid] = %s', $oid, ' LIMIT 1')->fetch();
        if ($exist)
        {
          continue;
        }

				$name = trim($cols[11] ?? '');
        if ($name === '')
					continue;

        $newInChages = $this->db()->query('SELECT * FROM [services_persons_regsChangesItems] ',
                                          ' WHERE [done] = %i', 0, ' AND [changeType] = %i', 0,
                                          ' AND [oid] = %s', $oid , ' AND country = %i', 60)->fetch();
        if (!$newInChages)
          continue;

				$cntNew++;
				echo sprintf("%8d", $rowNumber).' / '.sprintf("%06d", $cntNew).': '.$oid.": `".$name."`";

        $ii = new \services\persons\libs\cz\InitialImportPersonsCZ($this->app());
        $addResult = $ii->importOnePersonRES($line, '');
        if ($addResult)
					echo " OK";
				else
					echo " ERROR";

        $now = new \DateTime();
        $this->db()->query('UPDATE [services_persons_regsChangesItems] SET [done] = 1, [doneAt] = %t', $now,
                            ' WHERE [oid] = %s', $oid, ' AND country = %i', 60, ' AND changeType IN %in', [0, 2]);

				echo "\n";
				if ($cntNew >= $maxCount)
					break;
			}
			fclose($file);

			echo "\n\n";
		}
		else
		{

		}
  }

  public function run()
  {
    $this->downloadChangeSets();
  }
}
