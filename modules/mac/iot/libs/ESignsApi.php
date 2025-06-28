<?php

namespace mac\iot\libs;
use \Shipard\Utils\Json;


/**
 * class ESignsApi
 */
class ESignsApi extends \mac\iot\libs\IoTApi
{
  var $esignRecData = NULL;
  var $esignImageRecData = NULL;

  protected function doOperation()
  {
    switch($this->operation)
    {
      case 'getESignImageInfo':
      case 'getESignImageVersion':
      case 'getESignImagePreviewEInk':
      case 'getESignImagePreviewDisplay':
      case 'getESignImageEInk':
        $this->opESignImageInfo();
        return;
    }

    parent::doOperation();
  }

  protected function opESignImageInfo()
  {
    $e = new \mac\iot\libs\ESignImageEngine($this->app());
    $e->setESign(intval($this->esignRecData['ndx']));
    $e->doIt();

    $esignImageRecData = $this->app()->loadItem(intval($this->esignRecData['ndx']), 'mac.iot.esignsImgs');
		if (!$esignImageRecData)
    {
      $this->result['msg'] = 'ESign image for `'.$this->esignRecData['idName'].'` not found';
      return;
    }

    if ($this->operation === 'getESignImageVersion')
    {
      $this->resultMimeType = 'text/plain';
      $this->resultData = strval($esignImageRecData['version']);
      return;
    }
    elseif ($this->operation === 'getESignImageInfo')
      $this->resultData = Json::lint($esignImageRecData);
    elseif ($this->operation === 'getESignImagePreviewEInk')
    {
      $this->resultSrcFileName = __APP_DIR__.'/tmp/'.basename($esignImageRecData['imagePreviewURL']);
      $this->resultMimeType = 'image/png';
      $this->resultSaveFileName = 'esign-image-preview-eink.png';
    }
    elseif ($this->operation === 'getESignImagePreviewDisplay')
    {
      $this->resultSrcFileName = __APP_DIR__.'/tmp/'.basename($esignImageRecData['imagePreviewURL']);
      $this->resultMimeType = 'image/png';
      $this->resultSaveFileName = 'esign-image-preview-display.png';
    }
    elseif ($this->operation === 'getESignImageEInk')
    {
      $this->resultSrcFileName = __APP_DIR__.'/tmp/'.basename($esignImageRecData['imageEinkURL']);
      $this->resultMimeType = 'application/octet-stream';
      $this->resultSaveFileName = 'esign-image-eink.sbef';
    }
  }

  protected function detectParams()
  {
    if (!parent::detectParams())
      return FALSE;

    $esignId = $this->app->requestPath(4);
    if ($esignId === '')
    {
      $this->result['msg'] = 'Missing ESign ID';
      return FALSE;
    }

		$esignRecData = $this->db()->query('SELECT [esigns].* FROM [mac_iot_esigns] AS [esigns]',
                                       ' LEFT JOIN [mac_iot_devicesIOPorts] AS [ports] ON [esigns].[iotPort] = [ports].[ndx]',
                                       ' WHERE',
                                       ' [esigns].[iotDevice] = %i', intval($this->deviceRecData['ndx']),
                                       ' AND [ports].[portId] = %s', $esignId,
                                      )->fetch();
		if (!$esignRecData)
    {
      $this->result['msg'] = 'ESign for device `'.$this->deviceRecData['friendlyId'].'` not found';
      return FALSE;
    }
    $this->esignRecData = $esignRecData->toArray();

    return TRUE;
  }
}
