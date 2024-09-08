<?php
namespace e10pro\vendms\libs;


/**
 * class VendMsMachineWidget
 */
class VendMsMachineWidget extends \Shipard\UI\Core\UIWidgetBoard
{
	var $vendmNdx = 0;

	var $code;
	var $products = [];
	var $units;

	var $today = NULL;

	// uiTemplate

	protected function composeCode ()
	{
		$this->vendmNdx = 1;

		$c = '';

		$vme = new \e10pro\vendms\libs\VendMsEngine($this->app());
		$vme->setVendMs($this->vendmNdx);
		$vme->createCodeMachine();

		$this->uiTemplate->data['machineSelectBoxTable'] = $vme->code;

		$templateStr = $this->uiTemplate->subTemplateStr('modules/e10pro/vendms/subtemplates/vmWidgetMachine');
		$c .= $this->uiTemplate->render($templateStr);

		$c .= $this->composeCodeInitScript();

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

		$this->today = new \DateTime();

		//$this->widgetSystemParams['data-cashbox'] = ($this->app->workplace && $this->app->workplace['cashBox']) ? $this->app->workplace['cashBox'] : 1;
		//$this->widgetSystemParams['data-warehouse'] = 0;

		//$this->widgetSystemParams['data-taxcalc'] = intval($this->app->cfgItem ('options.e10doc-sale.cashRegSalePricesType', 2));
		//$this->widgetSystemParams['data-taxcalc'] = E10Utils::taxCalcIncludingVATCode ($this->app(), $this->today, $this->widgetSystemParams['data-taxcalc']);

		//$this->widgetSystemParams['data-roundmethod'] = 1;

		//$this->units = $this->app->cfgItem ('e10.witems.units');

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
