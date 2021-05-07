<?php

namespace E10Doc\Purchase;


require_once __APP_DIR__ . '/e10-modules/e10/witems/tables/items.php';
require_once __APP_DIR__ . '/e10-modules/e10doc/core/tables/heads.php';

use E10\ContentRenderer;
use \E10\DataModel, e10doc\core\e10utils;
use \E10\TableForm;
use \E10\Application;
use \E10\FormReport;
use E10\uiutils;
use E10\utils;
use \E10Doc\Core\ViewDetailHead;

/**
 * Pohled na Výkupy
 *
 */

class ViewPurchaseDocs extends \E10Doc\Core\ViewHeads
{
	var $warehouses;
	var $paymentMethods;
	public function init ()
	{
		$this->docType = 'purchase';
		parent::init();

		$this->warehouses = $this->table->app()->cfgItem ('e10doc.warehouses', array());
		$this->paymentMethods = $this->table->app()->cfgItem ('e10.docs.paymentMethods');

		forEach ($this->warehouses as $whId => $wh)
			$bt [] = array ('id' => $whId, 'title' => $wh['shortName'], 'active' => 0, 'addParams' => array ('warehouse' => $whId));
		$bt [] = array ('id' => '', 'title' => 'Vše', 'active' => 0);
		$bt [0]['active'] = 1;
		$this->setBottomTabs ($bt);
	}

	public function renderRow ($item)
	{
		$listItem = parent::renderRow ($item);

		$icon = 'x-people-walk';
		if ($item['weighingMachine'] !== 0)
		{
			if ($item['weightGross'] < 999)
				$icon = 'x-transport-car';
			else
				$icon = 'x-transport-truck';
		}
		$listItem ['icon'] = $icon;

		$listItem ['i1']['icon'] = $this->paymentMethods[$item['paymentMethod']]['icon'];
		return $listItem;
	}

	public function selectRows ()
	{
		$wh = $this->bottomTabId ();

		$q [] = 'SELECT heads.[ndx] as ndx, heads.quantity as quantity, [docNumber], [title], heads.[docType] as [docType], [heads].docStateAcc,'.
						' [sumPrice], [sumBase], [sumTotal], [weightGross], [activateTimeFirst], [activateTimeLast], [weighingMachine],[paymentMethod],'.
						' [toPay], [cashBoxDir], [dateIssue], [dateAccounting], [person], [currency], [homeCurrency], [symbol1],'.
						' heads.initState as initState, heads.[docState] as docState, heads.[docStateMain] as docStateMain, persons.fullName as personFullName'.
            ' FROM [e10doc_core_heads] as heads'.
						' LEFT JOIN e10_persons_persons AS persons ON (heads.person = persons.ndx)'.
						' WHERE 1';

		$this->qryCommon ($q);
		$this->qryFulltext ($q);

		// bottomTab
		if ($wh != '')
			array_push ($q, " AND heads.[warehouse] = %i", $this->warehouses[$wh]['ndx']);

		$this->qryMain($q);
		$this->runQuery ($q);
	} // selectRows

} // class ViewPurchaseDocs


/**
 * Základní detail Výkupu
 *
 */

class ViewDetailPurchaseDocs extends ViewDetailHead
{
}


/**
 * Editační formulář Výkupu
 *
 */

