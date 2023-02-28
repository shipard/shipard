<?php

namespace mac\iot\libs;
use \Shipard\Utils\Utils;


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

    $deviceNdx = intval($params['ndx'] ?? 0);
    $deviceRecData = $this->app()->loadItem($deviceNdx, 'mac.iot.devices');
    if (!$deviceRecData)
    {
      return 'Invalid device';
    }

    $id = 'switch_'.$deviceNdx;

    $c .= "<div class='card'>";
      $c .= "<div class='card-header'>";
        $c .= "<div class='form-check form-switch form-check-reverse'>";
        $c .= "<input class='form-check-input' type='checkbox' role='switch' id='onoff_$id'>";
        $c .= "<label class='form-check-label' for='onoff_$id'>".Utils::es($deviceRecData['fullName'])."</label>";
        $c .= "</div>";
      $c .= "</div>";

      $c .= "<div class='input-group'>";
        $c .= "<label for='br_$id' class='form-label'>Jas</label>";
        $c .= "<input type='range' class='form-range' min='0' max='255' id='br_$id' style='width: 80%;'>";
      $c .= "</div>";
    $c .= "</div>";

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
      default => ''
    };

    return $c;
  }
}