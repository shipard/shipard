<?php
namespace e10pro\vendms\libs;


/**
 * class VendMsMachineWidget
 */
class VendMsMachineWidget extends \Shipard\UI\Core\UIWidgetBoard
{
	var $vendmNdx = 0;
	var $vendmsCfg = NULL;

	var $code;

	var $sensorIdTempTop = '';
	var $sensorIdTempBottom = '';
	var $sensorIdBusy = '';
	var $rfidReaderId = '';
	var $mqttBaseTopic = '';

	protected function composeCode ()
	{
		if (!$this->vendmsCfg)
		{
			return 'unconfigured...';
		}

		$this->uiTemplate->data['machineUrl'] = $this->vendmsCfg['urlMachine'];
		$this->uiTemplate->data['setupUrl'] = $this->vendmsCfg['urlSetup'];

		$c = '';

		$vme = new \e10pro\vendms\libs\VendMsEngine($this->app());
		$vme->setVendMs($this->vendmNdx);
		$vme->createCodeMachine();

		$this->uiTemplate->data['machineSelectBoxTable'] = $vme->code;

		$templateStr = $this->uiTemplate->subTemplateStr('modules/e10pro/vendms/subtemplates/vmWidgetMachine');
		$c .= $this->uiTemplate->render($templateStr);

		$c .= $this->composeCodeInitScript();

		$this->uiTemplate->uiData['iotTopicsMap'][$this->sensorIdTempTop] = [
			'sid' => 'AAA_10',
			'type' => 'sensor',
			'wss' => 1,
			'elids' => [$this->widgetId]
		];

		$this->uiTemplate->uiData['iotTopicsMap'][$this->sensorIdTempBottom] = [
			'sid' => 'AAA_20',
			'type' => 'sensor',
			'wss' => 1,
			'elids' => [$this->widgetId]
		];

		$this->uiTemplate->uiData['iotTopicsMap'][$this->sensorIdBusy] = [
			'sid' => 'AAA_30',
			'type' => 'sensor',
			'wss' => 1,
			'elids' => [$this->widgetId]
		];

		$this->uiTemplate->uiData['iotTopicsMap'][$this->rfidReaderId] = [
			'sid' => 'AAA_40',
			'type' => 'reader',
			'wss' => 1,
			'elids' => [$this->widgetId]
		];

		$this->uiTemplate->uiData['iotTopicsMap'][$this->mqttBaseTopic] = [
			'sid' => 'AAA_50',
			'type' => 'device',
			'wss' => 1,
			'elids' => [$this->widgetId]
		];

		return $c;
	}

	protected function composeCodeInitScript ()
	{
    $c = '';
		$c = "\n<script>(() => {initWidgetVendM ('{$this->widgetId}');})();</script>";
		return $c;
	}

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$this->vendmNdx = 1;
		$this->vendmsCfg = $this->app()->cfgItem('e10pro.vendms.vendms.'.$this->vendmNdx, NULL);

		$this->rfidReaderId = $this->vendmsCfg['mqttRfid'];
		$this->sensorIdBusy = $this->vendmsCfg['mqttBusy'];
		$this->sensorIdTempBottom = $this->vendmsCfg['mqttTempBottom'];
		$this->sensorIdTempTop = $this->vendmsCfg['mqttTempTop'];
		$this->mqttBaseTopic = $this->vendmsCfg['mqttBaseTopic'];

		$this->widgetSystemParams['data-temp-sensor-top'] = $this->sensorIdTempTop;
		$this->widgetSystemParams['data-temp-sensor-bottom'] = $this->sensorIdTempBottom;
		$this->widgetSystemParams['data-sensor-busy'] = $this->sensorIdBusy;
		$this->widgetSystemParams['data-base-topic'] = $this->mqttBaseTopic;
		$this->widgetSystemParams['data-reader-rfid'] = $this->rfidReaderId;
		$this->widgetSystemParams['data-setup-mode-cards'] = $this->vendmsCfg['setupModeChipIds'];
		$this->widgetSystemParams['data-setup-url'] =  $this->vendmsCfg['urlSetup'];
		$this->widgetSystemParams['data-machine-url'] = $this->vendmsCfg['urlMachine'];

		$this->code = $this->composeCode();
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $this->code]);
	}

	public function title()
	{
		return FALSE;
	}

	public function setDefinition ($d)
	{
		$this->definition = ['class' => 'e10-widget-terminal', 'type' => 'terminal'];
	}

	public function fullScreen()
	{
		return 1;
	}

	public function pageType()
	{
		return 'terminal';
	}
}
