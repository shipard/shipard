<?php

namespace hosting\core\libs;
use \Shipard\Utils\Json, \Shipard\Base\Utility;


/**
 * class DSHostingInfoReceiver
 */
class DSHostingInfoReceiver extends Utility
{
  public $result = ['success' => 0];

  var $incomingData;
  var $dsNdx = 0;
  var $dsRecData = NULL;
  var $infoNdx = 0;

  public function checkData()
  {
  	$this->incomingData = json_decode($this->app()->postData(), TRUE);
		if (!$this->incomingData)
			return;

    $dsId = strval($this->incomingData['dsid'] ?? '');
    if ($dsId == '')
      return;

    $dsExist = $this->db()->query('SELECT * FROM [hosting_core_dataSources] WHERE [gid] = %s', $dsId)->fetch();
    if (!$dsExist)
    {
      return;
    }
    $this->dsNdx = $dsExist['ndx'];
    $this->dsRecData = $dsExist->toArray();

    $existedInfo = $this->db()->query('SELECT [ndx] FROM [hosting_core_dsHostingInfo] WHERE [dataSource] = %i', $this->dsNdx)->fetch();
    if (!$existedInfo)
    {
      $insert = ['dataSource' => $this->dsNdx];
      $this->db()->query('INSERT INTO [hosting_core_dsHostingInfo]', $insert);
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
      'data' => Json::lint($this->incomingData),
      'dateUpdate' => new \DateTime(),
    ];
    $this->db()->query('UPDATE [hosting_core_dsHostingInfo] SET', $update, ' WHERE [ndx] = %i', $this->infoNdx);

    $this->result ['success'] = 1;
  }

  public function run()
  {

    $this->checkData();
    $this->save();
  }
}
