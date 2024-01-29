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

  public function run()
  {
    $this->downloadChangeSets();
  }
}