class FormPurchaseDocs extends \E10Doc\Core\FormHeads
{
	public function renderForm ()
	{
		$this->checkInfoPanelAttachments('20vw');

		$taxPayer = $this->recData['taxPayer'];

		$this->setFlag ('maximize', 1);
		$this->setFlag ('resetButtons', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('sidebarWidth', '0.36');
		$this->setFlag ('terminal', 1);

		$dsn = $this->recData['docState'];
		if ($dsn === 1200  || $dsn === 1201 || $dsn === 1202 || $dsn === 1203 || $dsn === 1204 || $dsn === 1205 || $dsn === 1206)
		{
			$this->openForm (TableForm::ltVertical);
				$this->addColumnInput ("title", TableForm::coHidden);
				$this->renderForm_Recapitulation();
			$this->closeForm ();
			return;
		}

		$this->openForm (TableForm::ltNone);
			$tabs ['tabs'][] = array ('text' => 'Doklad', 'icon' => 'x-content');
			$this->addAccountingTab ($tabs['tabs']);
			$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'x-attachments');
			$tabs ['tabs'][] = array ('text' => 'Nastavení', 'icon' => 'x-wrench');
			$this->openTabs ($tabs, 'right');

			$this->openTab (TableForm::ltNone);
				$this->layoutOpen (TableForm::ltDocRows, 'e10-terminalForm');
					$this->addList ('rows', '', TableForm::loRowsDisableMove);
				$this->layoutClose ();

				$this->layoutOpen (TableForm::ltDocMain, 'e10-terminalForm');

					$this->layoutOpen (TableForm::ltForm);
						$this->addPicturesWidget ($this->useAttInfoPanel);
						if ($this->recData['weighingMachine'] != 0)
						{
							$inpParams = array ('srcSensor' => $this->recData['weighingMachine']);

							$this->addColumnInput ("weightIn", 0, $inpParams);

							if ($this->table->app()->testGetParam ('__weightOut') !== '')
								$this->addColumnInput ("weightOut", 0, $inpParams);
							else
								$this->addColumnInput ("weightOut");
						}
					$this->layoutClose ();
					$this->layoutOpen (TableForm::ltVertical);
						$this->addColumnInput ("title", DataModel::coSaveOnChange|TableForm::coFocus);
						$this->addColumnInput ("person", DataModel::coSaveOnChange|TableForm::coHeader);
					$this->layoutClose ();

					$regTitle = [['text' => 'Registrace', 'icon' => 'icon-user', 'class' => 'h2']];
					$regTitle[] = [
						'text' => 'Tisk', 'class' => 'pull-right', 'type' => 'action', 'action' => 'printdirect', 'printer' => '1',
						'data-report' => 'e10pro.custreg.RegistrationListReport', 'actionClass' => 'btn-sm',
						'data-table' => 'e10.persons.persons', 'data-pk' => $this->recData['person']
					];
					if (!$this->readOnly)
						$regTitle[] = [
								'text' => 'Upravit', 'class' => 'pull-right', 'docAction' => 'edit',
								'actionClass' => 'btn btn-primary btn-sm', 'type' => 'button', 'icon' => 'icon-edit',
								'table' => 'e10.persons.persons', 'pk' => $this->recData['person'],
								'data-srcobjecttype' => 'form-to-save', 'data-srcobjectid' => $this->fid
						];

					$this->layoutOpen (TableForm::ltForm, 'xxe10-row-info default stripped');
						$this->addStatic($regTitle, TableForm::coHeader);
						//$this->table->addFormPersonProperties ($this);
						$this->addFormPersonInfo();
					$this->layoutClose ();
					/*$this->addStatic('Adresa', TableForm::coHeader);
					$this->layoutOpen (TableForm::ltGrid);
						$this->table->addFormPersonAddress ($this);
					$this->layoutClose ();
*/

					if ($this->recData['personType'] == 2)
					{
						$this->addSeparator(TableForm::coH2);
							$this->layoutOpen(TableForm::ltVertical);
							$this->addColumnInput ('personHandover', TableForm::coHeader);
							$this->addColumnInput ('cashPersonName', TableForm::coColW12);
							$this->addColumnInput ('cashPersonID', TableForm::coColW12);
						$this->layoutClose();
					}
				$this->layoutClose ();
      $this->closeTab ();

			$this->addAccountingTabContent();
			$this->addAttachmentsTabContent ();

			$this->openTab ();
				$this->addColumnInput ("dateIssue");
				$this->addColumnInput ("dateAccounting");
				$this->addColumnInput ('dateDue');
				//$this->addColumnInput ('bankAccount');
				$this->addColumnInput ('myBankAccount');


		if ($taxPayer)
				{
					$this->addColumnInput ("dateTax");
					$this->addColumnInput ("taxCalc");
					$this->addColumnInput ("taxType");
				}
				$this->addColumnInput ("paymentMethod");
				$this->addColumnInput ("symbol1");
				$this->addColumnInput ('symbol2');
				$this->addColumnInput ("roundMethod");
				$this->addColumnInput ("currency");
				$this->addColumnInput ("cashBox");
        $this->addColumnInput ("warehouse");
				$this->addColumnInput ("author");
				$this->addList ('clsf', '', TableForm::loAddToFormLayout);
			$this->closeTab ();

      $this->closeTabs ();

    $this->closeForm ();

		$this->addInfoPanelAttachments();
	}

