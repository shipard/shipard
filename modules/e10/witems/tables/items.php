<?php

namespace e10\witems;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10/witems/tables/itemcategories.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';


use \E10\utils;
use \Shipard\Viewer\TableView, \E10\TableViewDetail, \Shipard\Viewer\TableViewPanel;
use \Shipard\Form\TableForm;
use \E10\DbTable;
use \E10Doc\Core\e10utils;
use \e10\base\libs\UtilsBase;

/**
 * Class TableItems
 * @package E10\Witems
 */
class TableItems extends DbTable
{
	var $itemsTypes;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.witems.items", "e10_witems_items", "Položky", 1065);
		$this->itemsTypes = $this->app()->cfgItem ('e10.witems.types');
	}

	public function checkAfterSave2 (&$recData)
	{
		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$idFormula = $this->app()->cfgItem ('flags.e10.items.idFormula', '%n');
			$recData['id'] = utils::createRecId($recData, $idFormula);

			$this->app()->db()->query ("UPDATE [e10_witems_items] SET [id] = %s WHERE [ndx] = %i", $recData['id'], $recData['ndx']);
		}

		parent::checkAfterSave2 ($recData);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
		if ( (!isset($recData['niceUrl']) || $recData['niceUrl'] == '') && isset ($recData['ndx']) && $recData['ndx'])
		{
			$recData['niceUrl'] = $recData['ndx'];
		}

		if (isset ($recData['type']) && $recData['type'] != 'none' && isset($this->itemsTypes [$recData['type']]))
		{
			$itemType = $this->itemsTypes [$recData['type']];
			$recData ['itemType'] = $itemType['ndx'];
			$recData ['itemKind'] = $itemType['kind'];
		}

		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$idFormula = $this->app()->cfgItem ('flags.e10.items.idFormula', '%n');
			$recData['id'] = utils::createRecId($recData, $idFormula);
		}

		$recData['itemKind2'] = $recData['itemKind'];
		if (isset($recData['isSet']) && $recData['isSet'] && $recData['ndx'])
		{
			$cntInvItems = $this->db()->query('SELECT COUNT(*) AS cnt FROM [e10_witems_itemsets] WHERE [setItemType] = %i', 0, ' AND [itemOwner] = %i', $recData['ndx'])->fetch();
			if ($cntInvItems && $cntInvItems['cnt'])
				$recData['itemKind2'] = 1;
		}
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);
		if (!isset($recData ['type']))
			$recData ['type'] = key ($this->itemsTypes);
	}

	public function columnRefInputTitle ($form, $srcColumnId, $inputPrefix)
	{
		$pk = isset ($form->recData [$srcColumnId]) ? $form->recData [$srcColumnId] : 0;
		if (!$pk)
			return '';

		$q[] = 'SELECT [fullName], [itemKind],';
		if ($this->app()->model()->module ('e10doc.core') !== FALSE)
			array_push($q, ' [debsAccountId],');
		array_push($q, ' [id]');
		array_push($q, ' FROM [' . $this->sqlName () . '] WHERE [ndx] = %i', intval ($pk));

		$refRec = $this->app()->db()->query ($q)->fetch ();
		if ($refRec['itemKind'] === 2)
			$refTitle = ['prefix' => isset($refRec ['debsAccountId']) ? $refRec ['debsAccountId'] : $refRec ['id'], 'text' => $refRec ['fullName']];
		else
			$refTitle = ['prefix' => '#'.$refRec ['id'], 'text' => $refRec ['fullName']];

		return $refTitle;
	}

	public function columnInfoEnum ($columnId, $valueType = 'cfgText', TableForm $form = NULL)
	{
		if ($columnId === 'vatRate')	
		{
			$enum = [];
			$taxRates = $this->app()->cfgItem('e10doc.taxes.eu.cz.taxRates', []);
			foreach ($taxRates as $trId => $tr)
			{
				$enum[$trId] = $tr['title'];
			}

			return $enum;
		}

		return parent::columnInfoEnum ($columnId, $valueType = 'cfgText', $form);	
	}

	function copyDocumentRecord ($srcRecData, $ownerRecord = NULL)
	{
		$recData = parent::copyDocumentRecord ($srcRecData, $ownerRecord);

		$recData ['id'] = '';
		$recData ['niceUrl'] = '';

		return $recData;
	}

	public function icon ($item)
	{ // TODO: check
		if (isset ($this->itemsTypes [$item['type']]))
		{
			$itemType = $this->itemsTypes [$item['type']];
			return (isset ($itemType ['icon']) && $itemType ['icon'] !== '') ? $itemType ['icon'] : 'tables/e10.witems.items';
		}
		return 'tables/e10.witems.items';
	}

	public function itemInCategory ($itemRecData, $propertyValues, $category)
	{
		if (!isset($category['qry']))
			return FALSE;

		$cntTypes = 0;
		$inTypes = 0;
		$cntProps = 0;
		$inProps = 0;
		forEach ($category['qry'] as $q)
		{
			if (isset ($q['itemType']))
			{
				$cntTypes++;
				if ($q['itemType'] === $itemRecData['type'])
					$inTypes++;
			}
			else
			if (isset ($q['prop']))
			{
				$cntProps++;
				if (in_array ($q['value'], $propertyValues[$q['prop']]))
					$inProps++;
			}
		}
		if ($cntTypes !== 0 && $inTypes === 0)
			return FALSE;
		if ($cntProps !== 0 && $inProps === 0)
			return FALSE;

		return TRUE;
	}

	public function itemsCategories ($availableCategories, &$inCategories, $itemRecData, $propertyValues)
	{
		forEach ($availableCategories as $c)
		{
			if ($this->itemInCategory ($itemRecData, $propertyValues, $c))
			{
				if (!isset ($c['cats']))
					$inCategories[] = $c['url'];
			}
			if (isset ($c['cats']))
				$this->itemsCategories ($c['cats'], $inCategories, $itemRecData, $propertyValues);
		}
	}

	public function itemType ($item, $all = FALSE)
	{
		if (isset ($this->itemsTypes [$item['type']]))
		{
			if ($all)
				return $this->itemsTypes [$item['type']];
			$itemType = $this->itemsTypes [$item['type']];
			return (isset ($itemType ['.text'])) ? $itemType ['.text'] : '';
		}
		if ($all)
			return NULL;

		return $item['type'];
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		$hdr ['newMode'] = 1;

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$itemTop = [];
		$now = utils::today();

		if (!utils::dateIsBlank($recData['validFrom']) && $recData['validFrom'] > $now)
		{
			$hdr['!error'] = 1;
			$itemTop[] = ['text' => 'Od '.utils::datef($recData['validFrom']), 'icon' => 'system/iconCalendar', 'class' => 'label label-danger'];
		}
		if (!utils::dateIsBlank($recData['validTo']) && $recData['validTo'] < $now)
		{
			$hdr['!error'] = 1;
			$itemTop[] = ['text' => 'Do '.utils::datef($recData['validTo']), 'icon' => 'system/iconCalendar', 'class' => 'label label-danger'];
		}

		if (isset ($this->itemsTypes [$recData['type']]))
		{
			$it = $this->itemsTypes [$recData['type']];
			$l = ['text' => $this->itemType($recData), 'class' => 'label label-default'];
			if (isset($it['validTo']))
			{
				$validTo = utils::createDateTime($it['validTo']);
				if ($validTo < $now)
				{
					$hdr['!error'] = 1;
					$l['prefix'] = 'Platné do ' . utils::datef($validTo);
					$l['class'] = 'label label-danger';
				}
			}
			$itemTop[] = $l;
		}

		if ($recData['brand'])
		{
			$brand = $this->app()->loadItem($recData['brand'], 'e10.witems.brands');
			$itemTop[] = ['text' => $brand['fullName'], 'class' => 'label label-info'];
		}

		$hdr ['info'][] = array ('class' => 'info', 'value' => $itemTop);
		$hdr ['info'][] = ['class' => 'title', 'value' => [['text' => $recData ['fullName']], ['text' => '#'.$recData ['id'], 'class' => 'pull-right id']]];

		$hdr['newMode'] = 1;

		$image = UtilsBase::getAttachmentDefaultImage ($this->app(), $this->tableId(), $recData ['ndx'], TRUE);
		if (isset ($image ['smallImage']))
			$hdr ['image'] = $image ['smallImage'];

		return $hdr;
	}

} // class TableItems


