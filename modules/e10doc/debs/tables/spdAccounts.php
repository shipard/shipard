<?php

namespace e10doc\debs;

use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;


/**
 * Class TableSpdAccounts
 * @package e10doc\debs
 */
class TableSpdAccounts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.debs.spdAccounts', 'e10doc_debs_spdAccounts', 'Nastavení účtů výkazů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => ' '];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['accountsMask']];

		return $hdr;
	}

	function loadSpreadsheetCfg ($spreadsheetId)
	{
		$parts = explode ('.', $spreadsheetId);
		$shortId = array_pop ($parts);

		$replace = $this->db()->query ("SELECT * FROM [e10_base_templates] WHERE [replaceId] = %s", $spreadsheetId)->fetch ();
		if ($replace)
		{
			$fullFileName = __APP_DIR__ . '/templates/'.$replace['sn'].'/'.$shortId.'.json';
		}
		else
		{
			$coreFileName = __SHPD_MODULES_DIR__.implode ('/', $parts).'/spreadsheets/'.$shortId;
			$fullFileName = $coreFileName.'.json';
		}

		$cfg = utils::loadCfgFile($fullFileName);
		return $cfg;
	}

	function enumSpreadsheetId ()
	{
		$enum = [];

		$balanceSheets = $this->app()->cfgItem ('e10.acc.balanceSheets');
		foreach ($balanceSheets as $bsId => $bsDef)
		{
			foreach ($bsDef['variants'] as $variantId => $variantDef)
			{
				if (isset($variantDef['fullSpreadsheetId']))
					continue;
				$enum[$variantDef['spreadsheetId']] = $variantDef['reportTitle'].' - '.$bsDef['shortName'];
			}
		}

		$statements = $this->app()->cfgItem ('e10.acc.statements');
		foreach ($statements as $stId => $stDef)
		{
			foreach ($stDef['variants'] as $variantId => $variantDef)
			{
				if (isset($variantDef['fullSpreadsheetId']))
					continue;
				$enum[$variantDef['spreadsheetId']] = $variantDef['reportTitle'].' - '.$stDef['shortName'];
			}
		}

		return $enum;
	}

	function enumSpreadsheetTable ($recData)
	{
		$enum = [];
		if (!$recData)
			return $enum;


		$spd = $this->loadSpreadsheetCfg ($recData['spreadsheetId']);
		foreach ($spd['pattern']['tables'] as $tableNdx => $tableDef)
		{
			$tableNdxStr = strval($tableNdx);
			$enum[$tableNdxStr] = $tableDef['tableId'];
		}

		return $enum;
	}

	function enumSpreadsheetRow ($recData)
	{
		$enum = [];
		if (!$recData)
			return $enum;


		$spd = $this->loadSpreadsheetCfg ($recData['spreadsheetId']);
		$table = $spd['pattern']['tables'][$recData['spreadsheetTable']];
		$rowInfo = $table['rowInfo'];

		foreach ($table['rows'] as $rowNdx => $rowDef)
		{
			$rowNdxStr = strval($rowNdx);
			$rowTitle = '';

			if (isset($recData['spreadsheetCol']))
			{
				$cellValue = $rowDef[$recData['spreadsheetCol']];
				if (substr($cellValue, 0, 2) === '=[')
					continue;
			}

			foreach ($rowInfo['shortName']['cols'] as $nc)
				$rowTitle .= $rowDef[$nc];

			$rowTitle .= ' - ';

			foreach ($rowInfo['fullName']['cols'] as $nc)
				$rowTitle .= $rowDef[$nc];

			$enum[$rowNdxStr] = $rowTitle;
		}

		return $enum;
	}

	function enumSpreadsheetCol ($recData)
	{
		$enum = [];
		if (!$recData)
			return $enum;


		$spd = $this->loadSpreadsheetCfg ($recData['spreadsheetId']);
		$table = $spd['pattern']['tables'][$recData['spreadsheetTable']];
		$rowInfo = $table['rowInfo'];

		foreach ($table['columns'] as $colNdx => $colDef)
		{
			if (!isset($colDef['shortName']))
				continue;

			$colNdxStr = strval($colNdx);
			$colTitle = '';

			$colTitle .= $colDef['title'];
			$colTitle .= ' - ';
			$colTitle .= $colDef['shortName'];

			$enum[$colNdxStr] = $colTitle;
		}

		return $enum;
	}

	public function columnInfoEnumSrc ($columnId, $form)
	{
		if ($columnId === 'spreadsheetId')
			return $this->enumSpreadsheetId();

		if ($columnId === 'spreadsheetTable')
			return $this->enumSpreadsheetTable($form ? $form->recData : NULL);

		if ($columnId === 'spreadsheetRow')
			return $this->enumSpreadsheetRow($form ? $form->recData : NULL);

		if ($columnId === 'spreadsheetCol')
			return $this->enumSpreadsheetCol($form ? $form->recData : NULL);

		return parent::columnInfoEnumSrc ($columnId, $form);
	}

	function getEnumsInfo ($recData)
	{
		$info = [];

		$spreadsheets = $this->enumSpreadsheetId();
		$info['spreadsheetId'] = $spreadsheets[$recData['spreadsheetId']];

		$tables = $this->enumSpreadsheetTable($recData);
		$info['spreadsheetTable'] = $tables[$recData['spreadsheetTable']];

		$rows = $this->enumSpreadsheetRow($recData);
		$info['spreadsheetRow'] = $rows[$recData['spreadsheetRow']];

		$cols = $this->enumSpreadsheetCol($recData);
		$info['spreadsheetCol'] = $cols[$recData['spreadsheetCol']];

		return $info;
	}
}


/**
 * Class ViewSpdAccounts
 * @package e10doc\debs
 */
class ViewSpdAccounts extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [e10doc_debs_spdAccounts] WHERE 1';

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, 'AND [accountsMask] LIKE %s', '%'.$fts.'%');
		}

		$this->queryMain ($q, '', ['[spreadsheetId]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$info = $this->table->getEnumsInfo ($item);

		$listItem ['t1'] = $item['accountsMask'];
		$listItem ['t2'] = $info['spreadsheetId'];

		$props = [];
		$props[] = ['text' => $info['spreadsheetTable'], 'class' => 'label label-default'];
		$props[] = ['text' => $info['spreadsheetRow'], 'class' => 'label label-default'];
		$props[] = ['text' => $info['spreadsheetCol'], 'class' => 'label label-default'];
		$listItem ['t3'] = $props;

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}
}


/**
 * Class ViewDetailSpdAccount
 * @package e10doc\debs
 */
class ViewDetailSpdAccount extends TableViewDetail
{
}


/**
 * Class FormSpdAccount
 * @package e10doc\debs
 */
class FormSpdAccount extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		//$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('spreadsheetId');
			$this->addColumnInput ('spreadsheetTable');
			$this->addColumnInput ('spreadsheetCol');
			$this->addColumnInput ('spreadsheetRow');
			$this->addColumnInput ('accountsMask');
		$this->closeForm ();
	}
}