	function addInfoPanelAttachments($startCode = '')
	{
		if (!$this->useAttInfoPanel)
			return;

		$sc = '';

		if (!isset ($this->recData['ndx']))
		{
			$addPicture = Application::testGetParam ('addPicture');
			if ($addPicture === '' && isset ($this->app()->workplace['startDocumentCamera']))
			{
				if (isset ($this->postData['cameras'][$this->app()->workplace['startDocumentCamera']]))
					$addPicture = $this->postData['cameras'][$this->app()->workplace['startDocumentCamera']];
			}
			if (isset($addPicture))
			{
				$sc .= "<img src='{$addPicture}' style='width: 100%;'/>";
			}
		}

		parent::addInfoPanelAttachments($sc);
	}

	public function renderForm_Recapitulation ()
	{
		$q = "SELECT [rows].text AS rText, [rows].quantity AS rQuantity, [rows].unit AS rUnit, [rows].priceItem AS rPriceItem, [rows].priceAll AS rPriceAll
          FROM [e10doc_core_rows] AS [rows] WHERE [rows].document = %i ORDER BY ndx";

		$cfgUnits = Application::cfgItem ('e10.witems.units');
		$rows = $this->table->db()->query($q, $this->recData ['ndx'])->fetchAll ();
		$list = array ();
		forEach ($rows as $r)
		{
			$unit = $cfgUnits[$r['rUnit']];
			$list[] = array ('text' => $r['rText'], 'quantity' => $r['rQuantity'], 'unit' => $unit['shortcut'],
												'priceItem' => $r['rPriceItem'], 'priceAll' => $r['rPriceAll']);
		}

		$h = array ('#' => '#', 'text' => 'text',
								'quantity' => ' Množství', 'unit' => ' Jed.', 'priceItem' => ' Cena/jed', 'priceAll' => ' Celkem');

		$params = array ('forceTableClass' => 'default pull-right');

		$currencyName = $this->table->app()->cfgItem ('e10.base.currencies.'.$this->recData['currency'].'.shortcut');

		$c = '';
		$c .= "<div class='docRecapitulation' style='padding: .2em;'>";
		$c .= "<h1 style='padding-bottom: .2em; text-align: right;'>";

		switch ($this->recData['paymentMethod'])
		{
			case	0: $c .= 'Bude uhrazeno na účet:'; break;
			case	1: $c .= 'Zaplatit v hotovosti:'; break;
			case	4: $c .= 'Celkem k fakturaci:'; break;
			case	6: $c .= 'Celkem k vyúčtování:'; break;
			case	8: $c .= 'Likvidační protokol:'; break;
			case	9: $c .= 'Vystavit šek:'; break;
			case	10: $c .= 'Bude uhrazeno poštovní poukázkou:'; break;
		}

		$c .= ' '.\E10\nf ($this->recData ['toPay']).' '.$currencyName.'</h1>';

		if ($this->recData['paymentMethod'] == 0)
		{
			$c .= "<h3 style='margin-top: -1em; text-align: right;'>";
			if ($this->recData['bankAccount'] == '')
				$c .= "<span class='e10-error'>".'Není zadáno číslo účtu!'.'</span>';
			else
				$c .= 'Účet: '.$this->recData['bankAccount'];
			$c .= '</h3>';
		}

		$c .= \E10\renderTableFromArray ($list, $h, $params);

		$c .= '</div>';

		if (isset ($this->table->app()->workplace['endDocumentCamera']))
		{
			if (isset ($this->postData['cameras'][$this->table->app()->workplace['endDocumentCamera']]))
			{
				$addPicture = $this->postData['cameras'][$this->table->app()->workplace['endDocumentCamera']];
				$this->recData['_addPicture'] = $addPicture;
				$ip = $this->option ('inputPrefix', '');
				$c .= "<input type='hidden' name='{$ip}_addPicture' data-fid='{$this->fid}'/>";
			}
		}

		$this->layoutOpen(TableForm::ltVertical);
			$this->appendCode($c);
		$this->layoutClose('padd5 number');

		$this->layoutOpen(TableForm::ltHorizontal);
			$this->layoutOpen(TableForm::ltForm);
				$this->table->addFormPrintAfterConfirm ($this);

				if ($this->recData['paymentMethod'] === 9)
				{
					$this->addColumnInput('symbol2', TableForm::coFocus);
					$this->appendElement(uiutils::addScanToDocumentInputCode('e10doc.core.heads', $this->recData['ndx']), NULL, 'e10-wsh-h2b');
				}
			$this->layoutClose('width50 padd5 number');
		$this->layoutClose();
	}