/*
 * ViewItems
 *
 */

class ViewItems extends TableView
{
	var $defaultType;
	var $itemsStates = array();
	var $withInventory;
	var $units;
	var $itemKind = FALSE;
	var $activeCategory = FALSE;

	CONST PRICE_NONE = 0, PRICE_SALE = 1, PRICE_BUY = 2;
	var $showPrice = self::PRICE_SALE;

	protected $classification;

	var $now;

	public function init ()
	{
		$this->units = $this->table->app()->cfgItem ('e10.witems.units');
		$this->now = new \DateTime();

		$this->withInventory = FALSE;
		if ($this->table->app()->model()->table ('e10doc.inventory.journal') !== FALSE)
			$this->withInventory = TRUE;

		if (isset ($this->defaultType))
			$this->addAddParam ('type', $this->defaultType);

		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'new', 'title' => 'Nové');
		$mq [] = array ('id' => 'archive', 'title' => 'Archív');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		$this->setPanels (TableView::sptQuery);

		parent::init();
	} // init

	public function selectRows ()
	{
		$dotaz = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		// -- prepare topTabs
		$topTabId = $this->topTabId ();
		if ($topTabId !== '')
		{
			if ($topTabId[0] === 't') // item type
				$this->defaultType = substr ($topTabId, 1);
			else
			if ($topTabId[0] === 'c') // item category
			{
				$catNdx = intval (substr($topTabId, 1));
				$catRootPath = $this->app->cfgItem ('e10.witems.categories.list.'.$catNdx, '');
				if ($catRootPath != '')
				{
					$parts = explode ('.', substr($catRootPath, 1));
					$rootTreeId = 'e10.witems.categories.tree.'.implode('.cats.', $parts);
					$this->activeCategory = $this->app->cfgItem ($rootTreeId);
				}
			}
		}

		$q [] = 'SELECT [items].*,';
		array_push ($q, ' [itemTypes].validFrom AS itValidFrom, [itemTypes].validTo AS itValidTo');
		$this->qryColumns($q);

		array_push ($q, ' FROM [e10_witems_items] AS [items]');
		array_push ($q, ' LEFT JOIN [e10_witems_itemtypes] AS [itemTypes] ON [items].[itemType] = itemTypes.ndx');

		$this->qryJoin($q);

		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($dotaz != '')
		{
			$words = explode (' ', $dotaz);
			array_push ($q, " AND (");
			if (count($words) == 1)
			{
				array_push ($q, "[items].[fullName] LIKE %s", '%'.$dotaz.'%');
				array_push ($q, " OR [items].[shortName] LIKE %s", '%'.$dotaz.'%');
				array_push ($q, " OR [items].[id] LIKE %s", $dotaz.'%');
				array_push ($q, " OR EXISTS (SELECT ndx FROM e10_base_properties WHERE items.ndx = e10_base_properties.recid AND valueString LIKE %s AND tableid = %s)", '%'.$dotaz.'%', 'e10.witems.items');
			}
			else
			{
				$widx = 0;
				array_push ($q, "(");
				forEach ($words as $w)
				{
					if ($w == '')
						continue;
					if ($widx == 0)
						array_push ($q, " [items].[fullName] LIKE %s", '%'.$w.'%');
					else
						array_push ($q, " AND [items].[fullName] LIKE %s", '%'.$w.'%');
					$widx++;
				}
				array_push ($q, ")");

				$widx = 0;
				array_push ($q, " OR ");
				array_push ($q, "(");
				forEach ($words as $w)
				{
					if ($w == '')
						continue;
					if ($widx == 0)
						array_push ($q, " [items].[shortName] LIKE %s", '%'.$w.'%');
					else
						array_push ($q, " AND [items].[shortName] LIKE %s", '%'.$w.'%');
					$widx++;
				}
				array_push ($q, ")");

				array_push ($q, " OR ");
				array_push ($q, " EXISTS (SELECT ndx FROM e10_base_properties WHERE items.ndx = e10_base_properties.recid AND valueString LIKE %s AND tableid = %s)", '%'.$dotaz.'%', 'e10.witems.items');
			}
			array_push ($q, ")");
		}
		// -- defaultType?
		if (isset ($this->defaultType))
			array_push ($q, " AND [items].[type] = %s", $this->defaultType);

		if ($this->queryParam('operation'))
		{
			$operation = $this->table->app()->cfgItem ('e10.docs.operations.'.$this->queryParam('operation'), FALSE);
			if ($operation !== FALSE && (isset ($operation['itemType'])))
				array_push ($q, " AND ([items].[itemKind] = %i", $operation['itemType'], ' OR [items].[itemKind2] = %i)', $operation['itemType']);
		}
		else
		if ($this->itemKind !== FALSE)
			array_push ($q, " AND [items].[itemKind] = %i", $this->itemKind);

		$docDir = $this->queryParam('docDir');
		if ($docDir !== FALSE)
			array_push ($q, " AND [useFor] IN (0, %i)", $docDir);

		$docType = $this->queryParam('docType');
		if ($docType === 'bank')
			array_push ($q, " AND [useFor] IN (0, 100)");
		else
		if ($docType === 'cmnbkp')
			array_push ($q, " AND [useFor] IN (0, 101)");

		// special queries
		$qv = $this->queryValues ();

		if (isset ($qv['itemTypes']))
			array_push ($q, " AND [items].[itemType] IN %in", array_keys($qv['itemTypes']));

		if (isset ($qv['itemBrands']))
			array_push ($q, " AND [items].[brand] IN %in", array_keys($qv['itemBrands']));

		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE items.ndx = recid AND tableId = %s', 'e10.witems.items');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}

		$this->qryCommon($q);

		if ($this->activeCategory !== FALSE)
			itemCategoryQuery ($this->activeCategory, $q, 'items');

		$this->qryMain($q);

		$this->qryOrder ($q, $mainQuery);
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	} // selectRows

	public function qryColumns (array &$q)
	{
	}

	public function qryCommon (array &$q)
	{
	}

	public function qryJoin (array &$q)
	{
	}

	public function qryMain (array &$q)
	{
		$mainQuery = $this->mainQueryId ();
		$fts = $this->fullTextSearch ();

		// -- new
		if ($mainQuery == 'new')
			array_push ($q, " AND [items].[docStateMain] = 0");

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
		{
			if ($fts != '')
				array_push ($q, " AND [items].[docStateMain] IN (0, 2, 5)");
			else
				array_push ($q, " AND [items].[docStateMain] < 4");
		}

		// -- archive
		if ($mainQuery == 'archive')
			array_push ($q, " AND [items].[docStateMain] = 5");

		// trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND [items].[docStateMain] = 4");
	}

	public function qryOrder (array &$q, $mainQueryId)
	{
		if ($mainQueryId === 'all')
			array_push ($q, ' ORDER BY [items].[fullName]');
		else
			array_push ($q, ' ORDER BY [items].[docStateMain], [items].[fullName]');
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = \E10\Base\loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);

		if (!$this->withInventory)
			return;

		$mats = implode (', ', $this->pks);

		if ($this->queryParam ('docType') === 'mnf')
			$date = $this->queryParam ('dateAccounting');
		else
			$date = \date("Y-m-d");

		$fiscalYear = e10utils::todayFiscalYear($this->app, $date);

		$q = "SELECT SUM(quantity) as quantity, SUM(price) as price, MAX(date) as lastDate, item, unit ".
				"FROM [e10doc_inventory_journal] WHERE [item] IN ($mats) AND [fiscalYear] = $fiscalYear AND [date] <= %d GROUP BY item, unit";
		$rows = $this->table->app()->db()->query ($q, $date);
		forEach ($rows as $r)
			$this->itemsStates [$r['item']] = ['quantity' => $r['quantity'], 'price' => $r['price'], 'unit' => $this->units[$r['unit']]['shortcut'], 'lastDate' => $r['lastDate']];
	}

	public function renderRow ($item)
	{
		$thisItemType = $this->table->itemType ($item, TRUE);
		$props = [];

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];

		if (!utils::dateIsBlank($item['validFrom']) && $item['validFrom'] > $this->now)
		{
			$listItem['!error'] = 1;
			$props[] = ['text' => 'Od '.utils::datef($item['validFrom']), 'icon' => 'system/iconCalendar', 'class' => 'label label-danger'];
		}
		if (!utils::dateIsBlank($item['validTo']) && $item['validTo'] < $this->now)
		{
			$listItem['!error'] = 1;
			$props[] = ['text' => 'Do '.utils::datef($item['validTo']), 'icon' => 'system/iconCalendar', 'class' => 'label label-danger'];
		}

		if ($item['successorItem'])
		{
			if (utils::dateIsBlank($item['successorDate']) || $item['successorDate'] < $this->now)
				$listItem['!error'] = 1;
		}
		if (isset ($item['debsAccountId']) && $thisItemType && $thisItemType['kind'] === 2)
			$listItem ['i1'] = $item['debsAccountId'];
		else
			$listItem ['i1'] = ['text' => '#'.$item['id'], 'class' => 'id'];

		$listItem ['icon'] = $this->table->icon ($item);

		if ($thisItemType && $thisItemType['kind'] !== 2)
		{
			$listItem ['i2'] = [];

			if ($this->showPrice === self::PRICE_SALE)
			{
				if ($item['priceSell'] != 0.0)
					$listItem ['i2'][] = ['text' => utils::nf($item['priceSell'], 2), 'icon' => 'icon-money', 'class' => 'label label-warning'];
				if ($item['priceSellBase'] != 0.0)
					$listItem ['i2'][] = ['text' => utils::nf($item['priceSellBase'], 2), 'suffix' => 'bez DPH', 'icon' => 'icon-money', 'class' => 'label label-success'];
				if ($item['priceSellTotal'] != 0.0)
					$listItem ['i2'][] = ['text' => utils::nf($item['priceSellTotal'], 2), 'suffix' => 's DPH', 'icon' => 'icon-money', 'class' => 'label label-info'];
			}
			else
			if ($this->showPrice === self::PRICE_BUY)
			{
				if ($item['priceBuy'])
					$listItem ['i2'][] = ['text' => utils::nf($item['priceBuy'], 2)];
			}

			//if ($item['defaultUnit'] !== '')
			//	$listItem ['i2'][] = ['text' => $this->units[$item['defaultUnit']]['shortcut'], 'class' => 'label label-default'];
		}

		$itShow = 0;
		$itLabel = ['text' => $this->table->itemType ($item), 'class' => 'label label-default'];
		if ($item['useFor'] !== 0)
		{
			$useFor = $this->table->columnInfoEnum ('useFor', 'cfgText');
			$l['suffix'] = $useFor [$item ['useFor']];
		}
		if (!utils::dateIsBlank($item['itValidTo']) && $item['itValidTo'] < $this->now)
		{
			$listItem['!error'] = 1;
			$itLabel['class'] = 'label label-danger';
			$itLabel['prefix'] = 'Platné do '.utils::datef($item['itValidTo']);
			$itShow = 1;
		}
		if (!isset ($this->defaultType) || $itShow)
			$props[] = $itLabel;

		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->itemsStates [$item ['pk']]))
		{
			$item ['i2'][] = [
				'text' => utils::nf($this->itemsStates [$item ['pk']]['quantity'], 2),
				'suffix' => $this->itemsStates [$item ['pk']]['unit'],
				'icon' => 'icon-shopping-basket',
				'class' => '',
			];
		}

		if (isset ($this->classification [$item ['pk']]))
		{
			$item ['t3'] = [];
			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t3'] = array_merge ($item ['t3'], $clsfGroup);
		}
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = array ();

		// -- tags
		$clsf = \E10\Base\classificationParams ($this->table);
		foreach ($clsf as $cg)
		{
			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.clsf.'.$cg['id'], ['items' => $cg['items']]);
			$qry[] = array ('style' => 'params', 'title' => $cg['name'], 'params' => $params);
		}

		// -- types
		$itemTypes = $this->app()->cfgItem ('e10.witems.types', FALSE);
		if ($itemTypes !== FALSE && count($itemTypes) !== 0)
		{
			$chbxItemTypes = [];
			forEach ($itemTypes as $typeId => $t)
			{
				if (isset($t['kind']) && $this->itemKind !== FALSE && $t['kind'] != $this->itemKind)
					continue;
				$chbxItemTypes[$t['ndx']] = ['title' => $t['.text'], 'id' => strval($t['ndx'])];
			}

			$paramsItemTypes = new \E10\Params ($panel->table->app());
			$paramsItemTypes->addParam ('checkboxes', 'query.itemTypes', ['items' => $chbxItemTypes]);
			$qry[] = array ('id' => 'itemTypes', 'style' => 'params', 'title' => 'Typy položek', 'params' => $paramsItemTypes);
		}

		// -- brands
		$brandsQry = 'SELECT * FROM [e10_witems_brands] WHERE docStateMain < 5 ORDER BY shortName, ndx';
		$brandsRows = $this->table->db()->query ($brandsQry);
		if (count($brandsRows) !== 0)
		{
			$chbxItemBrands = [];
			$chbxItemBrands['0'] = array ('title' => '---', 'id' => 0);
			forEach ($brandsRows as $b)
				$chbxItemBrands[$b['ndx']] = array ('title' => $b['shortName'], 'id' => $b['ndx']);

			$paramsItemBrands = new \E10\Params ($panel->table->app());
			$paramsItemBrands->addParam ('checkboxes', 'query.itemBrands', ['items' => $chbxItemBrands]);
			$qry[] = array ('id' => 'itemBrands', 'style' => 'params', 'title' => 'Značky položek', 'params' => $paramsItemBrands);
		}

		$panel->addContent(array ('type' => 'query', 'query' => $qry));
	}


} // class ViewItems


