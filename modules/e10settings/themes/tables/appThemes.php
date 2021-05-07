<?php

namespace e10settings\themes;

use \e10\utils, \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable, \e10\DataModel;


/**
 * Class TableAppThemes
 * @package e10settings\themes
 */
class TableAppThemes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10settings.themes.appThemes', 'e10settings_themes_appThemes', 'Témata aplikace');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
		if (!isset ($recData['sn']) || $recData['sn'] === '')
		{
			$recData['sn'] = utils::createToken(35);
		}
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['sn']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * from [e10settings_themes_appThemes] WHERE [docState] != 9800 ORDER BY [name]');
		foreach ($rows as $r)
			$list [$r['sn']] = ['ndx' => $r ['ndx'], 'name' => $r ['name'], 'system' => 0];

		// save to file
		$cfg ['e10settings']['themes']['desktop'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10settings.themes.desktop.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewAppThemes
 * @package e10settings\themes
 */
class ViewAppThemes extends TableView
{
	public function init ()
	{
		parent::init();

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['name'];
		$listItem ['t2'] = $item['sn'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10settings_themes_appThemes]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [name] LIKE %s', '%'.$fts.'%', ' OR [sn] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[name]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailAppTheme
 * @package e10settings\themes
 */
class ViewDetailAppTheme extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

/**
 * Class FormAppTheme
 * @package e10settings\themes
 */
class FormAppTheme extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
		$tabs ['tabs'][] = ['text' => 'Téma', 'icon' => 'icon-paint-brush'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-code'];
		$tabs ['tabs'][] = ['text' => 'Doplnění vzhledu', 'icon' => 'icon-code'];

		$this->openTabs ($tabs, TRUE);
			$this->openTab ();
				$this->addColumnInput ('name');
				//$this->addColumnInput ('type');
			$this->closeTab ();

			$this->openTab (self::ltNone);
				$this->addInputMemo ('codeVariables', NULL, TableForm::coFullSizeY, DataModel::ctCode);
			$this->closeTab ();
			$this->openTab (self::ltNone);
				$this->addInputMemo ('codeDecorators', NULL, TableForm::coFullSizeY, DataModel::ctCode);
			$this->closeTab ();
		$this->closeTabs ();
		$this->closeForm ();
	}

	public function checkAfterSave ()
	{
		$compiler = new \lib\themes\AppThemeCompiler($this->app());
		$compiler->init();
		$result = $compiler->compileTheme($this->recData);

		if ($result !== '')
		{
			$this->saveResult['notifications'][] = ['style' => 'error', 'title' => 'Nelze vytvořit soubor s kaskádovým stylem',
					'msg' => "<code>".utils::es($result).'</code>', 'mode' => 'top'];
			$this->saveResult['disableClose'] = 1;
		}

		return parent::checkAfterSave ();
	}
}

