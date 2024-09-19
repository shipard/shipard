<?php
namespace e10pro\vendms\libs;


/**
 * class VendMsSetupWidget
 */
class VendMsSetupWidget extends \Shipard\UI\Core\UIWidgetBoard
{
	var $vendmNdx = 0;
	var $vendmsCfg = NULL;

	var $code;

	protected function composeCode ()
	{
		if (!$this->vendmsCfg)
		{
			return 'unconfigured...';
		}

		$c = '';

		$vme = new \e10pro\vendms\libs\VendMsEngine($this->app());
		$vme->setVendMs($this->vendmNdx);
		$vme->createCodeSetup();

		$this->uiTemplate->data['machineSelectBoxTable'] = $vme->code;
		$this->uiTemplate->data['machineUrl'] = $this->vendmsCfg['urlMachine'];

		$templateStr = $this->uiTemplate->subTemplateStr('modules/e10pro/vendms/subtemplates/vmWidgetSetup');
		$c .= $this->uiTemplate->render($templateStr);

		$c .= $this->composeCodeInitScript();

		return $c;
	}

	protected function composeCodeInitScript ()
	{
    $c = '';
		$c = "\n<script>(() => {initWidgetVendMSetup ('{$this->widgetId}');})();</script>";
		return $c;
	}

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$this->vendmNdx = 1;
		$this->vendmsCfg = $this->app()->cfgItem('e10pro.vendms.vendms.'.$this->vendmNdx, NULL);

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