	public function checkNewRec ()
	{
		parent::checkNewRec ($this->recData);

		if (isset ($this->table->app()->workplace['cashBox']))
			$this->recData ['cashBox'] = $this->table->app()->workplace['cashBox'];

		$this->recData ['roundMethod'] = intval(Application::cfgItem ('options.e10doc-buy.roundPurchase', 1));
		$this->recData ['taxCalc'] = 0;
		$this->recData ['dateDue'] = utils::today();
	}

	function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case	'person': return 'Dodavatel';
			case	'personHandover': return 'Předávající';
			case	'bankAccount': return 'Bankovní účet pro úhradu';
			case	'symbol2': if ($this->recData['paymentMethod'] === 9) return 'Číslo šeku'; break;
			case	'cashPersonName': return 'Jméno';
			case	'cashPersonID': return 'OP';
    }
    return parent::columnLabel ($colDef, $options);
  }

	public function comboViewer ($srcTableId, $srcColDef, $srcColumnId, $allRecData, $recData, $viewerId = 'default')
	{
		if ($srcTableId === 'e10doc.core.heads' && $srcColumnId === 'person')
			return parent::comboViewer ($srcTableId, $srcColDef, $srcColumnId, $allRecData, $recData, 'e10pro.purchase.ViewSuppliers');
		return parent::comboViewer ($srcTableId, $srcColDef, $srcColumnId, $allRecData, $recData, $viewerId);
	}


	public function addFormPersonInfo ()
	{
		$personNdx = $this->recData['person'];

		// -- properties
		$properties = e10utils::loadProperties($this->app(), $personNdx);
		if (isset($properties[$personNdx]['contacts']))
			$this->addStatic($properties[$personNdx]['contacts']);
		if (isset($properties[$personNdx]['ids']))
			$this->addStatic($properties[$personNdx]['ids']);
		// -- bank accounts
		if ($this->readOnly)
		{
			$this->addStatic(['text' => $this->recData['bankAccount'], 'icon' => 'icon-institution']);
		}
		else
		{
			$bankAccounts = [];
			if (isset($properties[$personNdx]['payments']))
			{
				foreach ($properties[$personNdx]['payments'] as $ba)
				{
					$bankAccounts[$ba['text']] = ['text' => $ba['text']];
					if (isset($ba['prefix']))
						$bankAccounts[$ba['text']]['suffix'] = $ba['prefix'];
				}
				if ($this->recData['bankAccount'] === '')
					$this->recData['bankAccount'] = key($bankAccounts);
				if (!isset($bankAccounts[$this->recData['bankAccount']]))
					$bankAccounts[$this->recData['bankAccount']] = $this->recData['bankAccount'];
			}
			if (count($bankAccounts))
			{
				$this->addStatic(['text' => 'Účet pro úhradu:', 'icon' => 'icon-institution', 'class' => 'block']);
				$this->addInputEnum2('bankAccount', NULL, $bankAccounts);
			}
		}

		// -- address
		$addresses = [];
		$deliveryAddress = 0;
		$addressesOther1 = [];
		$other1Address = 0;

		$q = 'SELECT * FROM [e10_persons_address] WHERE tableid = %s AND recid = %i';
		$rows = $this->table->db()->query ($q, 'e10.persons.persons', $personNdx);
		foreach ($rows as $r)
		{
			$title = [];
			if ($r['specification'] !== '')
				$title[] = ['text' => $r['specification']];
			if ($r['street'] !== '')
				$title[] = ['text' => $r['street']];
			$title[] = ['text' => $r['city'].' '.$r['zipcode']];

			if ($r['type'] !== 99)
			{
				$addresses[$r['ndx']] = $title;
				if ($r['type'] == 4)
					$deliveryAddress = $r['ndx'];
			}
			if ($r['type'] === 99)
			{
				$addressesOther1[$r['ndx']] = $title;
				$other1Address = $r['ndx'];
			}
		}
		$this->addFormPersonInfo_Address ($addresses, 'deliveryAddress', $deliveryAddress, 'Doručovací adresa');
		$this->addFormPersonInfo_Address ($addressesOther1, 'otherAddress1', $other1Address, 'Provozovna');
	}

	public function addFormPersonInfo_Address ($addresses, $columnId, $suggestedAddressNdx, $labelText)
	{
		if ($this->readOnly)
		{
			if ($this->recData[$columnId])
			{
				$a = $addresses[$this->recData[$columnId]];
				$a[0]['icon'] = 'icon-home';
				$this->addStatic($a);
			}
		}
		else
		{
			$this->addStatic(['text' => $labelText, 'icon' => 'icon-home', 'class' => 'block']);
			$this->addInputEnum2($columnId, NULL, $addresses);
			if ($this->recData[$columnId] === 0 || !isset($addresses[$this->recData[$columnId]]))
				$this->recData[$columnId] = ($suggestedAddressNdx) ? $suggestedAddressNdx : key($addresses);
		}
	}
} // class FormPurchaseDocs


