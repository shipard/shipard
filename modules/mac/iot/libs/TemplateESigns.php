<?php

namespace mac\iot\libs;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;
use \e10\base\libs\UtilsBase;


/**
 * class TemplateUI
 */
class TemplateESigns extends \Shipard\Utils\TemplateCore
{
  public function app() {return $this->app;}

	function resolveCmd ($tagCode, $tagName, $params)
	{
    switch ($tagName)
		{
			case	'iotSensor' 					: return $this->iotSensor ($params);
			case	'iotSetup' 					  : return $this->iotSetup ($params);
      case  'slideShowImage'			: return $this->slideShowImage($params);
      case  'dataSet'			        : return $this->dataSet($params);
		}

    return parent::resolveCmd($tagCode, $tagName, $params);
  }

  public function subTemplateStr($stId)
	{
		$templateStr = file_get_contents(__SHPD_ROOT_DIR__.'/'.$stId.'.mustache');
		return $templateStr;
	}

  public function dataSet(array $params)
  {
    $sensorNdx = intval($params['id'] ?? 0);
    if (!$sensorNdx)
    {
      return 'Missing dataSet id';
    }

    $dataSetRecData = $this->app()->db()->query('SELECT * FROM [integrations_eds_data] WHERE [edsSet] = %i', $sensorNdx)->fetch();
    if (!$dataSetRecData)
    {
      return 'Invalid dataSet';
    }

    $data = Json::decode($dataSetRecData['data']);
    if (!$data)
    {
      return 'Empty dataSet';
    }

    $dataItemId = $params['item'] ?? '';
    $varName = intval($params['variable'] ?? 'dataSet');
    if ($dataItemId !== '')
      $this->data[$varName] = Utils::cfgItem($data, $dataItemId, 'unknown item');
    else
      $this->data[$varName] = $data;
		$webScriptId = $params['webScript'] ?? '';
		if ($webScriptId !== '')
		{
			$script = new \lib\web\WebScript($this->app());
			$script->setScriptId($webScriptId);
			$script->runScript($this->data[$varName], FALSE);

      return $script->resultCode;
		}

    return Utils::cfgItem($data, $dataItemId, 'unknown item');
  }

  public function iotSensor(array $params)
  {
    $data = ['valid' => 0, 'data' => []];

    $sensorNdx = intval($params['ndx'] ?? 0);
    if (!$sensorNdx)
    {
      return 'Missing sensor id';
    }

		$sensorRecData = $this->app()->db()->query(
      'SELECT [sensors].*, sensorsValues.value AS sensorValue FROM [mac_iot_sensors] AS [sensors] ',
			'LEFT JOIN [mac_iot_sensorsValues] AS sensorsValues ON sensors.ndx = sensorsValues.ndx',
			'WHERE [sensors].ndx = %i', $sensorNdx)->fetch();

    if (!$sensorRecData)
    {
      return 'Invalid sensor';
    }

    $data['data'] = $sensorRecData->toArray();

    $data['data']['sensorValueP0'] = round($sensorRecData['sensorValue'], 0);
    $data['data']['sensorValueP1'] = round($sensorRecData['sensorValue'], 1);
    $data['data']['sensorValueP2'] = round($sensorRecData['sensorValue'], 2);
    $data['data']['sensorValuePF0'] = Utils::nf($sensorRecData['sensorValue'], 0);
    $data['data']['sensorValuePF1'] = Utils::nf($sensorRecData['sensorValue'], 1);
    $data['data']['sensorValuePF2'] = Utils::nf($sensorRecData['sensorValue'], 2);

    $data['valid'] = 1;

    $icon = 'tables/mac.iot.sensors';
		if (isset($sensorRecData['sensorIcon']) && $sensorRecData['sensorIcon'] !== '')
		{
			$icon = $sensorRecData['sensorIcon'];
		}
		else
		{
			$qt = $this->app()->cfgItem('mac.data.quantityTypes.' . $sensorRecData['quantityType'], NULL);
			if ($qt)
			{
				$icon = $qt['icon'];
			}
		}

    $data['icon'] = $icon;

    $dataItemId = $params['item'] ?? 'data.sensorValue';
    return Utils::cfgItem($data, $dataItemId, 'unknown item');
  }

