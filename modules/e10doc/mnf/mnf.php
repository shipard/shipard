<?php

namespace E10Doc\Mnf;
require_once __SHPD_MODULES_DIR__ . 'e10/witems/tables/items.php';
use \E10\DataModel, \E10\TableForm, \E10Doc\Core\ViewDetailHead, E10Doc\Core\e10utils;

/**
 * Pohled na Výrobu
 *
 */

class ViewMnfDocs extends \E10Doc\Core\ViewHeads
{
	var $warehouses;
	var $paymentMethods;
	public function init ()
	{
		$this->docType = 'mnf';
		parent::init();

		$this->warehouses = $this->table->app()->cfgItem ('e10doc.warehouses', array());

		forEach ($this->warehouses as $whId => $wh)
			$bt [] = array ('id' => $whId, 'title' => $wh['shortName'], 'active' => 0, 'addParams' => array ('warehouse' => $whId));

		$bt [] = array ('id' => '', 'title' => 'Vše', 'active' => 0);
		$bt [0]['active'] = 1;
		$this->setBottomTabs ($bt);
	}

	public function renderRow ($item)
	{
		$listItem = parent::renderRow ($item);

		$icon = 'iconProduction';
		$listItem ['icon'] = $icon;


		return $listItem;
	}

	public function selectRows ()
	{
		$wh = $this->bottomTabId ();

		$q [] = 'SELECT heads.[ndx] as ndx, heads.quantity as quantity, [docNumber], [title], heads.[docType] as [docType],
						[sumPrice], [sumBase], [sumTotal], [weightGross], [activateTimeFirst], [activateTimeLast], [paymentMethod],
						[toPay], [cashBoxDir], [dateIssue], [person], [currency], [homeCurrency], [symbol1], heads.dateAccounting,
						heads.initState as initState, heads.[docState] as docState, heads.[docStateMain] as docStateMain, persons.fullName as personFullName, taxp.fullName as taxpFullName
						FROM
						e10_persons_persons AS persons RIGHT JOIN (
						e10doc_base_taxperiods AS taxp RIGHT JOIN [e10doc_core_heads] as heads
						ON (heads.taxPeriod = taxp.ndx))
						ON (heads.person = persons.ndx)
						WHERE 1';

		$this->qryCommon ($q);
		$this->qryFulltext ($q);

		// bottomTab
		if ($wh != '')
			array_push ($q, " AND heads.[warehouse] = %i", $this->warehouses[$wh]['ndx']);

		$this->qryMain($q);
		$this->runQuery ($q);
	} // selectRows
} // class ViewMnfDocs


/**
 * Class ViewDetailMnfDocs
 * @package E10Doc\Mnf
 */
class ViewDetailMnfDocs extends ViewDetailHead
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.mnf.dc.Detail');
	}
}


/**
 * Editační formulář Výroby
 *
 */

class FormMnfDocs extends \E10Doc\Core\FormHeads
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_LEFT);

		$this->openForm (TableForm::ltNone);
		$tabs ['tabs'][] = array ('text' => 'Doklad', 'icon' => 'system/formHeader');
		$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'system/formAttachments');
		$tabs ['tabs'][] = array ('text' => 'Nastavení', 'icon' => 'system/formSettings');
		$this->openTabs ($tabs, 'right');

		$this->openTab (TableForm::ltNone);
			$this->layoutOpen (TableForm::ltDocRows, 'e10-terminalForm');
				$this->addList ('rows');
			$this->layoutClose ();

			$this->layoutOpen (TableForm::ltDocMain, 'e10-terminalForm');
				$this->layoutOpen (TableForm::ltVertical);
					$this->addColumnInput ("dateAccounting");
					$this->addColumnInput ("title", DataModel::coSaveOnChange|TableForm::coFocus);
					//$this->addColumnInput ("mnfItem", DataModel::coSaveOnChange);
					//$this->addColumnInput ("mnfQuantity", DataModel::coSaveOnChange);
					//$this->addColumnInput ("mnfUnit", DataModel::coSaveOnChange);
					$this->addColumnInput ("mnfType", DataModel::coSaveOnChange);
				$this->layoutClose ();
			$this->layoutClose ();
		$this->closeTab ();

		$this->openTab (TableForm::ltNone);
			$this->addAttachmentsViewer();
		$this->closeTab ();

		$this->openTab ();
			$this->addColumnInput ("dateIssue");

			$this->addColumnInput ("symbol1");
			$this->addColumnInput ("currency");
			$this->addColumnInput ("warehouse");
			$this->addColumnInput ("author");
		$this->closeTab ();

		$this->closeTabs ();

		$this->closeForm ();
	}

	public function checkBeforeSave (&$saveData)
	{
		if (!isset ($saveData['lists']['rows']))
			return;

		$ownerRow = 0;
		forEach ($saveData['lists']['rows'] as &$r)
		{
			if ($r['operation'] == 1060701) // příjem z výroby
			{
				$ownerRow = $r['ndx'];
				$r['ownerRowMain'] = 0;
			}
			else
			{
				$r['ownerRowMain'] = $ownerRow;
			}
		}
	}

	public function checkNewRec ()
	{
		parent::checkNewRec ($this->recData);
		$this->recData ['taxCalc'] = 0;
	}

	public function comboViewer ($srcTableId, $srcColDef, $srcColumnId, $allRecData, $recData, $viewerId = 'default')
	{
		if ($srcTableId === 'e10doc.core.heads' && $srcColumnId === 'person')
			return parent::comboViewer ($srcTableId, $srcColDef, $srcColumnId, $allRecData, $recData, 'e10pro.purchase.ViewSuppliers');
		return parent::comboViewer ($srcTableId, $srcColDef, $srcColumnId, $allRecData, $recData, $viewerId);
	}

} // class FormMnfDocs


