<?php

namespace e10doc\purchase\libs;

use E10\utils;
use \e10\base\libs\UtilsBase;


/**
 * class ViewItemsForPurchase
 */
class ViewItemsForPurchase extends \e10\witems\ViewItems
{
	var $purchItemComboImages = 0;
	var $loypsInfo = [];

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
		$purchItemComboAll = intval($this->table->app()->cfgItem ('options.e10doc-buy.purchItemComboAll', 1));

		$loypCats = intval($this->table->app()->cfgItem ('options.e10doc-buy.purchItemLoypComboCat', 0));

		$this->purchItemComboImages = intval($this->table->app()->cfgItem ('options.e10doc-buy.purchItemComboImages', 0));

		$allId = '';
		if ($comboByCats)
			$allId = 'c'.$comboByCats;

		if ($purchItemComboAll == 1)
			$bt [] = ['id' => $allId, 'title' => 'Vše', 'active' => ($defaultCat === 0) ? 1 : 0];

		$comboByTypes = intval($this->table->app()->cfgItem ('options.e10doc-buy.purchItemComboByTypes', 0));
		if ($comboByTypes)
		{
			$itemTypes = $this->table->app()->cfgItem ('e10.witems.types');

			forEach ($itemTypes as $itemTypeId => $itemType)
			{
				if ($itemTypeId === 'none')
					continue;
				$bt [] = [
					'id' => 't'.$itemTypeId, 'title' => $itemType['shortName'], 'active' => 0,
					'addParams' => ['type' => $itemTypeId]
				];
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

		if ($loypCats)
		{
			$loypCatPath = $this->table->app()->cfgItem ('e10.witems.categories.list.'.$loypCats, '---');
			$loypCat = $this->table->app()->cfgItem ("e10.witems.categories.tree".$loypCatPath, NULL);
			if ($loypCat)
			{
				$bt [] = ['id' => 'cl'.$loypCat['ndx'], 'title' => $loypCat['shortName'], 'active' => 0];
			}
		}

		if ($purchItemComboAll == 2)
			$bt [] = ['id' => $allId, 'title' => 'Vše', 'active' => ($defaultCat === 0) ? 1 : 0];

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
		$listItem ['t1'] = $item['shortName'];
		//$listItem ['icon'] = $this->table->icon ($item);
		$listItem ['t2'] = $item['description'];
		$listItem ['i2'] = $item['id'];

		if ($this->loypMode)
		{
			if ($item['priceSell'])
				$listItem ['i1'] = ['text' => utils::nf($item['priceSell'], 2)];
		}
		else
		if ($thisItemType['kind'] !== 2)
		{
			$listItem ['i1'] = ['text' => ''];

			if ($this->showPrice === self::PRICE_SALE)
			{
				if ($item['priceSell'])
					$listItem ['i1'] = ['text' => utils::nf($item['priceSell'], 2)];
			}
			else
			if ($this->showPrice === self::PRICE_BUY)
			{
				if ($item['priceBuy'])
					$listItem ['i1'] = ['text' => utils::nf($item['priceBuy'], 2)];
			}

			if ($item['defaultUnit'] !== '')
				$listItem ['i1']['prefix'] = $this->units[$item['defaultUnit']]['shortcut'];
		}

		if ($item['groupCashRegister'] !== '' && $this->activeCategory !== FALSE && $this->activeCategory['si'] === 'cashreg')
			$this->addGroupHeader ($item['groupCashRegister']);

		if ($this->purchItemComboImages)
		{
			$image = UtilsBase::getAttachmentDefaultImage ($this->app(), $this->table->tableId(), $item['ndx'], TRUE);
			if (isset($image ['smallImage']))
			{
				if ($this->purchItemComboImages === 1)
					$listItem ['rightImage'] = ['thumb' => $image ['smallImage'], 'image' => $image ['originalImage'], 'cellClass' => 'width15'];
				elseif ($this->purchItemComboImages === 2)
					$listItem ['image'] = $image ['smallImage'];
			}
			else
			{
				if ($this->purchItemComboImages === 1)
					$listItem ['rightImage'] = ['cellClass' => 'width15'];
				elseif ($this->purchItemComboImages === 2)
					$listItem ['image'] = '';
			}
		}

		if ($this->loypMode)
		{
			$listItem ['data-cc']['operation'] = '10400015';
			$listItem ['data-cc']['itemIsLoyp'] = '1';
		}

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (!isset($item ['pk']))
			return;

		if (isset ($this->itemsStates [$item ['pk']]))
			$item ['i2'] = \E10\nf ($this->itemsStates [$item ['pk']]['quantity'], 2).' '.$this->itemsStates [$item ['pk']]['unit'] .
					(isset($item ['i2']['text']) ? ' / '.$item ['i2']['text'] : '');

		if (isset($this->loypsInfo[$item['pk']]))
		{
			$item['i1'] = ['text' => Utils::nf($this->loypsInfo[$item['pk']]['pricePoints']), 'suffix' => 'bodů', 'class' => 'id'];
		}
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		parent::selectRows2();

		if (!$this->loypMode)
			return;

		$q = [];
		array_push ($q, 'SELECT [priceList].*');
		array_push ($q, ' FROM [e10pro_loyp_priceListPoints] AS [priceList]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [item] IN %in', $this->pks);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->loypsInfo[$r['item']]['pricePoints'] = $r['pricePoints'];
		}
	}
}

