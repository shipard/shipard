<?php

namespace terminals\store;
require_once __SHPD_MODULES_DIR__ . 'e10/witems/tables/itemcategories.php';
use e10\utils, e10doc\core\libs\E10Utils;
use \Shipard\UI\Core\WidgetPane;


/**
 * Class WidgetCashBox
 * @package terminals\store
 */
class WidgetCashBox extends WidgetPane
{
	var $code;
	var $products = [];
	var $units;

	var $embeddMode = 0;

	var $today = NULL;

	var $disablePaymentCards = 0;
	var $enablePaymentButtons = 0;

	protected function loadProducts ()
	{
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

			// -- append EANs
			if (count($pks))
			{
				$eans = $this->db()->query (
						'SELECT * FROM [e10_base_properties] WHERE [property] = %s', 'ean', ' AND tableid = %s', 'e10.witems.items',
						' AND recid IN %in', $pks);
				foreach ($eans as $ean)
				{
					$this->products[$catKey]['items'][$ean['recid']]['ean'] = $ean['valueString'];
				}
			}

			unset ($q);
		}

	}

	protected function askItem ($askType, $catCfg, $item)
	{
		$askResult = -1;

		$shortKey = 'ask'.$askType.'CR';
		$longKey = 'ask'.$askType.'CashRegister';

		if ($catCfg[$shortKey] === 2)
			$askResult = 1;
		elseif ($catCfg[$shortKey] === 1)
			$askResult = 0;

		if ($item[$longKey] === 2)
			$askResult = 1;
		elseif ($item[$longKey] === 1)
			$askResult = 0;

		if ($askResult === -1 && $askType === 'Q' && $item['defaultUnit'] !== 'pcs')
			$askResult = 1;

		if ($askResult === -1)
			$askResult = 0;

		return $askResult;
	}

	protected function composeCode ()
	{
		$c = '';

		$c .= $this->composeCodeDocRows();
		$c .= $this->composeCodeProducts();
		$c .= $this->composeCodePay();
		$c .= $this->composeCodeDone();
		$c .= $this->composeCodeInitScript();
		return $c;
	}

	protected function composeCodeDocRows ()
	{
		$enableFullSearch = intval($this->app->cfgItem ('options.e10doc-sale.cashregMobileItemComboSearch', 0));

		$c = '';

		$c .= "<div class='e10-wcb-docrows'>";

		$c .= "<ul class='display'>";

		$c .= $this->composeCodeSensors();
		$c .= "<li class='e10-terminal-action' data-action='terminal-search-code-manually'>".$this->app()->ui()->icon('system/iconKeyboard')."</li>";
		if ($enableFullSearch)
			$c .= "<li class='e10-terminal-action' data-action='terminal-search-code-combo'>".$this->app()->ui()->icon('system/actionInputSearch')."</li>";

		$c .= "<li class='total number nowrap e10-terminal-action' data-action='terminal-pay'>0.00</li>";
		$c .= '</ul>';

		$c .= "<ul class='symbol' style='display: none;'>";
		$c .= "<li class='value'></li>";
		$c .= "<li class='e10-terminal-action icon' data-action='terminal-symbol-clear'>".$this->app()->ui()->icon('system/actionInputClear')."</li>";
		$c .= '</ul>';

		$c .= "<div class='close'>";
		$c .= '<div class="h1">';
		$c .= $this->app()->ui()->icon('system/iconUser').' '.utils::es($this->app->user()->data ('name'));

		if ($this->app->workplace)
			$c .= ' '.$this->app()->ui()->icon('system/iconWorkplace').' '.utils::es($this->app->workplace['name']);
		else
			$c .= ' '.$this->app()->ui()->icon('system/iconWarning').' '.utils::es('Neznámé pracoviště');

		$c .= '</div>';
		$c .= "<div>";


		if (!$this->embeddMode)
			$c .= "<button class='link btn btn-info' id='e10-back-button' data-path='#start'>".$this->app()->ui()->icon('system/actionBack')." Konec	</button>";

		$c .= "<button class='btn btn-info pull-right e10-terminal-action' data-action='print-retry' id='terminal-print-retry'>".$this->app()->ui()->icon('system/actionPrint')."</button>";
		$c .= '</div>';
		$c .= '<b>'.utils::es('Můžete prodávat').'</b><br/>';
		$c .= '<div class="e10-small">'.utils::es('Účtenku vytisknete stisknutím částky vpravo nahoře.').'</div>';
		$c .= '</div>';

		$c .= "<div class='rows' style='display: none;'>";
		$c .= "<table class='rows'>";
		$c .= '</table>';

		if ($this->enablePaymentButtons)
		{
			$c .= "<div class='payButtons'>";
			if ($this->disablePaymentCards)
			{
				$c .= "<span class='e10-terminal-action' data-action='do-payment-method' data-pay-method='1'>Zaplatit</span>";
			}
			else
			{
				$c .= "<span class='e10-terminal-action' data-action='do-payment-method' data-pay-method='1'>Hotově</span>";
				$c .= "<span class='e10-terminal-action' data-action='do-payment-method' data-pay-method='2'>Kartou</span>";
			}
			$c .= '</div>';
		}

		$c .= '</div>';

		$c .= '</div>';

		return $c;
	}

	protected function composeCodeSensors ()
	{
		$wss = $this->app->webSocketServers();
		$srvidx = 0;

		$c = '';

		forEach ($wss as $srv)
		{
			forEach ($srv['sensors'] as $sensor)
			{
				if (count($sensor['devices']) && !in_array ($this->app->deviceId, $sensor['devices']))
					continue;

				if ($sensor['class'] !== 'barcode')
					continue;

				$title = utils::es ($sensor['name']);

				$allwaysOn = $sensor['allwaysOn'] ? ' allwaysOn' : ' e10-sensor-on';

				$sensorIcon = $this->app()->ui()->icons()->cssClass($sensor['icon']);
				$c .= "<li class='e10-sensor{$allwaysOn}' data-sensorid='{$sensor['ndx']}' data-serveridx='$srvidx' id='wss-{$srv['ndx']}-{$sensor['ndx']}' data-call-function='e10.terminal.barcode' title=\"$title\">";
				$c .= "<span><i class='$sensorIcon'/></i>";
				$c .= '</span></li>';
			}
			$srvidx++;
		}

		return $c;
	}

	protected function composeCodeProducts ()
	{
		$otherItems = [];
		$otherItem1Ndx = intval($this->app->cfgItem ('options.e10doc-sale.cashregItemOtherTaxRate1', 0));
		$otherItem2Ndx = intval($this->app->cfgItem ('options.e10doc-sale.cashregItemOtherTaxRate2', 0));
		$otherItem3Ndx = intval($this->app->cfgItem ('options.e10doc-sale.cashregItemOtherTaxRate3', 0));

		$cntOtherItems = 0;
		if ($otherItem1Ndx)
		{
			$otherItems[$cntOtherItems] = ['ndx' => $otherItem1Ndx, 'rate' => 1];
			$cntOtherItems++;
		}
		if ($otherItem2Ndx)
		{
			$otherItems[$cntOtherItems] = ['ndx' => $otherItem2Ndx, 'rate' => 2];
			$cntOtherItems++;
		}
		if ($otherItem3Ndx)
		{
			$otherItems[$cntOtherItems] = ['ndx' => $otherItem3Ndx, 'rate' => 3];
			$cntOtherItems++;
		}
		$otherItemsPos = 0;

		$c = '';

		$productsClass = 'e10-wcb-products';
		if ($this->app->oldBrowser)
			$productsClass .= ' e10-no-flex';
		$c .= "<div class='$productsClass'>";

		// -- tabs
		$tabsCount = count($this->products);
		if ($cntOtherItems)
			$tabsCount++;
		$tabsHidden = 'showed';
		if ($tabsCount < 2)
			$tabsHidden = 'hidden';

		$class = " class='active'";
		$c .= "<ul class='tabs $tabsHidden' id='e10-wcb-products-tabs'>";
		foreach ($this->products as $productCatKey => $productCat)
		{
			$c .= "<li$class data-tabid='e10-wcb-cat-$productCatKey'>";
			$c .= utils::es ($productCat['title']);
			$c .= '</li>';

			$class = '';
		}

		if ($cntOtherItems)
		{
			$c .= "<li$class data-tabid='e10-wcb-cat-calc_kbd'>";
			$c .= ' '.$this->app()->ui()->icon('system/detailCalculate').' ';

			$c .= '</li>';
		}

		$c .= '</ul>';

		// -- products

		$active = 1;
		$c .= "<div class='e10-wcb-products-buttons'>";
		foreach ($this->products as $productCatKey => $productCat)
		{
			$c .= "<div class='products' id='e10-wcb-cat-$productCatKey'";

			if (!$active)
				$c .= "style='display:none;'";
			$c .= '>';
			foreach ($this->products[$productCatKey]['items'] as $item)
			{
				$c .= "<span class='item'";

				$c .= " data-pk='{$item['pk']}'" . " data-price='{$item['price']}'" . " data-askq='{$item['askq']}'" . " data-askp='{$item['askp']}'" .
							" data-name=\"".utils::es($item['name'])."\"" . " data-unit=\"".utils::es($item['unit'])."\"" . " data-unit-name=\"".utils::es($item['unitname'])."\"";

				if (isset ($item['ean']))
					$c .= " data-ean=\"".utils::es($item['ean'])."\"";

				$c .= '>';

				$c .= "<div>";
				$c .= utils::es($item['title']);
				$c .= "</div>";
				$c .= '</span>';
			}
			$c .= '</div>';

			$active = 0;
		}

		if ($cntOtherItems)
		{
			$c .= "<div class='calc-kbd' id='e10-wcb-cat-calc_kbd'";
			if (!$active)
				$c .= "style='display:none;'";
			$c .= '>';

			$c .= "<table class='e10-calc-keyboard'>";

			$c .= "<tr>";
			$c .= "<td class='d e10-trigger-ck' colspan='3' id='e10-display-ck'></td><td class='b e10-trigger-ck'>".$this->app()->ui()->icon('system/actionBack')."</td>";
			$c .= "</tr>";

			$c .= "<tr>";
			$c .= "<td class='n e10-trigger-ck'>7</td><td class='n e10-trigger-ck'>8</td><td class='n e10-trigger-ck'>9</td>";

			$c .= "<td class='multiply e10-trigger-ck'>".$this->app()->ui()->icon('system/actionInputClear')."</td>";

			$c .= "</tr>";

			$c .= "<tr>";
			$c .= "<td class='n e10-trigger-ck'>4</td><td class='n e10-trigger-ck'>5</td><td class='n e10-trigger-ck'>6</td>";
			if ($cntOtherItems > 0)
			{
				$c .= $this->composeCodeProducts_calcKbdItem($otherItems[$otherItemsPos], $cntOtherItems, 0);
				$otherItemsPos++;
			}
			$c .= "</tr>";

			$c .= "<tr>";
			$c .= "<td class='n e10-trigger-ck'>1</td><td class='n e10-trigger-ck'>2</td><td class='n e10-trigger-ck'>3</td>";
			if ($cntOtherItems > 2)
			{
				$c .= $this->composeCodeProducts_calcKbdItem($otherItems[$otherItemsPos], $cntOtherItems, 1);
				$otherItemsPos++;
			}
			$c .= "</tr>";

			$c .= "<tr>";
			$c .= "<td class='n e10-trigger-ck' colspan='2'>0</td><td class='n e10-trigger-ck'>,</td>";
			if ($cntOtherItems > 1)
			{
				$c .= $this->composeCodeProducts_calcKbdItem($otherItems[$otherItemsPos], $cntOtherItems, 2);
				$otherItemsPos++;
			}
			$c .= "</tr>";

			$c .= "</table>";

			$c .= '</div>';
		}

		$c .= '</div>';

		$c .= '</div>';

		return $c;
	}

	function composeCodeProducts_calcKbdItem ($itemInfo, $cntOtherItems, $posKey)
	{
		$rates = ['1' => '21%', '2' => '15%', '3' => '10%'];

		$itemNdx = $itemInfo['ndx'];
		$itemRate = $itemInfo['rate'];

		$itemName = 'Ostatní prodej';
		if ($cntOtherItems > 1)
			$itemName .= ' '.$rates[$itemRate];

		$keySuffix = '';
		if ($cntOtherItems > 1)
			$keySuffix = ' '.$rates[$itemRate];

		$rowSpan = 3 - $cntOtherItems;
		if ($posKey === 0 && $cntOtherItems < 3)
			$rowSpan++;

		$c = '';
		$c .= "<td class='ok e10-trigger-ck' rowspan='$rowSpan'";

		$c .= " data-pk='{$itemNdx}'" . " data-price='0'" . " data-askq='0'" . " data-askp='0'" .
			" data-name=\"".utils::es($itemName)."\"" . " data-unit=\"".utils::es('pcs')."\"" . " data-unit-name=\"".utils::es('ks')."\"";

		if (!$posKey)
			$c .= " id='e10-terminal-ck-primary'";

		$c .= "><i class='fa fa-check'></i>$keySuffix</td>";

		return $c;
	}

	protected function composeCodePay ()
	{
		$c = '';

		$c .= "<div class='e10-wcb-pay' style='display: none;'>";

		$c .= "<div class='pay-left'>";
			$c .= "<div class='header'>";
			$c .= "Zaplatit a vytisknout";
			$c .= '</div>';

			$c .= "<div class='pay-methods'>";
			if (!$this->disablePaymentCards)
			{
				$c .= "<span class='e10-terminal-action active' data-action='change-payment-method' data-pay-method='1'>Hotově</span>";
				$c .= "<span class='e10-terminal-action' data-action='change-payment-method' data-pay-method='2'>Kartou</span>";
			}
			else
				$c .= "<span class='e10-terminal-action active' data-action='change-payment-method' data-pay-method='1' style='display: none;'>Hotově</span>";

		$c .= '</div>';
		$c .= '</div>';

		$c .= "<div class='pay-right'>";
			$c .= "<div class='pay-display'>";
			$c .= "<span class='money-to-pay pull-right'>0.00</span>";
			$c .= '</div>';

			$c .= "<div class='done-buttons'>";
			$c .= "<button class='btn btn-primary e10-terminal-action' data-action='terminal-done'>ZAPLACENO</button>";
			$c .= '</div>';
		$c .= '</div>';

		$c .= "<div class='back-buttons'>";
		$c .= "<button class='btn btn-warning e10-terminal-action' data-action='terminal-cashbox'>".$this->app()->ui()->icon('system/actionBack')." Zpět do kasy</button>";
		$c .= '</div>';


		$c .= '</div>';

		return $c;
	}

	protected function composeCodeDone ()
	{
		$c = '';

		$c .= "<div class='e10-wcb-done' style='display: none;'>";

		$c .= "<div class='header'>";
		$c .= "Účtenka se ukládá";
		$c .= '</div>';

		$c .= "<div class='done-status'>";
		$c .= '</div>';


		$c .= "<div class='done-buttons' style='display: none;'>";
			$c .= "<button class='btn btn-primary e10-terminal-action' data-action='terminal-retry'>Zkusit to znovu</button>";
			$c .= "<button class='btn btn-primary e10-terminal-action' data-action='terminal-queue'>Vyřešit to později</button>";
		$c .= '</div>';

		$c .= "<div class='print-buttons' style='display: none;'>";
			$c .= "<button class='btn btn-primary e10-terminal-action' data-action='print-retry'><i class='fa fa-repeat'></i> Vytisknout znovu</button>";
			$c .= "<button class='btn btn-primary e10-terminal-action' data-action='print-exit'>Pokračovat</button>";
		$c .= '</div>';


		$c .= '</div>';

		return $c;
	}

	protected function composeCodeInitScript ()
	{
		//$c = "<script>e10.terminal.init ('{$this->widgetId}');</script>";
		$c = "<script>$(function () {e10.terminal.init ('{$this->widgetId}');});</script>";
		return $c;
	}


	public function createContent ()
	{
		$emp = $this->app()->testGetParam('embeddMode');
		if ($emp === '1')
			$this->embeddMode = 1;

		$this->today = new \DateTime();

		$this->disablePaymentCards = intval($this->app->cfgItem ('options.e10doc-sale.cashregMobileDisablePaymentCards', 0));
		$this->enablePaymentButtons = intval($this->app->cfgItem ('options.e10doc-sale.cashregMobileEnablePaymentButtons', 0));

		$this->widgetSystemParams['data-cashbox'] = ($this->app->workplace && $this->app->workplace['cashBox']) ? $this->app->workplace['cashBox'] : 1;
		$this->widgetSystemParams['data-warehouse'] = 0;

		$this->widgetSystemParams['data-taxcalc'] = intval($this->app->cfgItem ('options.e10doc-sale.cashRegSalePricesType', 2));
		$this->widgetSystemParams['data-taxcalc'] = E10Utils::taxCalcIncludingVATCode ($this->app(), $this->today, $this->widgetSystemParams['data-taxcalc']);

		$this->widgetSystemParams['data-roundmethod'] = 1;

		$this->units = $this->app->cfgItem ('e10.witems.units');

		$this->loadProducts();

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