  public function iotSetup(array $params)
  {
    $data = ['valid' => 0, 'data' => []];

    $setupNdx = intval($params['ndx'] ?? 0);
    if (!$setupNdx)
    {
      return 'Missing setup id';
    }

		$setupRecData = $this->app()->db()->query(
      'SELECT [setups].*, setupsStates.activeScene AS activeScene FROM [mac_iot_setups] AS [setups] ',
			'LEFT JOIN [mac_iot_setupsStates] AS setupsStates ON setups.ndx = setupsStates.setup',
			'WHERE [setups].ndx = %i', $setupNdx)->fetch();

    if (!$setupRecData)
    {
      return 'Invalid setup';
    }

    $setupState = $this->app()->loadItem($setupRecData['activeScene'], 'mac.iot.scenes');

    $setupState['cssClass'] = 'setup-state-' . $setupState['stateType'];
    $setupState['cssClassFull'] = 'setup-' . $setupNdx . '-state-' . $setupState['stateType'];

    $data['setup'] = $setupRecData->toArray();
    $data['state'] = $setupState;
    $data['valid'] = 1;

    $dataItemId = $params['item'] ?? 'data.sensorValue';
    return Utils::cfgItem($data, $dataItemId, 'unknown item');
  }

  public function slideShowImage(array $params)
  {
    $now = new \DateTime();
    $attatchmentsNdxsParam = $params['attachments'] ?? '';
    if ($attatchmentsNdxsParam === '')
    {
      return 'Missing `attachments` param';
    }
    $attsParts = explode (',', $attatchmentsNdxsParam);
		$ndxs = [];
		forEach ($attsParts as $num)
    {
      $ndx = intval (trim($num));
      if ($ndx === 0)
        continue;
			$ndxs [] = $ndx;
    }
    if (count($ndxs) === 0)
      return 'Invalid `attachments` param';

    $slideShowIdParam = $params['id'] ?? 'slide-show';
    $slideShowId = sha1($slideShowIdParam);
    $fn = 'tmp/slide-show-' . $slideShowId . '.json';

    $interval = intval($params['interval'] ?? 30);
    $nextImageTime = new \DateTime('+' . $interval . ' minutes');

    $nextImageIndex = 0;

    $slideShowCfg = Utils::loadCfgFile($fn);
    if (!$slideShowCfg)
    {
      $slideShowCfg = [
        'lastImageIndex' => 0,
        'lastImageNdx' => $ndxs[0],
        'nextImageTime' => $nextImageTime->format('Y-m-d H:i:s'),
      ];

      file_put_contents($fn, Json::lint($slideShowCfg));
    }
    else
    { // next image?
      $nextImageIndex = $slideShowCfg['lastImageIndex'] ?? 0;
      $nit = Utils::createDateTime($slideShowCfg['nextImageTime']);
      if ($nit && $now > $nit)
      {
        $nextImageIndex++;
        if ($nextImageIndex >= count($ndxs))
          $nextImageIndex = 0;

        $slideShowCfg['lastImageIndex'] = $nextImageIndex;
        $slideShowCfg['lastImageNdx'] = $ndxs[$nextImageIndex];
        $slideShowCfg['nextImageTime'] = $nextImageTime->format('Y-m-d H:i:s');

        file_put_contents($fn, Json::lint($slideShowCfg));
      }
    }

    $attRecData = $this->app()->db()->query('SELECT * FROM [e10_attachments_files] where [ndx] = %i', $ndxs[$nextImageIndex])->fetch();
    if (!$attRecData)
      return 'Invalid attachment #' . $ndxs[$nextImageIndex];

    $url = UtilsBase::getAttachmentUrl ($this->app(), $attRecData);
    return $url;
  }
}
