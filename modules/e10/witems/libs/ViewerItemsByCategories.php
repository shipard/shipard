<?php

namespace e10\witems\libs;
use \Shipard\Viewer\TableViewPanel;



/**
 * Class ViewerItemsByCategories
 * @package e10\witems\libs
 */
class ViewerItemsByCategories extends \E10\Witems\ViewItems
{
	var $catsParam = NULL;
	var $cats = [];

	public function init()
	{
		$this->usePanelLeft = TRUE;
		$this->linesWidth = 40;

		$this->cats[0] = $ic = [['text' => 'Vše', 'icon' => 'system/iconFile', ]];
		$this->loadCategories('e10.witems.categories.tree', $this->cats);

		$this->catsParam = new \E10\Params ($this->app);
		$this->catsParam->addParam('switch', 'categories', ['title' => '', 'switch' => $this->cats, 'list' => 1]);
		$this->catsParam->detectValues();

		parent::init();
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{
		if (!$this->catsParam)
			return;

		$qry = [];
		$qry[] = ['style' => 'params', 'params' => $this->catsParam];
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	protected function loadCategories($treeId, &$dst)
	{
		$cats = $this->app()->cfgItem($treeId);
		foreach ($cats as $catTreeId => $cat)
		{
			$icNdx = $cat['ndx'];
			$ic = [['text' => $cat['shortName'], 'icon' => ($cat['icon'] !== '') ? $cat['icon'] : 'system/iconFile', 'subItems' => []]];

			$dst[$icNdx] = $ic;
			if (isset($cat['cats']) && count($cat['cats']))
			{
				$this->loadCategories($treeId . '.' . $cat['id'] . '.cats', $dst[$icNdx][0]['subItems']);		
			}
			else
				unset($dst[$icNdx][0]['subItems']);
		}
	}

	public function topTabId ()
	{
		$catNdx = intval($this->queryParam('categories'));
		return 'c'.$catNdx;
	}
}
