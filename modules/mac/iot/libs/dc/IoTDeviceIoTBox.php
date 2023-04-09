<?php

namespace mac\iot\libs\dc;
use \Shipard\Utils\Utils, \Shipard\Utils\Json;
use \Shipard\UI\Core\UIUtils;

/**
 * class IoTDeviceIoTBox
 */
class IoTDeviceIoTBox extends \mac\iot\libs\dc\IoTDevice
{
  var $portsTypes = [];

  var $ioPorts = [];
  var $usedHWPins = [];
  var $iotDeviceCfg = NULL;

  var $ioPortsTiles = [];

	public function createContentBody ()
	{
    if (count($this->ioPorts))
    {
      $h = [
        'portId' => 'Port',
        'pins' => 'Piny',
        'note' => 'Pozn.',
      ];


      $this->addContent('body',
        [
          'pane' => 'e10-pane_ _padd5', 'paneTitle' => ['text' => 'IO porty', 'class' => 'h2 block pb1'],
          'type' => 'tiles', 'tiles' => $this->ioPortsTiles, 'class' => 'panes'
        ]
      );

      $this->createContentBody_IotBox();

      $this->addContent('body',
        [
          'pane' => 'e10-pane e10-pane-table',
          'type' => 'text', 'subtype' => 'code', 'text' => Json::lint ($this->iotDeviceCfg),
          'paneTitle' => ['text' => 'GPIO layout', 'class' => 'subtitle']
          ]
      );

    }
	}

	function createContentBody_IotBox()
	{

		$q[] = 'SELECT * FROM [mac_iot_devicesCfg]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [iotDevice] = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY [ndx]');

		$cnt = 0;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      $iotBoxCfg = Json::decode($r['cfgData']);
      $this->addContent('body',
        [
          'pane' => 'e10-pane e10-pane-table',
          'type' => 'text', 'subtype' => 'code', 'text' => Json::lint($iotBoxCfg['iotBoxCfg']),
          'paneTitle' => ['text' => 'CFG', 'class' => 'subtitle']
          ]
      );

