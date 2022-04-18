<?php

namespace hosting\core\libs;
use \Shipard\Utils\Json, \Shipard\Base\Utility;


/**
 * @class ServerInfoReceiver
 */
class ServerInfoReceiver extends Utility
{
  public $result = ['success' => 0];

  var $incomingData;
  var $serverNdx = 0;
  var $serverRecData = NULL;
  var $infoNdx = 0;

  public function checkData()
  {
  	$this->incomingData = json_decode($this->app()->postData(), TRUE);
		if (!$this->incomingData)
			return;

    $serverNdx = intval($this->incomingData['serverId']);
    if (!$serverNdx)
      return;
  
    $serverExist = $this->db()->query('SELECT * FROM [hosting_core_servers] WHERE [ndx] = %i', $serverNdx)->fetch();
    if (!$serverExist)
      return;
    $this->serverNdx = $serverNdx;
    $this->serverRecData = $serverExist->toArray();  

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

  protected function save()
  {
    if (!$this->infoNdx)
      return;

    $update = [
      'dataCore' => Json::lint($this->incomingData),
      'dataCoreLastUpdate' => new \DateTime(),
    ];
    $this->db()->query('UPDATE [hosting_core_serversInfo] SET', $update, ' WHERE [ndx] = %i', $this->infoNdx);

    $serverUpdate = [];
    $shipardServerVerId = $this->incomingData['shipardServerDefaultChannel'].'/'.$this->incomingData['shipardServerChannels'][$this->incomingData['shipardServerDefaultChannel']]['version'];
    if ($this->serverRecData['shipardServerVerId'] !== $shipardServerVerId)
      $serverUpdate['shipardServerVerId'] = $shipardServerVerId;

    if ($this->serverRecData['osVerId'] !== $this->incomingData['os']['fullName'])
      $serverUpdate['osVerId'] = $this->incomingData['os']['fullName'];

    if (count($serverUpdate))
      $this->db()->query('UPDATE [hosting_core_servers] SET', $serverUpdate, ' WHERE [ndx] = %i', $this->serverNdx);

    $this->result ['success'] = 1;
  }

  public function run()
  {
    $this->checkData();
    $this->save();
  }
}
