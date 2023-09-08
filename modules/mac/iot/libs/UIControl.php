<?php

namespace mac\iot\libs;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;

/**
 * class UIControl
 */
class UIControl extends \Shipard\UI\ng\TemplateUIControl
{
  /** @var \mac\iot\TableDevices */
  var $iotDevicesTable;
  /** @var \mac\iot\TableCams */
  var $iotCamsTable;

  protected function defaultWssNdx()
  {
    return strval($this->uiTemplate->data['wssDefaultNdx']);
  }

  protected function iotDeviceSID($deviceNdx)
  {
    return $this->uiTemplate->uiData['iotElementsMap']['DE'.$deviceNdx]['sid'] ?? 'NULL-SID-'.$deviceNdx;
  }

  protected function registerIotDevice($iotDeviceRecData)
  {
    $wssNdx = $this->defaultWssNdx();
    $deviceNdx = 'DE'.$iotDeviceRecData['ndx'];
    $deviceTopic = $iotDeviceRecData['deviceTopic'];
    if (!isset($this->uiTemplate->uiData['iotElementsMap'][$deviceNdx]))
    {
      $deviceSID = base_convert(mt_rand(1000, 9999), 10, 36).'_iot';
      while(isset($this->uiTemplate->uiData['iotElementsMapSIDs'][$deviceSID]))
        $deviceSID = base_convert(mt_rand(1000, 9999), 10, 36).'_iot';

      $this->uiTemplate->uiData['iotElementsMapSIDs'][$deviceSID] = $deviceNdx;
      $this->uiTemplate->uiData['iotElementsMap'][$deviceNdx] = [
        'sid' => $deviceSID,
        'deviceTopic' => $deviceTopic,
      ];
      $this->uiTemplate->uiData['iotSubjects'][$deviceSID] = [
        'topic' => $deviceTopic, 'wss' => $wssNdx,
      ];

      $this->uiTemplate->uiData['iotTopicsMap'][$deviceTopic] = ['sid' => $deviceSID, 'type' => 'device', 'wss' => $wssNdx, 'elids' => []];
    }

    return $this->uiTemplate->uiData['iotElementsMap'][$deviceNdx];
  }

  protected function registerIotSensor($iotSensorRecData)
  {
    $wssNdx = $this->defaultWssNdx();
    $sensorNdx = 'SN'.$iotSensorRecData['ndx'];
    $sensorTopic = $iotSensorRecData['srcMqttTopic'];
    if (!isset($this->uiTemplate->uiData['iotElementsMap'][$sensorNdx]))
    {
      $sensorSID = base_convert(mt_rand(1000, 9999), 10, 36).'_iot';
      while(isset($this->uiTemplate->uiData['iotElementsMapSIDs'][$sensorSID]))
        $sensorSID = base_convert(mt_rand(1000, 9999), 10, 36).'_iot';

      $this->uiTemplate->uiData['iotElementsMapSIDs'][$sensorSID] = $sensorNdx;
      $this->uiTemplate->uiData['iotElementsMap'][$sensorNdx] = [
        'sid' => $sensorSID,
        'deviceTopic' => $sensorTopic,
      ];
      $this->uiTemplate->uiData['iotSubjects'][$sensorSID] = [
        'topic' => $sensorTopic, 'wss' => $wssNdx,
      ];

      $this->uiTemplate->uiData['iotTopicsMap'][$sensorTopic] = ['sid' => $sensorSID, 'type' => 'sensor', 'wss' => $wssNdx, 'elids' => []];
    }

    return $this->uiTemplate->uiData['iotElementsMap'][$sensorNdx];
  }