      $cnt++;
		}
	}


	public function createContent ()
	{
    $this->portsTypes = $this->app()->cfgItem ('mac.iot.ioPorts.types');
		$this->iotDeviceCfg = $this->table->iotDeviceCfgFromRecData($this->recData, TRUE);

    $this->loadIOPorts();

		$this->createContentBody ();
	}

  public function loadIOPorts()
  {
		$q = [];
    array_push ($q, 'SELECT [ports].*');
		array_push ($q, ' FROM [mac_iot_devicesIOPorts] AS [ports]');
		array_push ($q, ' LEFT JOIN [mac_iot_devices] AS iotDevices ON ports.iotDevice = iotDevices.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND ports.[iotDevice] = %i', $this->recData['ndx']);
		array_push ($q, ' ORDER BY ports.[rowOrder]');

    $rows = $this->db()->query($q);
    foreach ($rows as $item)
    {
      $portType = $this->portsTypes[$item['portType']];

      $tile = [];
      $tile['class'] = 'e10-pane';

      $portTitle = [];
      $portTitleBgClass = 'e10-bg-t9';

      $portTitle[] = ['text' => $item['portId'], 'class' => 'h2'];
      if ($item['disabled'])
      {
        $portTitle[] = ['text' => 'Zakázáno', 'class' => 'label label-danger pull-right'];
        $portTitleBgClass = 'e10-row-minus';
      }


      $portTitle[] = ['text' => '', 'class' => 'break'];
      $portTitle[] = ['text' => $portType['name'], 'class' => 'e10-small'];

      if ($item['fullName'] !== '')
        $portTitle[] = ['text' => $item['fullName'], 'class' => 'e10-small'];


      $tile['title'] = [['class' => '__h3 '.$portTitleBgClass, 'value' => $portTitle]];

      $portBody = [];

      $listItem = [];
      $listItem ['icon'] = 'system/iconCogs';
      $listItem ['portId'] = [];

      if ($item['disabled'])
        $listItem ['portId'][] = ['text' => 'Zakázáno', 'class' => 'label label-danger'];

      $listItem ['portId'][] = ['text' => $item['portId'], 'class' => 'break e10-bold'];
      if ($item['fullName'] !== '')
        $listItem ['portId'][] = ['text' => $item['fullName'], 'class' => 'break e10-small'];

      $listItem ['portId'][] = ['text' => $portType['name'], 'class' => 'break e10-small'];

      $listItem ['note'] = [];
      if ($item['note'] !== '')
        $listItem ['note'][] = ['text' => $item['note'], 'class' => 'block'];

      // -- pins
      $pinsLabels = [];
      $settingsProps = [];
      $portCfg = json_decode($item['portCfg'], TRUE);
      foreach ($portCfg as $key => $value)
      {
        $ioPortTypeCfg = $this->table->ioPortTypeCfg($item['portType']);
        $portTypeCfgColumn = utils::searchArray($ioPortTypeCfg['fields']['columns'], 'id', $key);
        if (!$portTypeCfgColumn)
          continue;

        $columnEnabled = uiutils::subColumnEnabled ($portTypeCfgColumn, $portCfg);
        if ($columnEnabled === FALSE)
          continue;

        if ($portTypeCfgColumn && isset($portTypeCfgColumn['enumCfgFlags']['type']) && $portTypeCfgColumn['enumCfgFlags']['type'] === 'pin')
        {
          $pinsLabels[] = [
            'text' => $portTypeCfgColumn['name'].': ', 'class' => '__width20 __block __pull-left __number pr1 e10-bold'];

          $pinCfg = isset($this->iotDeviceCfg['io']['pins'][$value]) ? $this->iotDeviceCfg['io']['pins'][$value] : NULL;
          if ($pinCfg)
          {
            $hwPin = isset($pinCfg['expPortId']) ? $pinCfg['expPortId'].':'.$pinCfg['hwnr']: strval($pinCfg['hwnr']);
            if (!isset($this->usedHWPins[$hwPin]))
              $this->usedHWPins[$hwPin] = 1;
            else
              $this->usedHWPins[$hwPin]++;

            $pinsLabels[] = ['text' => $pinCfg['title'], 'class' => 'label label-default'];

            if ($this->usedHWPins[$hwPin] === 1)
            {
              $pinsLabels[] = ['text' => '#'.$hwPin, 'class' => 'label label-info'];
            }
            else
            {
              $pinsLabels[] = ['text' => '#'.$hwPin, 'suffix' => 'vícenásobné použití', 'icon' => 'system/iconWarning', 'class' => 'label label-danger'];
              //$pinsLabels[] = ['text' => '#'.$hwPin, 'suffix' => 'vícenásobné použití', 'icon' => 'system/iconWarning', 'class' => 'label label-danger'];
            }

            $pinsLabels[] = ['text' => '', 'class' => 'break'];
          }
          else
          {
            $pinsLabels[] = ['text' => 'Chyba v konfiguraci pinu: `'.$value.'`', 'icon' => 'system/iconWarning', 'class' => 'label label-danger'];

          }
        }
        else
        {
          $showValue = $value;
          if (isset($portTypeCfgColumn['enumCfg']['cfgItem']))
          {
            $cfgItem = $this->app()->cfgItem($portTypeCfgColumn['enumCfg']['cfgItem'].'.'.$value, NULL);
            if ($cfgItem && isset($cfgItem[$portTypeCfgColumn['enumCfg']['cfgText']]))
              $showValue = $cfgItem[$portTypeCfgColumn['enumCfg']['cfgText']];
          }
          elseif ($portTypeCfgColumn['type'] === 'logical' && $value == 0)
          {
            $showValue = NULL;
          }

          if ($showValue !== NULL)
          {
            $settingsProps[] = ['text' => $portTypeCfgColumn['name'].':', 'class' => ''];
            $settingsProps[] = ['text' => strval($showValue), 'class' => ''];
            $settingsProps[] = ['text' => '', 'class' => 'break'];
          }
        }
      }

      if (count($pinsLabels))
      {
        $listItem['pins'] = $pinsLabels;
        $portBody[] = $pinsLabels;
      }

      if (count($settingsProps))
      {
        if (count($pinsLabels))
        {
          $portBody[] = ['text' => '', 'class' => 'break block bb1 padd5 _pb05 _lh16'];
          //$portBody[] = ['text' => ' ', 'class' => 'break lh16'];
        }
        //$portBody[] = $settingsProps;
      }

      if (count($portBody) || count($settingsProps))
      {
        $tile['body'] = [];
        if (count($portBody))
          $tile['body'][] = ['class' => 'padd5 pl1 lh16', 'value' => $portBody];
        if (count($settingsProps))
          $tile['body'][] = ['class' => 'padd5 pl1', 'value' => $settingsProps];
      }

      if (count($settingsProps))
        $listItem['note'] = array_merge ($listItem['note'], $settingsProps);

      $this->ioPorts [] = $listItem;
      $this->ioPortsTiles [] = $tile;
    }
  }
}
