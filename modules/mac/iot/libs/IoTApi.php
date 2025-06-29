<?php

namespace mac\iot\libs;
use \Shipard\Utils\Json, \Shipard\Utils\Utils, \Shipard\Base\Utility, \Shipard\Application\Response;


/**
 * class IoTApi
 */
class IoTApi extends Utility
{
  var $deviceUID = '';
  var $deviceMAC = '';
  var $deviceRecData = NULL;

  var $operation = '';

	var $result = [];
  var $resultData = '';
  var $resultSrcFileName = '';
  var $resultSaveFileName = '';
  var $resultMimeType = 'application/json';

	public function init()
	{
	}

  protected function detectParams()
  {
    // -- deviceId
    $devId = $this->app->requestPath(2);
    if (substr($devId, 0, 4) === 'mac:')
    {
      $this->deviceMAC = str_replace('-', ':', substr($devId, 4));
      if ($this->deviceMAC === '')
      {
        $this->result['msg'] = 'Missing device MAC';
        return FALSE;
      }
      $drd = $this->db()->query('SELECT * FROM [mac_iot_devices] WHERE [hwId] = %s', $this->deviceMAC)->fetch();
      if (!$drd)
      {
        $this->result['msg'] = 'Device with MAC `'.$this->deviceMAC.'` not found';
        return FALSE;
      }
      $this->deviceRecData = $drd->toArray();
    }
    else
    {
      $this->deviceUID = $devId;
      if ($this->deviceUID === '')
      {
        $this->result['msg'] = 'Missing device UID';
        return FALSE;
      }
      $drd = $this->db()->query('SELECT * FROM [mac_iot_devices] WHERE [uid] = %s', $this->deviceUID)->fetch();
      if (!$drd)
      {
        $this->result['msg'] = 'Device with UID `'.$this->deviceUID.'` not found';
        return FALSE;
      }
      $this->deviceRecData = $drd->toArray();
    }

    // -- operation
    $this->operation = $this->app->requestPath(3);

    return TRUE;
  }

  protected function doOperation()
  {
    switch($this->operation)
    {
      case 'getDeviceCfg':
        $this->opGetDeviceCfg();
        break;
      case 'setDeviceInfo':
        $this->opSetDeviceInfo();
        break;
      default:
        $this->result['msg'] = 'Unknown operation `'.$this->operation.'`';
        return;
    }
  }

  protected function opGetDeviceCfg()
  {
		$q[] = 'SELECT * FROM [mac_iot_devicesCfg]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [iotDevice] = %i', $this->deviceRecData['ndx']);
		array_push($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      $iotBoxCfg = Json::decode($r['cfgData']);
      $this->resultData = Json::lint($iotBoxCfg['iotBoxCfg']);
      return;
    }
  }

  protected function opSetDeviceInfo()
  {
		$headers = Utils::getAllHeaders();
    $topic = $headers['X-IOT-TOPIC'] ?? '';

    $postDataStr = $this->app()->postData();
    error_log("==== IoTApi::opSetDeviceInfo: topic: `$topic`, postDataStr: ".$postDataStr);
    if ($postDataStr[0] === '{')
    {
      // JSON data
      $postData = substr($postDataStr, 1);
      if (!$postData)
      {
        $this->result['error'] = 'Invalid JSON POST data';
        return;
      }

      if (isset($postData['pwr-batt-voltage']) || isset($postData['items']['verFW']))
      { // device info
        $postData['devNdx'] = $this->deviceRecData['ndx'];
        $udr = new \mac\lan\UploadDataReceiver($this->app());
        $udr->setData($postData);
        $res = $udr->doShnIbInfo();
      }
    }
    else
    { // text data
      $postData = $postDataStr;
    }

    $this->resultData = 'OK';
    $this->resultMimeType = 'text/plain';
  }

	public function run ()
	{
    if (!$this->detectParams())
    {
      $this->result['error'] = 'Invalid parameters';
      $response = new Response ($this->app);
      $response->setMimeType('application/json');
      $response->add ('result', $this->result);
      $response->setStatus(404);
      return $response;
    }

		$this->doOperation();

		$response = new Response ($this->app);
    if ($this->resultSrcFileName !== '')
    {
      $response->setFile($this->resultSrcFileName, $this->resultMimeType, $this->resultSaveFileName);
    }
    elseif ($this->resultData !== '')
    {
      $response->setMimeType($this->resultMimeType);
      $response->setRawData($this->resultData);
      $response->setStatus(200);
    }
    else
    {
      $response->setMimeType('application/json');
      $response->add ('result', $this->result);
      $response->setStatus(404);
    }

		return $response;
	}
}
