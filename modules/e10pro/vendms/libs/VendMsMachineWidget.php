<?php

namespace e10pro\vendms\libs;
use e10\utils, e10doc\core\libs\E10Utils;
use \Shipard\UI\Core\WidgetPane;


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

	protected function loadProducts ()
	{
		/*
		$comboByCats = intval($this->app->cfgItem ('options.e10doc-sale.cashregItemComboCats', 0));
		if ($comboByCats === 0)
		{
			return;
		}
		$taxReg = E10Utils::primaryTaxRegCfg($this->app());
		$taxCalc = intval($this->app->cfgItem ('options.e10doc-sale.cashRegSalePricesType', 2));
		$taxCalc = E10Utils::taxCalcIncludingVATCode ($this->app(), $this->today, $taxCalc);

		$catPath = $this->app->cfgItem ('e10.witems.categories.list.'.$comboByCats, '---');
		$cats = $this->app->cfgItem ("e10.witems.categories.tree".$catPath.'.cats');
		forEach ($cats as $catId => $cat)
		{
			$catNdx = $cat['ndx'];
			$catKey = 'c'.$catNdx;
			$catRootPath = $this->app->cfgItem ('e10.witems.categories.list.'.$catNdx, '');
			$parts = explode ('.', substr($catRootPath, 1));
			$rootTreeId = 'e10.witems.categories.tree.'.implode('.cats.', $parts);
			$ac = $this->app->cfgItem ($rootTreeId);

			$this->products[$catKey] = ['title' => $cat['shortName'], 'items' => []];

			$q[] = 'SELECT * FROM [e10_witems_items] AS items ';
			array_push ($q, ' WHERE 1');

			\e10\witems\itemCategoryQuery ($ac, $q, 'items');

			array_push ($q, ' ORDER BY items.orderCashRegister, items.fullName');

			$pks = [];
			$rows = $this->db()->query ($q);
			foreach ($rows as $r)
			{
				$askQuantity = $this->askItem('Q', $ac, $r);
				$askPrice = $this->askItem('P', $ac, $r);
				$title = ($r['shortName'] !== '') ? $r['shortName'] : $r['fullName'];
				$item = [
						'title' => $title, 'name' => $r['fullName'], 'pk' => $r['ndx'],
						'price' => E10Utils::itemPriceSell($this->app, $taxReg, $taxCalc, $r),
						'unit' => $r['defaultUnit'], 'unitname' => $this->units[$r['defaultUnit']]['shortcut'],
						'askq' => $askQuantity, 'askp' => $askPrice
				];
				$this->products[$catKey]['items'][$r['ndx']] = $item;
				$pks[] = $r['ndx'];
			}

			unset ($q);
		}
			*/
	}

	protected function composeCode ()
	{
		$this->vendmNdx = 1;

		$c = '';

		//$c .= '<h1>NAZDAR!!!</h1>';

		$vme = new \e10pro\vendms\libs\VendMsEngine($this->app());
		$vme->setVendMs($this->vendmNdx);
		$vme->createCodeMachine();

		//$c .= $vme->code;
		//$c .= $this->composeCodeInitScript();

		$this->uiTemplate->data['machineSelectBoxTable'] = $vme->code;

		$templateStr = $this->uiTemplate->subTemplateStr('modules/e10pro/vendms/subtemplates/vmWidget');
		$c .= $this->uiTemplate->render($templateStr);
		//$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $code]);

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

		//$this->loadProducts();

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