/**
 * Editační formulář Řádku Výkupu
 *
 */

class FormPurchaseDocsRows extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->addColumnInput ("text", TableForm::coHidden);
			$this->addColumnInput ("unit", TableForm::coHidden);
			$this->addColumnInput ("itemType", TableForm::coHidden);
			$this->addColumnInput ("itemBalance", TableForm::coHidden);
			$this->addColumnInput ("itemIsSet", TableForm::coHidden);

			$this->openRow ();
				$this->addColumnInput ("item", TableForm::coInfoText|TableForm::coNoLabel|TableForm::coColW12);
			$this->closeRow ();

			$this->openRow ('right');
				$this->addColumnInput ("quantity", TableForm::coColW4);
				$inpParams = array ('plusminus' => 'smart');
				$this->addColumnInput ("priceItem", TableForm::coColW4, $inpParams);
			$this->closeRow ();
		$this->closeForm ();
	}

  function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
			case'quantity': return $this->app()->cfgItem ('e10.witems.units.'.$this->recData['unit'].'.shortcut');
			case'priceItem': return 'Kč/'.$this->app()->cfgItem ('e10.witems.units.'.$this->recData['unit'].'.shortcut');
    }
    return parent::columnLabel ($colDef, $options);
  }

} // class FormPurchaseRows


/**
 *
 */

