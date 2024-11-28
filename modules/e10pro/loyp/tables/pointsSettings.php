<?php

namespace e10pro\loyp;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class TablePointsSettings
 */
class TablePointsSettings extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.loyp.pointsSettings', 'e10pro_loyp_pointsSettings', 'Nastavení věrnostních bodů');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);
		if ($recData['settingsType'] == 0)
		{ // item
			$itemRecData = $this->app()->loadItem($recData['item'], 'e10.witems.items');
			if ($itemRecData)
				$recData['fullName'] = $itemRecData['fullName'];
			else
				$recData['fullName'] = 'vadná položka';
			$recData['witemCategory'] = 0;
		}
		elseif ($recData['settingsType'] == 1)
		{ // category
			$catRecData = $this->app()->loadItem($recData['witemCategory'], 'e10.witems.itemcategories');
			if ($catRecData)
				$recData['fullName'] = $catRecData['fullName'];
			else
				$recData['fullName'] = 'vadná kategorie';
			$recData['item'] = 0;
		}
		elseif ($recData['settingsType'] == 2)
		{ // ALL
			$recData['fullName'] = '--- ostatní ---';
			$recData['item'] = 0;
			$recData['witemCategory'] = 0;
		}
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if ($recData['settingsType'] === 0)
			return 'tables/e10.witems.items';
		elseif ($recData['settingsType'] === 1)
			return 'tables/e10.witems.itemcategories';
		elseif ($recData['settingsType'] === 2)
			return 'user/square';

		return parent::tableIcon ($recData, $options);
	}

}


/**
 * class ViewPointsSettings
 */
class ViewPointsSettings extends TableView
{
	var $loyps;

	public function init ()
	{
		$this->loyps = $this->app()->cfgItem('e10pro.loyp.loyps', NULL);
		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();

		if ($this->loyps)
		{
			$active = 1;
			$bt = array();
			forEach ($this->loyps as $loyp)
			{
				$bt [] = [
					'id' => $loyp['ndx'], 'title' => $loyp['sn'], 'active' => $active,
					'addParams' => ['loyp' => $loyp['ndx']]
				];
				$active = 0;
			}
			$this->setBottomTabs ($bt);
		}

		parent::init();
	}

	public function renderRow ($item)
	{
		$loypCfg = $this->loyps[$item['loyp']];
		$pointsSource = $loypCfg['pointsSource'] ?? 0;

		$listItem ['pk'] = $item['ndx'];

		if ($item['settingsType'] === 0)
		{
			$listItem ['t1'] = ['text' => $item['itemName'], 'suffix' => $item['itemId']];
		}
		elseif ($item['settingsType'] === 1)
      $listItem ['t1'] = $item['categoryName'];
    else
      $listItem ['t1'] = '--- ostatní ---';

		if ($pointsSource == 0)
    	$pts = $item['cntPoints'].' bodů za '.Utils::nf($item['perAmount'], 2).' Kč';
		else
			$pts = $item['cntPoints'].' bodů za '.Utils::nf($item['perQuantity'], 2).' ks/kg';

    $listItem ['t2'] = $pts;

    $ft = utils::dateFromTo($item['validFrom'], $item['validTo'], NULL);
		if ($ft !== '')
			$listItem['i2'] = ['text' => $ft, 'class' => 'label label-default'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [points].*,');
    array_push ($q, ' [cats].fullName AS categoryName, [witems].[fullName] AS [itemName], [witems].[id] AS [itemId]');
    array_push ($q, ' FROM [e10pro_loyp_pointsSettings] AS [points]');
    array_push ($q, ' LEFT JOIN [e10_witems_itemcategories] AS [cats] ON [points].witemCategory = [cats].ndx');
		array_push ($q, ' LEFT JOIN [e10_witems_items] AS [witems] ON [points].[item] = [witems].ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [witems].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [cats].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[points].', ['settingsType', '[witems].[fullName]', '[cats].fullName', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * class FormPointsSettings
 */
class FormPointsSettings extends TableForm
{
	public function renderForm ()
	{
		$loypCfg = $this->app()->cfgItem('e10pro.loyp.loyps.'.$this->recData['loyp'], NULL);
		$pointsSource = intval($loypCfg['pointsSource'] ?? 0);

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('settingsType');
			$this->addSeparator(self::coH4);
			if ($this->recData['settingsType'] == 0)
				$this->addColumnInput ('item');
			elseif ($this->recData['settingsType'] == 1)
				$this->addColumnInput ('witemCategory');
			if ($this->recData['settingsType'] != 2)
				$this->addSeparator(self::coH4);
			$this->addColumnInput ('cntPoints');
			if ($pointsSource === 0)
				$this->addColumnInput ('perAmount');
			else
				$this->addColumnInput ('perQuantity');

			$this->addSeparator(self::coH4);
      $this->addColumnInput ('validFrom');
      $this->addColumnInput ('validTo');
			$this->addSeparator(self::coH4);
      $this->addColumnInput ('loyp');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailPointsSettings
 */
class ViewDetailPointsSettings extends TableViewDetail
{
}