  protected function registerIotSetup($iotSetupRecData)
  { // shp/setups/3d-tisk/set : {"scene":"shp\/scenes\/3d-on"}
    $wssNdx = $this->defaultWssNdx();
    $setupNdx = 'ST'.$iotSetupRecData['ndx'];
    $setupTopic = 'shp/setups/'.$iotSetupRecData['id'];
    if (!isset($this->uiTemplate->uiData['iotElementsMap'][$setupNdx]))
    {
      $setupSID = base_convert(mt_rand(1000, 9999), 10, 36).'_iot';
      while(isset($this->uiTemplate->uiData['iotElementsMapSIDs'][$setupSID]))
        $setupSID = base_convert(mt_rand(1000, 9999), 10, 36).'_iot';

      $this->uiTemplate->uiData['iotElementsMapSIDs'][$setupSID] = $setupNdx;
      $this->uiTemplate->uiData['iotElementsMap'][$setupNdx] = [
        'sid' => $setupSID,
        'deviceTopic' => $setupTopic,
      ];
      $this->uiTemplate->uiData['iotSubjects'][$setupSID] = [
        'topic' => $setupTopic, 'wss' => $wssNdx,
      ];

      $this->uiTemplate->uiData['iotTopicsMap'][$setupTopic] = ['sid' => $setupSID, 'type' => 'scene', 'wss' => $wssNdx, 'elids' => []];
    }

    return $this->uiTemplate->uiData['iotElementsMap'][$setupNdx];
  }

  protected function registerCamPicture($camPictureInfo)
  {
    $deviceNdx = 'CMP'.$camPictureInfo['ndx'];
    $camServerNdx = intval($camPictureInfo['camServerNdx']);

    if (!isset($this->uiTemplate->uiData['iotCamPictures'][$deviceNdx]))
      $this->uiTemplate->uiData['iotCamPictures'][$deviceNdx] = ['camServerNdx' => $camServerNdx, 'elms' => []];

    if (!isset($this->uiTemplate->uiData['iotCamServers'][$camServerNdx]))
      $this->uiTemplate->uiData['iotCamServers'][$camServerNdx] = $camPictureInfo['serverInfo'];

    $deviceSID = $deviceNdx.base_convert(mt_rand(1000, 9999), 10, 36).'_iot';
    $this->uiTemplate->uiData['iotCamPictures'][$deviceNdx]['elms'][] = $deviceSID;

    return $deviceSID;
  }

  protected function registerTopicMainElement($topic)
  {
    if (!isset($this->uiTemplate->uiData['iotTopicsMap'][$topic]))
      return 'INVALID_TOPIC';

    $elid = 'e'.base_convert(mt_rand(100000, 999900), 10, 36).'_'.count($this->uiTemplate->uiData['iotTopicsMap'][$topic]['elids']);
    $this->uiTemplate->uiData['iotTopicsMap'][$topic]['elids'][] = $elid;

    return $elid;
  }

  public function renderSetupSceneSwitch(array $params)
  {
    $c = '';

    $setupNdx = intval($params['ndx'] ?? 0);
    $setupRecData = $this->app()->loadItem($setupNdx, 'mac.iot.setups');
    if (!$setupRecData)
    {
      return 'Invalid setup id';
    }

    $setupRegData = $this->registerIotSetup($setupRecData);
    $setupSID = $setupRegData['sid'];
    $setupTopic = $setupRegData['deviceTopic'];

    //$activeSceneNdx = 0;

		$q = [];
		array_push($q, 'SELECT scenes.*, setups.fullName AS setupFullName, setups.shortName AS setupShortName');
		array_push($q, ' FROM mac_iot_scenes AS scenes');
		array_push($q, ' LEFT JOIN mac_iot_setups AS setups ON scenes.setup = setups.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND setup = %i', $setupNdx);
		array_push($q, ' ORDER BY [scenes].[order]');

    $id = $this->registerTopicMainElement($setupTopic);

    $groupClass = 'btn-group';
    if (isset($params['btnSize']))
      $groupClass .= ' btn-group-'.$params['btnSize'];
    $c .= "<div class='$groupClass' id='$id' data-shp-family='iot-setup-scene' role='group' aria-label=''";
    $c .= ">";
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      $checked = '';//($r['ndx'] === $activeSceneNdx) ? ' checked' : '';
      $paramId = $id."set_scene_{$r['setup']}_{$r['ndx']}";
      $nameId = $id."set_scene_setup_".$r['setup'];
      $c .= "<input type='radio' class='btn-check mac-shp-triggger shp-iot-scene-switch' name='$nameId'".
            " data-shp-iot-setup='$setupSID'".
            " id='$paramId' data-shp-scene-id='shp/scenes/".Utils::es($r['friendlyId'])."' autocomplete='off'$checked>";
      $c .= "<label class='btn btn-outline-primary' for='$paramId'>".Utils::es($r['shortName'])."</label>";
    }
    $c .= '</div>';

