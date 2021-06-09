<?php

namespace E10Doc\Debs;
require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \E10\Application, \E10\utils;
use \E10\TableView, \E10\TableViewDetail;
use \E10\TableForm;
use \E10\HeaderData;
use \E10\DbTable;

class TableAccounts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10doc.debs.accounts", "e10doc_debs_accounts", "Účtový rozvrh");
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

		$recData['g1'] = '';
		$recData['g2'] = '';
		$recData['g3'] = '';
		if ((!isset($recData['accGroup']) || !$recData['accGroup']) && $recData['accMethod'] === 'debs')
		{
			$recData['g1'] = substr($recData['id'], 0, 1);
			$recData['g2'] = substr($recData['id'], 0, 2);
			$recData['g3'] = substr($recData['id'], 0, 3);
		}
	}

	public function columnInfoEnumSrc ($columnId, $form)
	{
		if ($columnId === 'accountKind' && $form && $form->recData['accMethod'] === 'sebs')
		{
			$column = $this->app()->model()->column ($this->tableId, $columnId);
			$enum = $column['enumValues'];
			$enum['2'] = 'Výdaje';
			$enum['3'] = 'Příjmy';
			return $enum;
		}

		return parent::columnInfoEnumSrc ($columnId, $form);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		$hdr ['info'][] = array ('class' => 'info', 'value' => $recData ['id']);
		$hdr ['info'][] = array ('class' => 'title', 'value' => $recData ['fullName']);

		return $hdr;
	}

	public function checkAfterSave2 (&$recData)
	{
		if ($recData ['docStateMain'] == 2 && $recData ['accItem'])
		{ // check accounting item
			$accItem = $this->app()->db()->fetch('SELECT * FROM [e10_witems_items] WHERE [debsAccountId] = %s', $recData['id']);
			if (!$accItem)
			{
				$accItemType = $this->app()->db()->fetch('SELECT * FROM [e10_witems_itemtypes] WHERE [type] = 2');
				if ($accItemType)
				{
					$newItemRec = [
						'debsAccountId' => $recData['id'], 'id' => $recData['id'],
						'fullName' => $recData['fullName'], 'shortName' => $recData['shortName'],
						'useFor' => $recData['useFor'], 'useBalance' => $recData['useBalance'],
						'itemType' => $accItemType ['ndx'], 'type' => $accItemType ['id'], 'itemKind' => 2,
						'docState' => 4000, 'docStateMain' => 2
					];
					$tableItems = $this->app()->table ('e10.witems.items');
					$tableItems->dbInsertRec($newItemRec);
				}
			}
		}
	}
} // class TableAccounts


/**
 * Class ViewAccounts
 * @package E10Doc\Debs
 */
class ViewAccounts extends \E10\TableViewGrid
{
	var $today;