class ViewItemsForPurchase extends \E10\Witems\ViewItems
{
	public function init ()
	{
		parent::init();

		$this->withInventory = FALSE;
		$this->showPrice = self::PRICE_BUY;
		$this->itemKind = FALSE;

		if (intval($this->table->app()->cfgItem ('options.e10doc-buy.purchItemComboSearch', 0)) === 0)
			$this->enableFullTextSearch = FALSE;

		unset ($this->mainQueries); // TODO: better way

		$comboByCats = intval($this->table->app()->cfgItem ('options.e10doc-buy.purchItemComboCats', 0));
		$defaultCat = intval($this->table->app()->cfgItem ('options.e10doc-buy.purchItemDefaultComboCat', 0));

		$allId = '';
		if ($comboByCats)
			$allId = 'c'.$comboByCats;

		$bt [] = ['id' => $allId, 'title' => 'Vše', 'active' => ($defaultCat === 0) ? 1 : 0];
		$comboByTypes = intval($this->table->app()->cfgItem ('options.e10doc-buy.purchItemComboByTypes', 0));
		if ($comboByTypes)
		{
			$itemTypes = $this->table->app()->cfgItem ('e10.witems.types');

			forEach ($itemTypes as $itemTypeId => $itemType)
			{
				if ($itemTypeId === 'none')
					continue;
				$bt [] = array ('id' => 't'.$itemTypeId, 'title' => $itemType['shortName'], 'active' => 0,
					'addParams' => array ('type' => $itemTypeId));
			}
		}

		if ($comboByCats !== 0)
		{
			$catPath = $this->table->app()->cfgItem ('e10.witems.categories.list.'.$comboByCats, '---');
			$cats = $this->table->app()->cfgItem ("e10.witems.categories.tree".$catPath.'.cats');
			forEach ($cats as $catId => $cat)
			{
				$bt [] = ['id' => 'c'.$cat['ndx'], 'title' => $cat['shortName'], 'active' => ($defaultCat == $cat['ndx']) ? 1 : 0];
			}
		}

		if (count ($bt) > 1)
			$this->setTopTabs ($bt);
	}

	public function qryColumns (array &$q)
	{
		if ($this->activeCategory !== FALSE && $this->activeCategory['si'] === 'top')
		{
			array_push($q, ', (SELECT cnt FROM e10doc_base_statsItemDocType WHERE docType = %s AND items.ndx = item) as cnt', 'purchase');
		}
		else
		if ($this->activeCategory !== FALSE && $this->activeCategory['si'] === 'person')
		{
			$person = $this->queryParam('person');
			if ($person)
			{
				array_push($q, ', (SELECT cnt FROM e10doc_base_statsPersonItemDocType WHERE docType = %s AND person = %i AND items.ndx = item) as cnt1', 'purchase', $person);
				array_push($q, ', (SELECT cnt FROM e10doc_base_statsItemDocType WHERE docType = %s AND items.ndx = item) as cnt2', 'purchase');
			}
			else
				array_push($q, ', (SELECT cnt FROM e10doc_base_statsItemDocType WHERE docType = %s AND items.ndx = item) as cnt', 'purchase');
		}
	}

	public function qryOrder (array &$q, $mainQueryId)
	{
		if ($this->activeCategory !== FALSE && $this->activeCategory['si'] === 'person')
		{
			$person = $this->queryParam('person');
			if ($person)
				array_push($q, ' ORDER BY cnt1 DESC, cnt2 DESC, [items].[fullName]');
			else
				array_push($q, ' ORDER BY cnt DESC, [items].[fullName]');
		}
		else
		if ($this->activeCategory !== FALSE && $this->activeCategory['si'] === 'top')
		{
			array_push($q, ' ORDER BY cnt DESC, [items].[fullName]');
		}
		else
		if ($this->activeCategory !== FALSE && $this->activeCategory['si'] === 'cashreg')
		{
			array_push($q, ' ORDER BY orderCashRegister, [items].[fullName]');
		}
		else
			parent::qryOrder($q, $mainQueryId);
	}