/**
 * Class ViewItemsCombo
 * @package E10\Witems
 */
class ViewItemsCombo extends ViewItems
{
	public function init ()
	{
		$this->units = $this->table->app()->cfgItem ('e10.witems.units');

		$this->withInventory = FALSE;
		if ($this->table->app()->model()->table ('e10doc.inventory.journal') !== FALSE)
			$this->withInventory = TRUE;

		if (isset ($this->defaultType))
			$this->addAddParam ('type', $this->defaultType);

		//$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		//$mq [] = ['id' => 'archive', 'title' => 'Archív'];

		$docDate = utils::createDateTime($this->queryParam ('dateAccounting'));
		$docDateStr = utils::datef ($docDate, '%d');

		if ($docDate)
			$this->now = $docDate;

		$mq [] = ['id' => 'document', 'title' => $docDateStr];
		$mq [] = ['id' => 'today', 'title' => 'Dnes', 'side' => 'left'];

		$this->setMainQueries ($mq);

		TableView::init();
	}

	public function renderRow ($item)
	{
		$thisItemType = $this->table->itemType ($item, TRUE);

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = ($item['shortName'] !== '') ? $item['shortName'] : $item['fullName'];

		$props = [];

		if (!utils::dateIsBlank($item['validFrom']) && $item['validFrom'] > $this->now)
		{
			$listItem['!error'] = 1;
			$props[] = ['text' => 'Od '.utils::datef($item['validFrom']), 'icon' => 'system/iconCalendar', 'class' => 'label label-danger'];
		}
		if (!utils::dateIsBlank($item['validTo']) && $item['validTo'] < $this->now)
		{
			$listItem['!error'] = 1;
			$props[] = ['text' => 'Do '.utils::datef($item['validTo']), 'icon' => 'system/iconCalendar', 'class' => 'label label-danger'];
		}

		if (isset ($item['debsAccountId']) && $thisItemType['kind'] === 2)
			$props[] = ['text' => $item['debsAccountId'], 'class' => ''];
		else
			$props[] = ['text' => '#'.$item['id'], 'class' => ''];

		$listItem ['t2'] = $props;

		$listItem ['icon'] = $this->table->icon ($item);

		if ($thisItemType['kind'] !== 2)
		{
			if ($this->showPrice === self::PRICE_SALE)
			{
				if ($item['priceSell'] != 0.0)
					$listItem ['i2'][] = ['text' => utils::nf($item['priceSell'], 2), 'icon' => 'icon-money', 'class' => 'label label-warning'];
				if ($item['priceSellBase'] != 0.0)
					$listItem ['i2'][] = ['text' => utils::nf($item['priceSellBase'], 2), 'suffix' => 'bez DPH', 'icon' => 'icon-money', 'class' => 'label label-success'];
				if ($item['priceSellTotal'] != 0.0)
					$listItem ['i2'][] = ['text' => utils::nf($item['priceSellTotal'], 2), 'suffix' => 's DPH', 'icon' => 'icon-money', 'class' => 'label label-info'];
			}
			else
			if ($this->showPrice === self::PRICE_BUY)
			{
				if ($item['priceBuy'])
					$listItem ['i2'] = ['text' => utils::nf($item['priceBuy'], 2)];
			}
		}

		if (!isset ($this->defaultType))
		{
			$listItem ['t3'][] = $this->table->itemType ($item);
		}

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->itemsStates [$item ['pk']]))
		{
			$i = [
				'text' => utils::nf ($this->itemsStates [$item ['pk']]['quantity'], 2),
				'suffix' => $this->itemsStates [$item ['pk']]['unit'],
				'icon' => 'icon-shopping-basket',
				'class' => 'pull-right',
			];
			if ($this->itemsStates [$item ['pk']]['lastDate'])
				$i['prefix'] = utils::datef ($this->itemsStates [$item ['pk']]['lastDate']);

			$item ['t3'][] = $i;
		}
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$mainQuery = $this->mainQueryId ();

		//$this->classification = \E10\Base\loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);

		if (!$this->withInventory)
			return;

		$date = ($mainQuery === 'today') ? utils::today() : $this->queryParam ('dateAccounting');
		if (!$date)
			$date = utils::today();
		$fiscalYear = e10utils::todayFiscalYear($this->app, $date);

		$q[] = 'SELECT SUM(quantity) as quantity, SUM(price) as price, MAX(date) as lastDate, item, unit ';
		array_push ($q, 'FROM [e10doc_inventory_journal] WHERE [item] IN %in', $this->pks,
				' AND [fiscalYear] = %i', $fiscalYear, ' AND [date] <= %d', $date);

		$warehouse = $this->queryParam ('warehouse');
		if ($warehouse)
			array_push ($q, ' AND [warehouse] = %i', $warehouse);

		array_push ($q, ' GROUP BY item, unit');

		$rows = $this->table->app()->db()->query ($q);
		forEach ($rows as $r)
			$this->itemsStates [$r['item']] = ['quantity' => $r['quantity'], 'price' => $r['price'], 'unit' => $this->units[$r['unit']]['shortcut'], 'lastDate' => $r['lastDate']];
	}

	public function qryMain (array &$q)
	{
		$fts = $this->fullTextSearch ();

		if ($fts != '')
			array_push ($q, " AND [items].[docStateMain] IN (0, 2, 5)");
		else
			array_push ($q, " AND [items].[docStateMain] < 4");
	}

	public function qryCommon (array &$q)
	{
		$mainQuery = $this->mainQueryId ();
		$date = ($mainQuery === 'today') ? utils::today() : $this->queryParam ('dateAccounting');
		if (!$date)
			$date = utils::today();

		array_push($q, ' AND ([items].[validFrom] IS NULL OR [items].[validFrom] <= %d)', $date);
		array_push($q, ' AND ([items].[validTo] IS NULL OR [items].[validTo] >= %d)', $date);
	}
}