	public function init ()
	{
		$this->today = utils::today();
		$this->topParams = new \e10doc\core\libs\GlobalParams ($this->table->app());
		$this->topParams->addParam ('fiscalPeriod', 'queryFiscalPeriod',
				['colWidth' => 4, 'flags' => ['enableAll', 'years'], 'defaultValue' => '0']);

		parent::init();

		$this->gridEditable = TRUE;
		$this->classes = ['editableGrid'];
		$this->enableToolbar = FALSE;
		$this->enableDetailSearch = TRUE;
		//$this->objectSubType = TableView::vsMain;

		$mq [] = array ('id' => 'active', 'title' => 'Platné');
		$mq [] = array ('id' => 'archive', 'title' => 'Archív');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		$usedAccMethods = $this->app()->cfgItem ('e10doc.acc.usedMethods');
		if (count($usedAccMethods) === 1)
		{
			$amId = key($usedAccMethods);
			$this->addAddParam ('accMethod', $amId);
			$this->queryParams ['accMethod'] = $amId;
		}
		else
		if (count($usedAccMethods) >= 1)
		{
			$allAccMethods = $this->app()->cfgItem ('e10doc.acc.methods');
			foreach ($allAccMethods as $amId => $am)
			{
				if (!isset($usedAccMethods[$amId]) || !$usedAccMethods[$amId])
					continue;
				$bt [] = [
					'id' => $amId, 'title' => $am['title'], 'active' => 0,
					'addParams' => ['accMethod' => $amId]
				];
			}
			$bt [0]['active'] = 1;
			$this->setBottomTabs ($bt);
		}


		$g = [
			'id' => 'Účet',
			'fullName' => 'Název',
			//'shortName' => 'Krátký název',
			'props' => '_Vlastnosti',
		];
		$this->setGrid ($g);

		$this->setInfo('title', 'Účtový rozvrh');
		$this->setInfo('icon', 'icon-th');
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [e10doc_debs_accounts] WHERE 1';

		// -- accMethod
		if ($this->queryParam('accMethod') != '')
			array_push ($q, " AND [accMethod] = %s", $this->queryParam('accMethod'));
		else
		if ($this->bottomTabId () != '')
			array_push ($q, " AND [accMethod] = %s", $this->bottomTabId ());

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, " AND (");
			array_push ($q, "[fullName] LIKE %s", '%'.$fts.'%');
			array_push ($q, " OR ");
			array_push ($q, "[shortName] LIKE %s", '%'.$fts.'%');
			array_push ($q, " OR ");
			array_push ($q, "[id] LIKE %s", $fts.'%');
			array_push ($q, ")");
		}

		$fp = $this->topParamsValues['queryFiscalPeriod']['value'];
		if ($fp != '0')
		{
			$fpDef = $this->topParamsValues['queryFiscalPeriod']['values'][$fp];
			$this->setInfo('param', 'Období', $this->topParamsValues['queryFiscalPeriod']['activeTitle']);

			array_push ($q, ' AND ([validFrom] IS NULL OR [validFrom] <= %d)', $fpDef['dateBegin']);
			array_push ($q, ' AND ([validTo] IS NULL OR [validTo] >= %d)', $fpDef['dateEnd']);
		}

		// -- aktuální
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND [docStateMain] < 4");

		// -- archív
		if ($mainQuery == 'archive')
			array_push ($q, " AND [docStateMain] = 5 ");

		// koš
		if ($mainQuery == 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [id], [ndx]' . $this->sqlLimit());
		else
			array_push ($q, ' ORDER BY [docStateMain], [id], [ndx]' . $this->sqlLimit ());

		$this->runQuery ($q);
	} // selectRows


	public function renderRow ($item)
	{
		$accountKind = $this->table->columnInfoEnum ('accountKind', 'cfgText');

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['id'] = $item['id'];
		$listItem ['shortName'] = $item['shortName'];
		$listItem ['fullName'] = $item['shortName'];

		if ($item['accGroup'])
		{
			$listItem ['class'] = 'g';
		}

		$props = [];
		if (!$item['accGroup'])
		{
			$props [] = ['text' => $accountKind [$item ['accountKind']]];

			if ($item['accountKind'] === 2 || $item['accountKind'] === 3)
			{
				if ($item ['nontax'])
					$props [] = ['text' => 'Daňově neuznatelný'];
				if ($item ['excludeFromReports'])
					$props [] = ['text' => 'Nedávat do přehledů'];
			}
		}

		if ($item['validFrom'])
			$props [] = ['text' => utils::datef($item['validFrom']), 'icon' => 'icon-step-backward'];
		if ($item['validTo'])
			$props [] = ['text' => utils::datef($item['validTo']), 'icon' => 'icon-step-forward'];

		if (count($props))
			$listItem ['props'] = $props;

		$fp = $this->topParamsValues['queryFiscalPeriod']['value'];
		if ($fp == '0')
		{
			if (!utils::dateIsBlank($item['validFrom']) || !utils::dateIsBlank($item['validTo']))
			{
				if ((!utils::dateIsBlank($item['validFrom']) && $item['validFrom'] > $this->today) ||
						(!utils::dateIsBlank($item['validTo']) && $item['validTo'] < $this->today))
					$listItem ['class'] = (isset($listItem ['class'])) ? $listItem ['class'] . 'e10-off e10-em' : 'e10-off e10-em';
			}
		}

		return $listItem;
	}
} // class ViewAccounts


/**
 * Class ViewAccountsCombo
 * @package E10Doc\Debs
 */
