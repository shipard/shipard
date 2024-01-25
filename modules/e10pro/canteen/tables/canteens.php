<?php

namespace e10pro\canteen;

use \e10\utils, \e10\TableView, \Shipard\Form\TableForm, \e10\DbTable, \e10doc\core\CreateDocumentUtility;
use \e10\base\libs\UtilsBase;

/**
 * Class TableCanteens
 * @package e10pro\canteen
 */
class TableCanteens extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.canteen.canteens', 'e10pro_canteen_canteens', 'Jídelny');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		if (!$form)
			return TRUE;

		if ($columnId === 'dstDocKind')
		{
			if ($cfgItem['ndx'] === 0)
				return TRUE;

			if ($cfgItem['docType'] !== 'invno')
				return FALSE;

			return TRUE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}

	public function saveConfig ()
	{
		$rows = $this->app()->db->query ("SELECT * FROM [e10pro_canteen_canteens] WHERE docState != 9800 ORDER BY [order], [fullName]");
		$canteens = [];
		foreach ($rows as $r)
		{
			$canteen = [
				'ndx' => $r['ndx'], 'fn' => $r['fullName'], 'sn' => $r['shortName'],
				'title' => $r['title'], 'icon' => $r['icon'], 'webServer' => $r['webServer'],
				'mainFoodTitle' => ($r['mainFoodTitle'] !== '') ? $r['mainFoodTitle'] : 'Oběd',
				'lunchMenuCookingType' => $r['lunchMenuCookingType'],
				'lunchMenuFoodCount' => $r['lunchMenuFoodCount'], 'lunchMenuSoup' => $r['lunchMenuSoup'],
				'lunchCookFoodCount' => ($r['lunchMenuCookingType'] == 1) ? $r['lunchCookFoodCount'] : $r['lunchMenuFoodCount'],
				'closeOrdersDay' => $r['closeOrdersDay'], 'closeOrdersTime' => $r['closeOrdersTime'], 'closeOrdersSkipWeekends' => $r['closeOrdersSkipWeekends'],
				'timeoutLogoutTakeTerminal' => $r['timeoutLogoutTakeTerminal'],
				'supplierEmail' => $r['supplierEmail'], 'sendSupplierOrderTime' => $r['sendSupplierOrderTime'],
				'autoOrderFoods' => $r['autoOrderFoods'], 'sendingEmailsDisabled' => $r['sendingEmailsDisabled'],
				'invoicingEnabled' => $r['invoicingEnabled'], 'itemMainFood' => $r['itemMainFood'],
				'dstDocKind' => $r['dstDocKind'], 'dueDays' => $r['dueDays'], 'invoiceAuthor' => $r['invoiceAuthor'],
				'dstDocState' => $r['dstDocState'], 'dstDocAutoSend' => $r['dstDocAutoSend'],
			];

			if (!utils::dateIsBlank($r['dateWorkingFrom']))
				$canteen['dateWorkingFrom'] = $r['dateWorkingFrom']->format('Y-m-d');

			if ($r['lunchMenuCookingType'] == 1)
			{
				$canteen['closeSelectCookingFoodsDay'] = $r['closeSelectCookingFoodsDay'];
				$canteen['closeSelectCookingFoodsTime'] = $r['closeSelectCookingFoodsTime'];
				$canteen['forceSelectCookingFoodsDay'] = $r['forceSelectCookingFoodsDay'];
				$canteen['forceSelectCookingFoodsTime'] = $r['forceSelectCookingFoodsTime'];
			}

			$cntPeoples = 0;
			$cntPeoples += $this->saveConfigList ($canteen, 'admins', 'e10.persons.persons', 'e10pro-canteens-admins', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($canteen, 'adminsGroups', 'e10.persons.groups', 'e10pro-canteens-admins', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($canteen, 'users', 'e10.persons.persons', 'e10pro-canteens-users', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($canteen, 'usersGroups', 'e10.persons.groups', 'e10pro-canteens-users', $r ['ndx']);

			$wiki['allowAllUsers'] = ($cntPeoples) ? 0 : 1;

			$this->saveConfigList ($canteen, 'optimizePayers', 'e10.persons.persons', 'e10pro-canteens-optimize-payers', $r ['ndx']);
			$this->saveConfigList ($canteen, 'dailyReportLabels', 'e10.base.clsfitems', 'canteen-daily-report-labels', $r ['ndx']);

			$this->saveConfigAddFoods($canteen);

			$canteens [$r['ndx']] = $canteen;
		}

		$cfg ['e10pro']['canteen']['canteens'] = $canteens;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.canteen.canteens.json', utils::json_lint (json_encode ($cfg)));
	}

	function saveConfigList (&$item, $key, $dstTableId, $listId, $activityTypeNdx)
	{
		$list = [];

		$rows = $this->app()->db->query (
			'SELECT doclinks.dstRecId FROM [e10_base_doclinks] AS doclinks',
			' WHERE doclinks.linkId = %s', $listId, ' AND dstTableId = %s', $dstTableId,
			' AND doclinks.srcRecId = %i', $activityTypeNdx
		);
		foreach ($rows as $r)
		{
			$list[] = $r['dstRecId'];
		}

		if (count($list))
		{
			$item[$key] = $list;
			return count($list);
		}

		return 0;
	}

	function saveConfigAddFoods(&$canteen)
	{
		$list = [];

		$q = [];
		array_push($q, 'SELECT addFoods.* FROM [e10pro_canteen_canteensAddFoods] AS [addFoods]');
		array_push($q, ' WHERE [addFoods].[canteen] = %i', $canteen['ndx']);
		array_push($q, ' ORDER BY [addFoods].[addFoodType], [addFoods].[rowOrder], [addFoods].[ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r['ndx'],
				'type' => $r['addFoodType'],
				'title' => $r['shortName'],
				'fn' => $r['fullName'], 'sn' => $r['shortName'],
				'optional' => $r['addFoodOptional'],
				'validFrom' => utils::dateIsBlank($r['validFrom']) ? '0000-00-00' : $r['validFrom']->format('Y-m-d'),
				'validTo' => utils::dateIsBlank($r['validTo']) ? '0000-00-00' : $r['validTo']->format('Y-m-d'),
			];

			$this->saveConfigList ($item, 'personsLabels', 'e10.base.clsfitems', 'canteensAddFoods-persons-labels', $r ['ndx']);

			$list[$r['ndx']] = $item;
		}

		if (count($list))
			$canteen['addFoods'] = $list;
	}

	function usersCanteens ()
	{
		$canteens = [];
		$userNdx = $this->app()->userNdx();
		$userGroups = $this->app()->userGroups();

		$allCanteens = $this->app()->cfgItem ('e10pro.canteen.canteens', NULL);
		if ($allCanteens === NULL)
			return $canteens;

		foreach ($allCanteens as $w)
		{
			$enabled = 0;
			if (!isset($w['allowAllUsers'])) $enabled = 1;
			elseif ($w['allowAllUsers']) $enabled = 1;
			elseif (isset($w['admins']) && in_array($userNdx, $w['admins'])) $enabled = 1;
			elseif (isset($w['adminsGroups']) && count($userGroups) && count(array_intersect($userGroups, $w['adminsGroups'])) !== 0) $enabled = 1;
			elseif (isset($w['users']) && in_array($userNdx, $w['users'])) $enabled = 1;
			elseif (isset($w['usersGroups']) && count($userGroups) && count(array_intersect($userGroups, $w['usersGroups'])) !== 0) $enabled = 1;

			if (!$enabled)
				continue;

			$canteens[$w['ndx']] = $w;
		}

		return $canteens;
	}

	public function addFoodsList ($canteenCfg, $personNdx, $date)
	{
		$personsLabels = $this->personsLabels($personNdx);

		$addFoods = [];
		if (!$canteenCfg || !isset($canteenCfg['addFoods']) || !count($canteenCfg['addFoods']))
			return $addFoods;

		foreach ($canteenCfg['addFoods'] as $afNdx => $af)
		{
			if ($personNdx && isset($af['personsLabels']) && count($af['personsLabels']) && !count(array_intersect($af['personsLabels'], $personsLabels)))
				continue;
			$addFoods[] = $afNdx;
		}

		return $addFoods;
	}

	function personsLabels($personNdx)
	{
		$labels = [];
		if (!$personNdx)
			return $labels;

		$rows = $this->app()->db()->query ('SELECT clsfItem FROM [e10_base_clsf] WHERE [tableid] = %s', 'e10.persons.persons', ' AND [recid] = %i', $personNdx);
		foreach ($rows as $r)
			$labels[] = $r['clsfItem'];

		return $labels;
	}
}


/**
 * Class ViewCanteens
 * @package e10pro\canteen
 */
class ViewCanteens extends TableView
{
	var $linkedPersons;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];
		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['i2'] = $props;

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->linkedPersons [$item ['pk']]))
		{
			$item ['t2'] = $this->linkedPersons [$item ['pk']];
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10pro_canteen_canteens]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[title]', '[ndx]']);
		$this->runQuery ($q);
	}


	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->linkedPersons = UtilsBase::linkedPersons ($this->table->app(), $this->table, $this->pks);
	}
}


