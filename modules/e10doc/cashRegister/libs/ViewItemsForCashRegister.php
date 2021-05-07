<?php

namespace e10doc\cashRegister\libs;

class ViewItemsForCashRegister extends \e10\witems\ViewItems
{
	public function init ()
	{
		parent::init();

		if (intval($this->table->app()->cfgItem ('options.e10doc-sale.cashregItemComboSearch', 1)) === 0)
			$this->enableFullTextSearch = FALSE;

		unset ($this->mainQueries); // TODO: better way

		$comboByTypes = intval($this->table->app()->cfgItem ('options.e10doc-sale.cashregItemComboByTypes', 0));
		$defaultCat = intval($this->table->app()->cfgItem ('options.e10doc-sale.cashregItemDefaultComboCat', 0));
		$bt [] = ['id' => '', 'title' => 'Vše', 'active' => ($defaultCat === 0) ? 1 : 0];
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

		$comboByCats = intval($this->table->app()->cfgItem ('options.e10doc-sale.cashregItemComboCats', 0));
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

	public function qryCommon (array &$q)
	{
		array_push ($q, ' AND [useFor] IN (0, %i)', 2);
	}
}