class ViewAccountsCombo extends TableView
{
	public function init ()
	{
		$mq [] = array ('id' => 'active', 'title' => 'Platné');
		$mq [] = array ('id' => 'archive', 'title' => 'Archív');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		$usedAccMethods = $this->app()->cfgItem ('e10doc.acc.usedMethods');
		if (count($usedAccMethods) === 1)
		{
			$amId = key($usedAccMethods);
			$this->addAddParam ('accMethod', $amId);
			$this->queryParams ['accMethod'] = $amId;
		}
		else
		{
			forEach ($usedAccMethods as $amId => $amV)
			{
				$am = $this->app()->cfgItem ('e10doc.acc.methods.'.$amId);
				$bt [] = array ('id' => $amId, 'title' => $am['title'], 'active' => 0,
					'addParams' => array ('accMethod' => $amId));
			}
			$bt [0]['active'] = 1;
			$this->setBottomTabs ($bt);
		}

		parent::init();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [e10doc_debs_accounts] WHERE accGroup = 0';

		// -- accMethod
		if ($this->queryParam('accMethod') != '')
			array_push ($q, " AND [accMethod] = %s", $this->queryParam('accMethod'));
		else
			array_push ($q, " AND [accMethod] = %s", $this->bottomTabId ());

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, " AND (");
			array_push ($q, "[fullName] LIKE %s", '%'.$fts.'%');
			array_push ($q, " OR ");
			array_push ($q, "[shortName] LIKE %s", '%'.$fts.'%');
			array_push ($q, " OR ");
			array_push ($q, "[id] LIKE %s", $fts.'%');
			array_push ($q, ")");
		}

		// -- aktuální
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND [docStateMain] < 4");

		// -- archív
		if ($mainQuery == 'archive')
			array_push ($q, " AND [docStateMain] = 5 ");

		// koš
		if ($mainQuery == 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [id], [ndx]' . $this->sqlLimit());
		else
			array_push ($q, ' ORDER BY [docStateMain], [id], [ndx]' . $this->sqlLimit ());

		$this->runQuery ($q);
	} // selectRows


	public function renderRow ($item)
	{
		$listItem ['data-cc']['debsAccountId'] = $item['id'];

		$accountKind = $this->table->columnInfoEnum ('accountKind', 'cfgText');

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['id'];
		$listItem ['t2'] = $item['shortName'];

		if (!$item['accGroup'])
		{
			$listItem ['i1'] = $accountKind [$item ['accountKind']];

			//$resultsType = $this->table->columnInfoEnum ('resultsType', 'cfgText');
			//$props [] = array ('i' => 'file', 'text' => $resultsType [$item ['resultsType']]);

			if ($item['accountKind'] === 2 || $item['accountKind'] === 3)
			{
				if ($item ['nontax'])
					$props [] = array ('i' => 'system/iconAngleRight', 'text' => 'Daňově neuznatelný');
			}

			$listItem ['i2'] = $props;
		}

		return $listItem;
	}
}


/**
 * Základní detail Účtu
 *
 */

class ViewDetailAccount extends TableViewDetail
{
}


/**
 * Class FormAccount
 * @package E10Doc\Debs
 */
class FormAccount extends TableForm
{
	public function renderForm ()
	{
		//$this->setFlag ('maximize', 1);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		//$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = array ('text' => 'Účet', 'icon' => 'icon-th');
		$tabs ['tabs'][] = array ('text' => 'Popis', 'icon' => 'icon-edit');

		$this->openForm ();
			$this->addColumnInput ("id");
			$this->addColumnInput ("fullName");

			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ("shortName");
					$this->addColumnInput ("accountKind");

					if ($this->recData['accountKind'] === 2 || $this->recData['accountKind'] === 3)
					{
						$this->addColumnInput ("nontax");
						if ($this->recData['accMethod'] === 'sebs')
							$this->addColumnInput ('excludeFromReports');
					}

					$this->addColumnInput ("resultsType");

					if (!$this->recData['accGroup'])
					{
						$this->addColumnInput ("toBalance");

						$this->addColumnInput ("accItem");
						if ($this->recData['accItem'])
						{
							$this->addColumnInput ("useFor");
							$this->addColumnInput ("useBalance");
						}
					}
					$this->addColumnInput ("accGroup");
					$this->addSeparator(TableForm::coH2);
					$this->addColumnInput ('validFrom');
					$this->addColumnInput ('validTo');
				$this->closeTab();

				$this->openTab (TableForm::ltNone);
					$this->addColumnInput ('note', TableForm::coFullSizeY);
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
} // class FormAccount

