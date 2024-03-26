<?php

namespace terminals\store;
require_once __SHPD_MODULES_DIR__ . 'e10/witems/tables/itemcategories.php';
use e10\utils, e10doc\core\libs\E10Utils;
use \Shipard\UI\Core\WidgetPane;


/**
 * class WidgetCashBoxNG
 */
class WidgetCashBoxNG extends \Shipard\UI\Core\UIWidgetBoard
{
	var $code;
	var $products = [];
	var $units;

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

    $c .= "<div class='cash-box-container-sell'>";
      $c .= $this->composeCodeDocRows();
      $c .= $this->composeCodeProducts();
    $c .= "</div>";



		$c .= $this->composeCodePay();
		$c .= $this->composeCodeDone();

		$c .= $this->composeCodeInitScript();

		return $c;
	}

	protected function composeCodeDocRows ()
	{
		$enableFullSearch = intval($this->app->cfgItem ('options.e10doc-sale.cashregMobileItemComboSearch', 0));

		$c = '';


    $c .= "<div class='cash-box-rows'>";

    // -- DISPLAY
    $c .= "<div class='cash-box-display'>";
      $c .= "<div class='display d-flex'>";
      $c .= $this->composeCodeSensors();
      $c .= "<div class='e10-terminal-action flex-shrink-1 p-1' data-action='terminal-search-code-manually'>".$this->app()->ui()->icon('system/iconKeyboard')."</div>";
      if ($enableFullSearch)
        $c .= "<div class='e10-terminal-action flex-shrink-1 p-1' data-action='terminal-search-code-combo'>".$this->app()->ui()->icon('system/actionInputSearch')."</div>";

      $c .= "<div class='total number nowrap shp-widget-action w-100 text-end h1 px-2' data-action='terminal-pay' data-pay-method='1'>0.00</div>";
      //$c .= '</ul>';
      $c .= '</div>';

      //$c .= "<ul class='symbol' style='display: none;'>";
      //$c .= "<div class='value flex-shrink-1'></div>";
      //$c .= "<div class='e10-terminal-action icon flex-shrink-1' data-action='terminal-symbol-clear'>".$this->app()->ui()->icon('system/actionInputClear')."</div>";
    $c .= "</div>"; // <-- display

    $c .= "<div class='cash-box-rows-content'>";
      $c .= "<div class='docTermIntro'>";
      $c .= "<div class='infoLogin'>";
        $c .= "<span class='userInfo'>".$this->app()->ui()->icon('system/iconUser').' '.utils::es($this->app->user()->data ('name')).'</span>';
        $c .= "<span class='workplaceInfo'>";
        if ($this->uiRouter->workplace)
          $c .= ' '.$this->app()->ui()->icon('system/iconWorkplace').' '.utils::es($this->uiRouter->workplace['name']);
        else
          $c .= ' '.$this->app()->ui()->icon('system/iconWarning').' '.utils::es('Neznámé pracoviště');
        $c .= "</span>";
      $c .= "</div>";
      $c .= "<div class='infoComments'>";
		  $c .= utils::es('Můžete prodávat');
      $c .= "</div>";
		  //$c .= '<div class="e10-small">'.utils::es('Účtenku vytisknete stisknutím částky vpravo nahoře.').'</div>';
      //$c .= "<button class='btn btn-info pull-right e10-terminal-action' data-action='print-retry' id='terminal-print-retry'>".$this->app()->ui()->icon('system/actionPrint')."</button>";
      $c .= "</div>";


      $c .= "<div class='rows'>";
      $c .= "<table class='rows' class='d-none;'>";
      $c .= '</table>';
		  $c .= '</div>';

		$c .= '</div>';

    $c .= "<div class='cash-box-pay-buttons'>";
		if ($this->enablePaymentButtons)
		{
			if ($this->disablePaymentCards)
			{
				$c .= "<button class='shpd-btn shpd-btn-secondary shp-widget-action' data-action='terminal-pay' data-pay-method='1'>Zaplatit</button>";
			}
			else
			{
				$c .= "<button class='shpd-btn shpd-btn-secondary shp-widget-action' data-action='terminal-pay' data-pay-method='1'>Hotově</button>";
				$c .= "<button class='shpd-btn shpd-btn-secondary shp-widget-action' data-action='terminal-pay' data-pay-method='2'>Kartou</button>";
			}
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
    $c .= "<div class='cash-box-buttons $productsClass'>";

      // -- tabs
      $cbProductsTabsId = 'cb-products';
      $c .= "<div class='cash-box-buttons-tabs'>";
      $tabsCount = count($this->products);
      if ($cntOtherItems)
        $tabsCount++;
      $tabsHidden = 'showed';
      if ($tabsCount < 2)
        $tabsHidden = 'hidden';

      $class = ' active';
      $c .= "<ul class='shpd-pills' id='$cbProductsTabsId-tabs'>";
      foreach ($this->products as $productCatKey => $productCat)
      {
        $c .= "<li class='shpd-pills-item shp-simple-tabs-item $class' data-tabs='$cbProductsTabsId' data-tab-id='{$cbProductsTabsId}-content-$productCatKey'>";
        $c .= "<a class='shpd-pills-link' href='#'>".utils::es ($productCat['title']).'</a>';
        $c .= '</li>';
        $class = '';
      }

      /*
      if ($cntOtherItems)
      {
        $c .= "<li$class data-tabid='e10-wcb-cat-calc_kbd'>";
        $c .= ' '.$this->app()->ui()->icon('system/detailCalculate').' ';
        $c .= '</li>';
      }
      */

      $c .= '</ul>';
      $c .= "</div>";

      // -- products

      $active = 1;
      $c .= "<div class='cash-box-buttons-tabs-content e10-wcb-products-buttons' id='$cbProductsTabsId'>";
      foreach ($this->products as $productCatKey => $productCat)
      {
        $cls = '';
        if (!$active)
          $cls = " d-none";

        $c .= "<div class='products shp-buttons-container$cls' id='$cbProductsTabsId-content-$productCatKey'>";
        foreach ($this->products[$productCatKey]['items'] as $item)
        {
          $c .= "<span class='item cashboxItem shp-widget-action' data-action='addRow'";

          $c .= " data-pk='{$item['pk']}'" . " data-price='{$item['price']}'" . " data-askq='{$item['askq']}'" . " data-askp='{$item['askp']}'" .
                " data-name=\"".utils::es($item['name'])."\"" . " data-unit=\"".utils::es($item['unit'])."\"" . " data-unit-name=\"".utils::es($item['unitname'])."\"";

          if (isset ($item['ean']))
            $c .= " data-ean=\"".utils::es($item['ean'])."\"";

          $c .= '>';


          $c .= utils::es($item['title']);

          $c .= '</span>';
        }
        $c .= '</div>';

        $active = 0;
      }

    /*
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
    */

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

		$c .= "<div class='cash-box-container-pay d-none'>";

    $c .= "<div class='payHeader'>";
      $c .= "Zaplatit a vytisknout";
    $c .= "</div>";

    $c .= "<div class='paymentMethod d-flex flex-column mx-5'>";
      if (!$this->disablePaymentCards)
      {
        $c .= "<input type='radio' class='btn-check'  name='payment-method' id='".$this->widgetId."_payM0' autocomplete='off' data-pay-method='1' value='1' checked>";
        $c .= "<label class='btn btn-outline-danger shp-widget-action' data-action='change-payment-method' data-pay-method='1' for='".$this->widgetId."_payM0'>Hotově</label>";
        $c .= "<br>";
        $c .= "<input type='radio' class='btn-check' name='payment-method' id='".$this->widgetId."_payM1' autocomplete='off' data-pay-method='2' value='2'>";
        $c .= "<label class='btn btn-outline-danger shp-widget-action' data-action='change-payment-method' data-pay-method='2' for='".$this->widgetId."_payM1'>Kartou</label>";
      }
      else
        $c .= "<span class='e10-terminal-action active' data-action='change-payment-method' data-pay-method='1' style='display: none;'>Hotově</span>";
    $c .= "</div>";

    $c .= "<div class='paymentFooter text-end p-3'>";
      $c .= "<button class='shpd-btn shpd-btn-secondary shp-widget-action' data-action='terminal-sell'>".$this->app()->ui()->icon('system/actionBack')." Zpět do kasy</button>";
    $c .= "</div>";

    $c .= "<div class='paymentAmount'>";
          $c .= "<span class='money-to-pay pull-right'>0.00</span>";
    $c .= "</div>";

    $c .= "<div class='paymentDo'>";
      $c .= "<button class='shpd-btn shpd-btn-success shp-widget-action' data-action='terminal-save'>ZAPLACENO</button>";
    $c .= "</div>";

    $c .= "</div>"; // <-- div.cash-box-container-pay

		return $c;
	}

	protected function composeCodeDone ()
	{
		$c = '';

		$c .= "<div class='cash-box-container-save d-none text-center'>";

		$c .= "<div class='h2'>";
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
    $c = '';
		$c = "\n<script>(() => {initWidgetCashBox ('{$this->widgetId}');})();</script>";
		return $c;
	}

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

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