/**
 * Základní detail Položek
 *
 */

class ViewDetailItems extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10.witems.dc.Item');
	}

	public function createToolbar ()
	{
		$toolbar = parent::createToolbar ();

		if ($this->app()->hasRole('root'))
		{
			$merge = $this->app()->cfgItem ('registeredClasses.mergeRecords.e10-witems-items', FALSE);

			if ($merge !== FALSE)
			{
				$toolbar [] = array ('type' => 'action', 'action' => 'addwizard', 'data-table' => 'e10.persons.persons',
														 'text' => 'Sloučit položky', 'data-class' => 'e10.witems.MergeItemsWizard', 'icon' => 'icon-code-fork');
			}
		}
		return $toolbar;
	} // createToolbar
}


/**
 * Class ViewDetailUsing
 * @package E10\Witems
 */
class ViewDetailUsing extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10.witems.dc.ItemUsing');
	}
}


/**
 * Class ViewDetailAnnotations
 * @package E10\Witems
 */
class ViewDetailAnnotations extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('e10pro.kb.annots', 'default',
			['docTableNdx' => $this->table->ndx, 'docRecNdx' => $this->item['ndx']]);
	}
}


/*
 * FormItems
 *
 */

class FormItems extends TableForm
{
	public function renderForm ()
	{
		$itemKind = $this->app()->cfgItem ('e10.witems.types.'.$this->recData['type'].'.kind', FALSE);
		$debsGroups = $this->app()->cfgItem ('e10debs.groups', FALSE);
		$isset = (isset($this->recData['isSet']) && $this->recData['isSet']) ? 1 : 0;
		$salePricesType = intval($this->app()->cfgItem ('options.e10doc-sale.witemsSalePricesType', 0));
		$codeKinds = $this->app()->cfgItem('e10.witems.codesKinds', []);
		$useItemCodes = (count($codeKinds) !== 0);

		$useSuppliers = 0;
		if ($itemKind === 1)
			$useSuppliers = 1;
		$useRelated = 0;
		if ($itemKind === 1)
			$useRelated = 1;

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();

		$this->addColumnInput ("type");
		$this->addColumnInput ("fullName");
		$this->addColumnInput ("defaultUnit");

		$properties = $this->addList ('properties', '', TableForm::loAddToFormLayout|TableForm::loWidgetParts);

		$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'formProperties'];
		forEach ($properties ['memoInputs'] as $mi)
			$tabs ['tabs'][] = ['text' => $mi ['text'], 'icon' => $mi ['icon']];
		if ($isset)
			$tabs ['tabs'][] = ['text' => 'Sada', 'icon' => 'formSet'];
		if ($useItemCodes)
			$tabs ['tabs'][] = ['text' => 'Kódy', 'icon' => 'user/hashtag'];
		if ($useSuppliers)
			$tabs ['tabs'][] = ['text' => 'Dodavatelé', 'icon' => 'formSuppliers'];
		if ($useRelated)
			$tabs ['tabs'][] = ['text' => 'Související', 'icon' => 'formRelatedItems'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$this->openTabs ($tabs);
			$this->openTab ();
				$this->addColumnInput ("shortName");
				$this->addColumnInput ("id");

				if ($itemKind === 0)
				{ // service
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
					$this->addList ('clsf', '', TableForm::loAddToFormLayout);
					$this->priceSellInputs ($salePricesType);
					$this->addColumnInput ("useFor");
					if ($debsGroups !== FALSE && count($debsGroups) > 1)
						$this->addColumnInput ("debsGroup");
				}
				else
				if ($itemKind === 2)
				{ // accounting item
					if ($this->table->app()->model()->table ('e10doc.debs.accounts') !== FALSE)
						$this->addColumnInput ("debsAccountId");
					$this->addColumnInput ("useFor");
					$this->addColumnInput ("useBalance");
					$this->priceSellInputs ($salePricesType);
					$this->addColumnInput ("priceBuy");
				}
				else
				{
					$this->addColumnInput ("brand");
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
					$this->addList ('clsf', '', TableForm::loAddToFormLayout);

					$this->openRow ();
						$this->addColumnInput ("mnfEnableAssembling");
						$this->addColumnInput ("isSet");
					$this->closeRow();

					$this->priceSellInputs ($salePricesType);
					$this->addColumnInput ("priceBuy");

					if ($debsGroups !== FALSE && count($debsGroups) > 1)
						$this->addColumnInput ("debsGroup");
				}

				$this->appendCode ($properties ['widgetCode']);
			$this->closeTab ();

			forEach ($properties ['memoInputs'] as $mi)
			{
				$this->openTab ();
					$this->appendCode ($mi ['widgetCode']);
				$this->closeTab ();
			}

			if ($isset)
			{
				$this->openTab (TableForm::ltNone);
					$this->addList ('set');
				$this->closeTab ();
			}

			if ($useItemCodes)
			{
				$this->openTab (TableForm::ltNone);
					$this->addList ('codes');
				$this->closeTab ();
			}

			if ($useSuppliers)
			{
				$this->openTab (TableForm::ltNone);
					$this->addList ('suppliers');
				$this->closeTab ();
			}
			if ($useRelated)
			{
				$this->openTab (TableForm::ltNone);
					$this->addList ('related');
				$this->closeTab ();
			}

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();

			$this->openTab ();
				$this->addColumnInput ("vatRate");
				$this->addColumnInput ("niceUrl");
				if ($this->app()->model()->module ('terminals.store') !== FALSE || $this->app()->model()->module ('e10pro.purchase') !== FALSE)
				{
					$this->addColumnInput('askQCashRegister');
					$this->addColumnInput('askPCashRegister');
					$this->addColumnInput('orderCashRegister');
					$this->addColumnInput('groupCashRegister');
				}
				$this->addSeparator(self::coH2);
				$this->addColumnInput ('validFrom');
				$this->addColumnInput ('validTo');
				$this->addColumnInput ('successorItem');
				$this->addColumnInput ('successorDate');
			$this->closeTab ();
		$this->closeTabs ();

		$this->closeForm ();
	}

	function priceSellInputs ($salePricesType)
	{
		$usePriceBase = 0;
		$usePriceTotal = 0;

		if ($salePricesType === 0 || $salePricesType === 2 || $this->recData['priceSellBase'] != 0.0)
			$usePriceBase = 1;
		if ($salePricesType === 0 || $salePricesType === 1 || $this->recData['priceSellTotal'] != 0.0)
			$usePriceTotal = 1;

		if ($this->recData['priceSell'] != 0.0)
			$this->addColumnInput ('priceSell');

		if ($usePriceBase)
			$this->addColumnInput ('priceSellBase');
		if ($usePriceTotal)
			$this->addColumnInput ('priceSellTotal');
	}

	public function docLinkEnabled ($docLink)
	{
		if ($docLink['linkid'] === 'e10-witems-items-whPlaces')
		{
			$ewhp = $this->app()->cfgItem('options.e10doc-stock.useWHPlaces', 0);
			if (!$ewhp)
				return FALSE;
			$itemKind = $this->app()->cfgItem ('e10.witems.types.'.$this->recData['type'].'.kind', FALSE);

			if ($itemKind === 1)
				return TRUE;
		}

		return parent::docLinkEnabled($docLink);
	}
}

