<?php

namespace mac\iot\libs;
use \Shipard\Utils\Utils;


class UIControl extends \Shipard\UI\ng\TemplateUIControl
{
  public function renderSetupSceneSwitch(array $params)
  { // {{{@iotControl;type:setupSceneSwitch;ndx:1}}}
    $c = '';

    $setupNdx = intval($params['ndx'] ?? 0);

		$q = [];
		array_push($q, 'SELECT scenes.*, setups.fullName AS setupFullName, setups.shortName AS setupShortName');
		array_push($q, ' FROM mac_iot_scenes AS scenes');
		array_push($q, ' LEFT JOIN mac_iot_setups AS setups ON scenes.setup = setups.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND setup = %i', $setupNdx);
		array_push($q, ' ORDER BY [scenes].[order]');

    $c .= "<div class='btn-group' role='group' aria-label=''>";
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      $paramId = "set_scene_{$r['setup']}_{$r['ndx']}";
      $nameId = "set_scene_setup_".$r['setup'];
      $c .= "<input type='radio' class='btn-check' name='$nameId' id='$paramId' autocomplete='off'>";
      $c .= "<label class='btn btn-outline-primary' for='$paramId'>".Utils::es($r['shortName'])."</label>";
    }
    $c .= '</div>';

    return $c;
  }

  public function renderDeviceSwitch(array $params)
  {
    $c = '';

    $deviceNdx = intval($params['ndx'] ?? 0);

    $id = 'switch_'.$deviceNdx;

    $c .= "<div class='input-group flex-nowrap'>";

    $c .= "<label for='br_$id' class='form-label'>Jas</label>";
    $c .= "<input type='range' class='form-range' min='0' max='255' id='br_$id'>";

    $c .= "<div class='form-check form-switch'>";
    $c .= "<input class='form-check-input' type='checkbox' role='switch' id='onoff_$id'>";
    $c .= "<label class='form-check-label' for='onoff_$id'>123</label>";
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