    return $c;
  }

  public function renderDeviceSwitch(array $params)
  {
    $c = '';

    $devicesNdxList = explode(',', $params['ndx'] ?? '');
    if (!count($devicesNdxList))
    {
      return 'Invalid / missing param `ndx`';
    }

    $disabledOptions = explode(',', $params['disabledOptions'] ?? '');

    foreach ($devicesNdxList as $dn)
    {
      $deviceNdx = intval($dn);
      $deviceRecData = $this->app()->loadItem($deviceNdx, 'mac.iot.devices');
      if (!$deviceRecData)
      {
        $c .= '<pre>Invalid device #'.$deviceNdx.'</pre>';
        continue;
      }

      if ($deviceRecData['deviceKind'] === 'light')
        $c .= $this->renderDeviceSwitch_Light($deviceNdx, $deviceRecData, $disabledOptions, $params);
      elseif ($deviceRecData['deviceKind'] === 'socket')
        $c .= $this->renderDeviceSwitch_Socket($deviceNdx, $deviceRecData, $disabledOptions, $params);
    }

    //$c .= '<pre>'.Json::lint($deviceCfgData['dataModel']).'</pre>';

    return $c;
  }

  public function renderDeviceSwitch_Light($deviceNdx, $deviceRecData, array $disabledOptions, array $params)
  {
    $deviceCfgRecData = $this->app()->loadItem($deviceNdx, 'mac.iot.devicesCfg');
    $deviceCfgData = json_decode($deviceCfgRecData['cfgData'], TRUE);

    $deviceRegData = $this->registerIotDevice($deviceRecData);
    $deviceSID = $deviceRegData['sid'];

    $id = $this->registerTopicMainElement($deviceRecData['deviceTopic']);

    $useBrightness = isset($deviceCfgData['dataModel']['properties']['brightness']) && !in_array('br', $disabledOptions);
    $useColorTemp = isset($deviceCfgData['dataModel']['properties']['color_temp']) && !in_array('ct', $disabledOptions);;

    $icon = $this->iotDevicesTable->tableIcon($deviceRecData);
    $title = $deviceRecData['uiName'] === '' ? $deviceRecData['fullName'] : $deviceRecData['uiName'];

    $c = "<div class='d-flex align-items-center mt-1 mb-1'";
    $c .= " id='$id' data-shp-family='iot-light' data-shp-iot-device='$deviceSID'";
    $c .= ">";
      $c .= "<div class='p-2 align-self-start'>";
        $c .= "<label class='fs-2' for='{$id}_onoff'>";
        $c .= $this->app()->ui()->icon($icon);
        $c .= "</label>";
      $c .= "</div>";
      $c .= "<div class='_p-2 flex-grow-1 _ms-2'>";
        $c .= "<label class='pb-1 fw-semibold' for='{$id}_onoff'>".Utils::es($title)."</label>";

        if ($useBrightness)
        {
          $c .= "<div class='d-flex flex-grow-1'><span class='pe-2'>".$this->app()->ui()->icon('iconBrightness')."</span>";
            $c .= "<input type='range' class='form-range flex-grow-1 shp-iot-br-range mac-shp-triggger' data-shp-iot-device='$deviceSID' min='0' max='255' id='br_$id' disabled>";
          $c .= "</div>";
        }

        if ($useColorTemp)
        {
          $valueMin = intval($deviceCfgData['dataModel']['properties']['color_temp']['value-min']);
          $valueMax = intval($deviceCfgData['dataModel']['properties']['color_temp']['value-max']);
          if ($valueMin && $valueMax)
          {
            $c .= "<div class='d-flex flex-grow-1'><span class='pe-2'>".$this->app()->ui()->icon('iconBrightness')."</span>";
            $c .= "<input type='range' class='form-range flex-grow-1 shp-iot-ct-range mac-shp-triggger' data-shp-iot-device='$deviceSID' min='$valueMin' max='$valueMax' id='br_$id' disabled>";
            $c .= "</div>";
          }
        }
      $c .= "</div>";
      $c .= "<div class='ps-3 fs-3 align-self-start'>";
        $c .= "<div class='form-check form-switch form-switch-right'>";
          $c .= "<input class='form-check-input shp-iot-primary-switch mac-shp-triggger' data-shp-iot-device='$deviceSID' type='checkbox' role='switch' id='{$id}_onoff' disabled>";
        $c .= "</div>";
      $c .= "</div>";
    $c .= "</div>";

    return $c;
  }

  public function renderDeviceSwitch_Socket($deviceNdx, $deviceRecData, array $disabledOptions, array $params)
  {
    $deviceCfgRecData = $this->app()->loadItem($deviceNdx, 'mac.iot.devicesCfg');
    $deviceCfgData = json_decode($deviceCfgRecData['cfgData'], TRUE);

    $deviceRegData = $this->registerIotDevice($deviceRecData);
    $deviceSID = $deviceRegData['sid'];


    $icon = $this->iotDevicesTable->tableIcon($deviceRecData);
    $title = $deviceRecData['uiName'] === '' ? $deviceRecData['fullName'] : $deviceRecData['uiName'];
    $c = '';

    foreach ($deviceCfgData['dataModel']['properties'] as $propId => $propCfg)
    {
      if (!str_starts_with($propId, 'state'))
        continue;
      $id = $this->registerTopicMainElement($deviceRecData['deviceTopic']);

      $c .= "<div class='d-flex align-items-center mt-1 mb-1'";
      $c .= " id='$id' data-shp-family='iot-light' data-shp-iot-device='$deviceSID'";
      $c .= ">";
        $c .= "<div class='p-2 align-self-start'>";
          $c .= "<label class='fs-2' for='{$id}_onoff'>";
          $c .= $this->app()->ui()->icon($icon);
          $c .= "</label>";
        $c .= "</div>";
        $c .= "<div class='_p-2 flex-grow-1 _ms-2'>";
          $c .= "<label class='pb-1 fw-semibold' for='{$id}_onoff'>".Utils::es($title)."</label>";
        $c .= "</div>";
        $c .= "<div class='ps-3 fs-3 align-self-start'>";
          $c .= "<div class='form-check form-switch form-switch-right'>";
            $c .= "<input class='form-check-input shp-iot-primary-switch mac-shp-triggger' data-shp-iot-device='$deviceSID' data-shp-iot-state-id='$propId' type='checkbox' role='switch' id='{$id}_onoff' disabled>";
          $c .= "</div>";
        $c .= "</div>";
      $c .= "</div>";
    }

    return $c;
  }


  public function renderDevicesGroupSwitch(array $params)
  {
    $c = '';

    $groupNdxList = explode(',', $params['ndx'] ?? '');

    if (!count($groupNdxList))
    {
      return 'Invalid / missing param `ndx`';
    }

    $style = 'fullCard';

    $disabledOptions = explode(',', $params['disabledOptions'] ?? '');
    $enabledOptions = explode(',', $params['enabledOptions'] ?? '');

    foreach ($groupNdxList as $dn)
    {
      $deviceGroupNdx = intval($dn);
      $deviceGroupRecData = $this->app()->loadItem($deviceGroupNdx, 'mac.iot.devicesGroups');
      if (!$deviceGroupRecData)
      {
        $c .= '<pre>Invalid group #'.$deviceGroupNdx.'</pre>';
        continue;
      }

      $baseId = 'G'.$deviceGroupNdx;
      $groupEID = $baseId.'_ABC';

      // -- device in group
      $devicesNdxs = $this->db()->query ('SELECT ndx, iotDevice FROM [mac_iot_devicesGroupsItems] WHERE [devicesGroup] = %i', $deviceGroupNdx, ' ORDER BY [rowOrder], [ndx]')->fetchPairs ();
      $oneDeviceParams = ['ndx' => implode(',', array_values($devicesNdxs))];
      if (isset($params['disabledOptions']))
        $oneDeviceParams['disabledOptions'] = $params['disabledOptions'];
      $devicesInGroupCode = $this->renderDeviceSwitch($oneDeviceParams);
      $devicesInGroupSIDs = [];
      foreach ($devicesNdxs as $gdndx)
      {
        $devicesInGroupSIDs[] = $this->iotDeviceSID($gdndx);
        $this->uiTemplate->uiData['iotElementsGroups'][$groupEID][] = $this->iotDeviceSID($gdndx);
      }
      $devicesInGroupSIDsParam = implode(',', $devicesInGroupSIDs);

      if ($style === 'fullCard')
      {
        $c .= "<div class='card' id='$groupEID'>";
          $c .= "<div class='card-header'>";
            $c .= "<div class='d-flex align-items-center mt-1 mb-1'";
            $c .= ">";
              $c .= "<div class='ps-3 fs-3 align-self-start'>";
                $c .= "<div class='form-check form-switch form-switch-right'>";
                  $c .= "<input class='form-check-input mac-shp-triggger shp-iot-group-switch' data-shp-iot-device='$devicesInGroupSIDsParam' type='checkbox' role='switch' id='{$baseId}_onoff' disabled>";
                $c .= "</div>";
              $c .= "</div>";
              $c .= "<div class='_p-2 flex-grow-1 _ms-2'>";
                $c .= "<label class='pb-1 fw-semibold' for='{$baseId}_onoff'>".Utils::es($deviceGroupRecData['shortName'])."</label>";
              $c .= "</div>";
            $c .= "</div>";
          $c .= "</div>"; // --> card-header

          $c .= "<div class='row g-0'>"; //
            $c .= "<div class='col pt-2' style='max-width: 4rem !important; text-align: center;'>";
            $c .= $this->app()->ui()->icon('iconBrightness', 'd-block');
            $c .= "<input type='range' class='_form-range shp-iot-br-range mac-shp-triggger' orient='vertical'";
            $c .= " data-shp-iot-device='$devicesInGroupSIDsParam' min='0' max='255'";
            $c .= " id='{$baseId}_br_range'";
            $c .= " style='--moz-appearance: slider-vertical; appearance: slider-vertical; margin-top: .3rem; width: 2em; height: 89%;'>";
            $c .= "</div>";

            $c .= "<div class='col p-2' style='max-width: 4rem !important; text-align: center;'>";
            $c .= $this->app()->ui()->icon('iconColorTemperature', 'd-block');
            $c .= "<input type='range' class='_form-range shp-iot-ct-range mac-shp-triggger' orient='vertical'";
            $c .= " data-shp-iot-device='$devicesInGroupSIDsParam' min='250' max='454'";
            $c .= " id='{$baseId}_ct_range'";
            $c .= " style='--moz-appearance: slider-vertical; appearance: slider-vertical; margin-top: .3rem; width: 2em; height: 89%;'>";
            $c .= "</div>";

            $c .= "<div class='col'>";
            $c .= $devicesInGroupCode;
            $c .= "</div>";

          $c .= "</div>";
        $c .= "</div>"; // --> card
        continue;
      }

      //$c .= $this->renderDeviceSwitch_Light($deviceNdx, $deviceRecData, $disabledOptions, $params);
    }

    return $c;
  }

  public function renderIoTSensor(array $params)
  {
    $c = '';

    $sensorNdx = intval($params['ndx'] ?? 0);
    if (!$sensorNdx)
    {
      return 'Missing sensor id';
    }

		$sensorRecData = $this->db()->query(
      'SELECT [sensors].*, sensorsValues.value AS sensorValue FROM [mac_iot_sensors] AS [sensors] ',
			'LEFT JOIN [mac_iot_sensorsValues] AS sensorsValues ON sensors.ndx = sensorsValues.ndx',
			'WHERE [sensors].ndx = %i', $sensorNdx)->fetch();

    if (!$sensorRecData)
    {
      return 'Invalid sensor';
    }

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

    $sensorRegData = $this->registerIotSensor($sensorRecData);
    $sensorSID = $sensorRegData['sid'];
    $id = $this->registerTopicMainElement($sensorRecData['srcMqttTopic']);

    $mode = 1;
    $asBadge = 1;
		$mainClass = $asBadge ? 'shp-badge' : '';
		$titleClass = $asBadge ? 'e10-bg-bt' : 'label';
		$contentClass = $asBadge ? 'e10-bg-bv-default' : '';

		$c .= "<span class='$mainClass' id='$id' data-shp-family='iot-sensor'>";
		$c .= "<span class='$titleClass'>";

		$c .= $this->app()->ui()->icon($icon).' ';
		$c .= utils::es($sensorRecData['sensorBadgeLabel']);
		$c .= '</span>';
		if ($mode == 1)
		{
			$c .= "<span class='$contentClass value'> ";
			if (isset($sensorRecData['sensorValue']))
				$c .= $sensorRecData['sensorValue'];
			$c .= "</span>";
			$c .= "<span class='$contentClass unit'>" . $sensorRecData['sensorBadgeUnits'] . "</span>";
		}
		$c .= "</span>";

    return $c;
  }

  public function renderCamPicture(array $params)
  {
    $c = '';

    $camsNdxList = explode(',', $params['ndx'] ?? '');
    $pictStyle = $params['pictStyle'] ?? 'preview';

    if (!count($camsNdxList))
    {
      return 'Invalid / missing param `ndx`';
    }
    $phUrl = $this->app->urlRoot.'/www-root/sc/shipard/ph-image-1920-1080.svg';
    foreach ($camsNdxList as $cn)
    {
      $camNdx = intval($cn);
      $camInfo = $this->iotCamsTable->camInfo($camNdx);
      if (!$camInfo)
      {
        $c .= 'Invalid camera info';
        continue;
      }

      $picId = $this->registerCamPicture($camInfo);
      $c .= "<div class='shp-cam-pict' data-cam-ndx='".$camInfo['camServerNdx']."' data-pict-style='".Utils::es($pictStyle)."' id='{$picId}'>";

      if ($pictStyle === 'video')
      {
        $streamUrl = $camInfo['streams'][0]['url'] ?? '';

				$c .= "<video autoplay muted playsinline controls";
      	$c .= " style='width: 100%;'";
				$c .= " data-stream-url='$streamUrl'>";
				$c .= '</video>';
      }
      else
        $c .= "<img src='$phUrl' style='max-width: 100%;'>";

      $c .= '</div>';
    }

    return $c;
  }

	public function renderIoTControlButton(array $params)
	{
    $controlsNdxList = explode(',', $params['ndx'] ?? '');
    if (!count($controlsNdxList))
    {
      return 'Invalid / missing param `ndx`';
    }

    $btnType = 'primary';
    if (isset($params['btnType']))
      $btnType = $params['btnType'];

    $c = '';
    foreach ($controlsNdxList as $cn)
    {
      $controlNdx = intval($cn);
      $controlRecData = $this->app()->loadItem($controlNdx, 'mac.iot.controls');
      if (!$controlRecData)
      {
        $c .= '<pre>Invalid control #'.$controlNdx.'</pre>';
        continue;
      }

      $controlButton = [
        'text' => $controlRecData['shortName'],

        'action' => 'inline-action',
        'class' => 'pl1',

        'icon' => 'system/iconCheck',
        'btnClass' => 'btn-'.$btnType,
        'actionClass' => 'shp-app-action',

        'data-object-class-id' => 'mac.iot.libs.IotAction',
        'data-action-param-control' => $controlRecData['uid']
      ];

      if ($controlRecData['controlType'] === 'setDeviceProperty')
      {
        $controlButton['data-action-param-action-type'] = 'set-device-property';
      }
      elseif ($controlRecData['controlType'] === 'sendSetupRequest')
      {
        $controlButton['data-action-param-action-type'] = 'send-setup-request';
        $controlButton['data-action-param-setup'] = $controlRecData['iotSetup'];
        $controlButton['data-action-param-setup-request'] = $controlRecData['iotSetupRequest'];
      }
      elseif ($controlRecData['controlType'] === 'sendMqttMsg')
      {
        $controlButton['data-action-param-action-type'] = 'send-mqtt-msg';
      }

      $c .= $this->app()->ui()->renderTextLine($controlButton);
    }
		return $c;
	}

  public function render(string $tagName, ?array $params)
  {
    if (!isset($params['type']))
    {
      return 'missing param `type`';
    }

    $this->iotDevicesTable = $this->app()->table('mac.iot.devices');
    $this->iotCamsTable = $this->app()->table('mac.iot.cams');

    $c = match ($params['type'])
    {
      'setupSceneSwitch' => $this->renderSetupSceneSwitch($params),
      'deviceSwitch' => $this->renderDeviceSwitch($params),
      'renderDevicesGroupSwitch' => $this->renderDevicesGroupSwitch($params),
      'iotSensor' => $this->renderIoTSensor($params),
      'controlButton' => $this->renderIoTControlButton($params),
      'camPicture' => $this->renderCamPicture($params),
      default => ''
    };

    return $c;
  }
}