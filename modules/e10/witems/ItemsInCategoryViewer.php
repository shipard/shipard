<?php

namespace e10\witems;

use E10\Application, E10\TableForm, E10\TableView, E10\utils;




class ItemsInCategoryViewer extends \E10\Witems\ViewItems
{
	public function init ()
	{
		parent::init();

		$this->enableFullTextSearch = FALSE;
		$this->objectSubType = TableView::vsDetail;
	}

	public function topTabId ()
	{

		return 'c'.$this->queryParam('category');
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
				array_push($q, ' ORDER BY cnt1 DESC, cnt2 DESC, [fullName]');
			else
				array_push($q, ' ORDER BY cnt DESC, [fullName]');
		}
		else
		if ($this->activeCategory !== FALSE && $this->activeCategory['si'] === 'top')
		{
			array_push($q, ' ORDER BY cnt DESC, [fullName]');
		}
		else
		if ($this->activeCategory !== FALSE && $this->activeCategory['si'] === 'cashreg')
		{
			array_push($q, ' ORDER BY orderCashRegister, [fullName]');
		}
		else
			parent::qryOrder($q, $mainQueryId);
	}

	public function renderRow ($item)
	{
		$thisItemType = $this->table->itemType ($item, TRUE);

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = ($item['shortName'] !== '') ? $item['shortName'] : $item['fullName'];
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

		$props = [];
		$props[] = ['text' => $this->table->itemType ($item), 'icon' => 'icon-hand-o-up', 'class' => ''];

		if ($item['orderCashRegister'])
			$props[] = ['icon' => 'system/iconOrder', 'text' => utils::nf ($item['orderCashRegister']), 'class' => ''];

		if (count($props))
			$listItem ['t2'] = $props;

		if ($item['groupCashRegister'] !== '' && $this->activeCategory !== FALSE && $this->activeCategory['si'] === 'cashreg')
			$this->addGroupHeader ($item['groupCashRegister']);

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->itemsStates [$item ['pk']]))
			$item ['i2'] = \E10\nf ($this->itemsStates [$item ['pk']]['quantity'], 2).' '.$this->itemsStates [$item ['pk']]['unit'] .
					(isset($item ['i2']['text']) ? ' / '.$item ['i2']['text'] : '');

		if (isset ($this->classification [$item ['pk']]))
		{
			if (!isset($item ['t2']))
				$item ['t2'] = [];
			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t2'] = array_merge ($item ['t2'], $clsfGroup);
		}
	}
}
