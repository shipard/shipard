<?php

namespace hosting\core\libs;
use \Shipard\Utils\Json, \Shipard\Base\Utility;


/**
 * @class ServersUpdateUpdownIO
 */
class ServersUpdateUpdownIO extends Utility
{
  var int $infoNdx = 0;

  public function run()
  {
    $apiKey = $this->app()->cfgItem('options.hosting.updownIOROApiKey', '');
    if ($apiKey == '')
      return;

    $updownIO = new \mac\data\libs\UpdownIO($this->app());
    $updownIO->setApiKey($apiKey);

    $rows = $this->db()->query('SELECT * FROM [hosting_core_servers] WHERE [docState] = %i', [4000], ' AND [updownIOId] != %s', '');
    foreach ($rows as $r)
    {
      $res = $updownIO->loadCheck($r['updownIOId']);
      if ($res)
        $this->saveInfo($r->toArray(), $res);

      //print_r($res);
    }

    $updownIO->close();
  }

  protected function checkExistedInfo(int $serverNdx)
  {
    $existedInfo = $this->db()->query('SELECT [ndx] FROM [hosting_core_serversInfo] WHERE [server] = %i', $serverNdx)->fetch();
    if (!$existedInfo)
    {
      $insert = ['server' => $serverNdx];
      $this->db()->query('INSERT INTO [hosting_core_serversInfo]', $insert);
      $this->infoNdx = intval ($this->db()->getInsertId ());
    }
    else
      $this->infoNdx = $existedInfo['ndx'];
  }

  protected function saveInfo(array $serverRecData, array $info)
  {
    $serverNdx = $serverRecData['ndx'];
    $this->checkExistedInfo($serverNdx);

    if (!$this->infoNdx)
      return;
  
    $update = [
      'dataUpdownIO' => Json::lint($info),
      'UpdownIOLastUpdate' => new \DateTime(),
    ];
    $this->db()->query('UPDATE [hosting_core_serversInfo] SET', $update, ' WHERE [ndx] = %i', $this->infoNdx);

    $serverUpdate = [];
    if ($serverRecData['updownIOUptime'] != floatval($info['uptime']))
      $serverUpdate['updownIOUptime'] = floatval($info['uptime']);

    if ($serverRecData['updownIOStatus'] !== intval($info['last_status']))
      $serverUpdate['updownIOStatus'] = intval($info['last_status']);

    if (isset($info['ssl']))
    {
      if ($serverRecData['updownIOSSLValid'] !== intval($info['ssl']['valid']))
        $serverUpdate['updownIOSSLValid'] = intval($info['ssl']['valid']);
      $sslEA = new \DateTime($info['ssl']['expires_at']);
      if ($serverRecData['updownIOSSLExpire'] !== $sslEA)
        $serverUpdate['updownIOSSLExpire'] = $sslEA;
    }
    else
    {
      if ($serverRecData['updownIOSSLValid'] !== 2)
        $serverUpdate['updownIOSSLValid'] = 2;
    }

    if (count($serverUpdate))
      $this->db()->query('UPDATE [hosting_core_servers] SET', $serverUpdate, ' WHERE [ndx] = %i', $serverNdx);
  }
}