	public function renderRow ($item)
	{
		$thisItemType = $this->table->itemType ($item, TRUE);

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['tt'] = $item['shortName'];
		$listItem ['icon'] = $this->table->icon ($item);

		if ($thisItemType['kind'] !== 2)
		{
			$listItem ['i2'] = ['text' => ''];

			if ($this->showPrice === self::PRICE_SALE)
			{
				if ($item['priceSell'])
					$listItem ['i2'] = ['text' => utils::nf($item['priceSell'], 2)];
			}
			else
			if ($this->showPrice === self::PRICE_BUY)
			{
				if ($item['priceBuy'])
					$listItem ['i2'] = ['text' => utils::nf($item['priceBuy'], 2)];
			}

			if ($item['defaultUnit'] !== '')
				$listItem ['i2']['prefix'] = $this->units[$item['defaultUnit']]['shortcut'];
		}
/*
		if (!isset ($this->defaultType))
		{
			$listItem ['t2'] = $this->table->itemType ($item);
			if ($item['useFor'] !== 0)
			{
				$useFor = $this->table->columnInfoEnum ('useFor', 'cfgText');
				$listItem ['t2'] .= ' / '.$useFor [$item ['useFor']];
			}
		}
*/

		if ($item['groupCashRegister'] !== '' && $this->activeCategory !== FALSE && $this->activeCategory['si'] === 'cashreg')
			$this->addGroupHeader ($item['groupCashRegister']);

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->itemsStates [$item ['pk']]))
			$item ['i2'] = \E10\nf ($this->itemsStates [$item ['pk']]['quantity'], 2).' '.$this->itemsStates [$item ['pk']]['unit'] .
					(isset($item ['i2']['text']) ? ' / '.$item ['i2']['text'] : '');
	}

} // class ViewItemsForPurchase


/**
 * Class PurchaseReport
 * @package E10Doc\Purchase
 *
 * Výstupní sestava výkupního lístku
 */
class PurchaseReport extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		$this->reportId = 'e10doc.purchase.purchase';
		$this->reportTemplate = 'e10doc.purchase.purchase';
	}

	public function loadData ()
	{
		parent::loadData();

		if ($this->recData ['paymentMethod'] === 4) // fakturace
			$this->data ['flags']['reportTitle'] = 'vážní lístek - podklad pro fakturu';
		else
		if ($this->recData ['paymentMethod'] === 6) // sběrný doklad
			$this->data ['flags']['reportTitle'] = 'sběrný vážní lístek';
		else
		if ($this->recData ['paymentMethod'] === 8) // likvidační protokol
			$this->data ['flags']['reportTitle'] = 'likvidační protokol';
		else
		if ($this->recData ['paymentMethod'] === 0) // příkaz k úhradě
			$this->data ['flags']['reportTitle'] = 'bezhotovostní platba';
		else
		if ($this->recData ['paymentMethod'] === 9) // šek
			$this->data ['flags']['reportTitle'] = 'úhrada šekem';
		else
		if ($this->recData ['paymentMethod'] === 10) // poštovní poukázka
			$this->data ['flags']['reportTitle'] = 'úhrada složenkou';
		else
			$this->data ['flags']['reportTitle'] = 'výdajový pokladní doklad';

		if ($this->recData['personType'] == 2)
		{
			$this->data ['person']['hotovostPrevzalJmeno'] = $this->recData['cashPersonName'];
			$this->data ['person']['hotovostPrevzalOP'] = $this->recData['cashPersonID'];
		}
		else
		{
			$this->data ['person']['hotovostPrevzalJmeno'] = $this->data ['person']['fullName'];
			forEach ($this->data ['person']['lists']['properties'] as $iii)
			{
				if ($iii['property'] === 'idcn')
					$this->data ['person']['hotovostPrevzalOP'] = $iii['value'];
			}
		}
		if ($this->recData ['paymentMethod'] !== 8) // likvidační protokol
			$this->data ['flags']['enablePrice'] = 1;

		$rowNumber = 1;
		forEach ($this->data ['rows'] as &$row)
		{
			$row ['rowNumber'] = $rowNumber;
			$rowNumber++;
			$row ['rowItemProperties'] = \E10\Base\getPropertiesTable ($this->table->app(), 'e10.witems.items', $row['item']);
		}
	}
} // class PurchaseReport


/**
 * Class PurchaseReportPos
 * @package E10Doc\Purchase
 */
class PurchaseReportPos extends PurchaseReport
{
	function command ($cmd)
	{
		$this->objectData ['mainCode'] .= chr($cmd);
	}

	function init()
	{
		$this->reportMode = FormReport::rmPOS;
		$this->mimeType = 'application/x-octet-stream';

		parent::init();

		$this->reportId = 'e10doc.purchase.purchasepos';
		$this->reportTemplate = 'e10doc.purchase.purchasepos';
	}
}