/**
 * Editační formulář Řádku Výroby
 *
 */

class FormMnfDocsRows extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->addColumnInput ("text", TableForm::coHidden);
			$this->addColumnInput ("unit", TableForm::coHidden);

			$this->openRow ();
				$this->addColumnInput ("item", TableForm::coInfoText|TableForm::coNoLabel|TableForm::coColW12);
			$this->closeRow ();

			$this->openRow ('right');
				$this->addColumnInput ("operation", TableForm::coColW5);
				$this->addColumnInput ("quantity", TableForm::coColW4);
			$this->closeRow ();
		$this->closeForm ();
	}

	function columnLabel ($colDef, $options)
	{
		switch ($colDef ['sql'])
		{
			case'quantity': return $this->recData['unit'];
		}
		return parent::columnLabel ($colDef, $options);
	}
} // class FormMnfDocsRows


/**
 *
 */

class ViewItemsForMnf extends \E10\Witems\ViewItems
{
	var $stateDates = array();
	public function init ()
	{
		parent::init();
		$this->itemKind = 1;

		if (intval($this->table->app()->cfgItem ('options.e10doc-buy.purchItemComboSearch', 0)) === 0)
			$this->enableFullTextSearch = FALSE;

		unset ($this->mainQueries); // TODO: better way

		$comboByTypes = intval($this->table->app()->cfgItem ('options.e10doc-buy.purchItemComboByTypes', 0));
		$bt [] = array ('id' => '', 'title' => 'Vše', 'active' => 1);
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

		$comboByCats = intval($this->table->app()->cfgItem ('options.e10doc-buy.purchItemComboCats', 0));
		if ($comboByCats !== 0)
		{
			$catPath = $this->table->app()->cfgItem ('e10.witems.categories.list.'.$comboByCats, '---');
			$cats = $this->table->app()->cfgItem ("e10.witems.categories.tree".$catPath.'.cats');
			forEach ($cats as $catId => $cat)
			{
				$bt [] = array ('id' => 'c'.$cat['ndx'], 'title' => $cat['shortName'], 'active' => 0);
			}
		}

		if (count ($bt) > 1)
			$this->setTopTabs ($bt);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$mats = implode (', ', $this->pks);
		$dateAcc = new \DateTime ($this->queryParam ('dateAccounting'));

		for ($ii = 1; $ii < 4; $ii++)
		{
			$this->stateDates[] = $dateAcc;
			$dd = new \DateTime ($dateAcc->format('Y-m-d'));
			$dateAcc = $dd->sub(new \DateInterval('P1D'));
		}

		forEach ($this->stateDates as $d)
		{
			$fiscalYear = e10utils::todayFiscalYear($this->app, $d);
			$id = $d->format('Ymd');

			$q = "SELECT SUM(quantity) as quantity, SUM(price) as price, item, unit FROM [e10doc_inventory_journal] WHERE [item] IN ($mats) AND [fiscalYear] = $fiscalYear AND [date] <= %d GROUP BY item, unit";
			$rows = $this->table->app()->db()->query ($q, $d);
			forEach ($rows as $r)
				$this->itemsStates [$r['item']][$id] = array ('quantity' => $r['quantity'], 'price' => $r['price'], 'unit' => $this->units[$r['unit']]['shortcut']);
		}
	}

	function decorateRow (&$item)
	{
		$item['t2'] = '';
		$item['t3'] = '';
		$item ['i2'] = '';
		if (isset ($this->itemsStates [$item ['pk']]))
		{
			forEach ($this->stateDates as $d)
			{
				$id = $d->format('Ymd');
				if (!isset($this->itemsStates [$item ['pk']][$id]))
					continue;
				if ($item ['i2'] === '')
				{
					$item ['i2'] .= \E10\nf ($this->itemsStates [$item ['pk']][$id]['quantity'], 2);
				}
				else
				{
					if ($item ['t3'] !== '')
						$item ['t3'] .= ' | ';
					$item ['t3'] .= $d->format('d.m') . ': ' . \E10\nf ($this->itemsStates [$item ['pk']][$id]['quantity'], 2);
				}
			}

			$item ['i2'] .= ' '.$this->itemsStates [$item ['pk']][$id]['unit'];
		}

		if ($item ['i2'] === '')
			$item ['i2'] = '---';
	}
} // class ViewItemsForMnf


