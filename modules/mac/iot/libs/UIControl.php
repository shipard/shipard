<?php

namespace mac\iot\libs;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;

/**
 * class UIControl
 */
class UIControl extends \Shipard\UI\ng\TemplateUIControl
{
  public function renderSetupSceneSwitch(array $params)
  {
    $c = '';

    $setupNdx = intval($params['ndx'] ?? 0);

    $activeSceneNdx = 0;
    $activeScene = $this->db()->query('SELECT * FROM [mac_iot_setupsStates] WHERE [setup] = %i', $setupNdx)->fetch();
    if ($activeScene)
      $activeSceneNdx = intval($activeScene['activeScene'] ?? 0);

		$q = [];
		array_push($q, 'SELECT scenes.*, setups.fullName AS setupFullName, setups.shortName AS setupShortName');
		array_push($q, ' FROM mac_iot_scenes AS scenes');
		array_push($q, ' LEFT JOIN mac_iot_setups AS setups ON scenes.setup = setups.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND setup = %i', $setupNdx);
		array_push($q, ' ORDER BY [scenes].[order]');

    $prefix = 'c'.base_convert(mt_rand(100000, 999999), 10, 36).'_';
    $groupClass = 'btn-group';
    if (isset($params['btnSize']))
      $groupClass .= ' btn-group-'.$params['btnSize'];
    $c .= "<div class='$groupClass' role='group' aria-label=''>";
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      $checked = ($r['ndx'] === $activeSceneNdx) ? ' checked' : '';
      $paramId = $prefix."set_scene_{$r['setup']}_{$r['ndx']}";
      $nameId = $prefix."set_scene_setup_".$r['setup'];
      $c .= "<input type='radio' class='btn-check' name='$nameId' id='$paramId' autocomplete='off'$checked>";
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
      $c .= $this->renderDeviceSwitch_Light($deviceNdx, $deviceRecData, $disabledOptions, $params);
    }

    //$c .= '<pre>'.Json::lint($deviceCfgData['dataModel']).'</pre>';

    return $c;
  }

  public function renderDeviceSwitch_Light($deviceNdx, $deviceRecData, array $disabledOptions, array $params)
  {
    $deviceCfgRecData = $this->app()->loadItem($deviceNdx, 'mac.iot.devicesCfg');
    $deviceCfgData = json_decode($deviceCfgRecData['cfgData'], TRUE);

    $useBrightness = isset($deviceCfgData['dataModel']['properties']['brightness']) && !in_array('br', $disabledOptions);
    $useColorTemp = isset($deviceCfgData['dataModel']['properties']['color_temp']) && !in_array('ct', $disabledOptions);;

    $id = 'switch_'.$deviceNdx;
    $icon = 'tables/mac.iot.devices';

    $c = "<div class='d-flex align-items-center mt-1 mb-1'";
    $c .= " data-shp-family='iot-light' data-shp-src='mqtt' data-shp-src-mqtt-topic='".Utils::es($deviceCfgData['dataModel']['deviceTopic'])."'";
    $c .= ">";
      $c .= "<div class='p-2 align-self-start'>";
        $c .= "<label class='fs-2' for='onoff_$id'>";
        $c .= $this->app()->ui()->icon($icon);
        $c .= "</label>";
      $c .= "</div>";
      $c .= "<div class='_p-2 flex-grow-1 _ms-2'>";
        $c .= "<label class='pb-1 fw-semibold' for='onoff_$id'>".Utils::es($deviceRecData['fullName'])."</label>";

        if ($useBrightness)
        {
          $c .= "<div class='d-flex flex-grow-1'><span class='pe-2'>Jas: </span>";
            $c .= "<input type='range' class='form-range flex-grow-1 shp-iot-br-range' min='0' max='255' id='br_$id' >";
          $c .= "</div>";
        }

        if ($useColorTemp)
        {
          $valueMin = intval($deviceCfgData['dataModel']['properties']['color_temp']['value-min']);
          $valueMax = intval($deviceCfgData['dataModel']['properties']['color_temp']['value-max']);
          if ($valueMin && $valueMax)
          {
            $c .= "<div class='d-flex flex-grow-1'><span class='pe-2'>WW: </span>";
            $c .= "<input type='range' class='form-range flex-grow-1 shp-iot-ct-range' min='$valueMin' max='$valueMax' id='br_$id' >";
            $c .= "</div>";
          }
        }
      $c .= "</div>";
      $c .= "<div class='ps-3 fs-3 align-self-start'>";
        $c .= "<div class='form-check form-switch form-switch-right'>";
          $c .= "<input class='form-check-input shp-iot-primary-switch' type='checkbox' role='switch' id='onoff_$id'>";
        $c .= "</div>";
      $c .= "</div>";
    $c .= "</div>";

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

    $id = 'sensor_'.$sensorNdx;
    $mode = 1;
    $asBadge = 1;
		$mainClass = $asBadge ? 'shp-badge' : '';
		$titleClass = $asBadge ? 'e10-bg-bt' : 'label';
		$contentClass = $asBadge ? 'e10-bg-bv-default' : '';

		$c .= "<span class='$mainClass' id='$id' data-shp-family='iot-sensor' data-shp-src='mqtt' data-shp-src-mqtt-topic='".Utils::es($sensorRecData['srcMqttTopic'])."' data-sensorid='".$sensorNdx."'>";
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

  public function render(string $tagName, ?array $params)
  {
    if (!isset($params['type']))
    {
      return 'missing param `type`';
    }

    $c = match ($params['type'])
    {
      'setupSceneSwitch' => $this->renderSetupSceneSwitch($params),
      'deviceSwitch' => $this->renderDeviceSwitch($params),
      'iotSensor' => $this->renderIoTSensor($params),
      default => ''
    };

    return $c;
  }
}