/**
 * Class FormCanteen
 * @package e10pro\canteen
 */
class FormCanteen extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Doplňková jídla', 'icon' => 'formAdditionalMeals'];
			$tabs ['tabs'][] = ['text' => 'Fakturace', 'icon' => 'formInvoicing'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('title');
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');

					$this->addSeparator(self::coH4);
					$this->addColumnInput ('lunchMenuFoodCount');
					$this->addColumnInput ('lunchMenuSoup');
					$this->addColumnInput ('lunchMenuCookingType');
					if ($this->recData['lunchMenuCookingType'] == 1)
					{
						$this->addColumnInput ('lunchCookFoodCount');
					}
					$this->addColumnInput ('mainFoodTitle');

					$this->addSeparator(self::coH4);
					$this->openRow();
						$this->addColumnInput ('closeOrdersDay');
						$this->addColumnInput ('closeOrdersTime');
						$this->addColumnInput ('closeOrdersSkipWeekends');
					$this->closeRow();
					if ($this->recData['lunchMenuCookingType'] == 1)
					{
						$this->openRow();
							$this->addColumnInput ('closeSelectCookingFoodsDay');
							$this->addColumnInput ('closeSelectCookingFoodsTime');
						$this->closeRow();
						$this->openRow();
							$this->addColumnInput ('forceSelectCookingFoodsDay');
							$this->addColumnInput ('forceSelectCookingFoodsTime');
						$this->closeRow();
					}

					$this->addColumnInput ('autoOrderFoods');
					$this->addColumnInput ('sendingEmailsDisabled');

					$this->addSeparator(self::coH4);
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addListViewer ('addFoods', 'default');
				$this->closeTab ();

				$this->openTab ();
					$this->addColumnInput ('invoicingEnabled');
					if ($this->recData['invoicingEnabled'])
					{
						$this->addColumnInput('itemMainFood');
						$this->addColumnInput('dstDocKind');
						$this->addColumnInput('dueDays');
						$this->addColumnInput('invoiceAuthor');
						$this->addColumnInput('dstDocState');
						if ($this->recData['dstDocState'] === CreateDocumentUtility::sdsDone)
							$this->addColumnInput('dstDocAutoSend');
					}
				$this->closeTab();

				$this->openTab ();
					$this->addColumnInput ('webServer');
					$this->addColumnInput ('timeoutLogoutTakeTerminal');
					$this->addColumnInput ('dateWorkingFrom');
					$this->addColumnInput ('supplierEmail');
					$this->addColumnInput ('sendSupplierOrderTime');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}
