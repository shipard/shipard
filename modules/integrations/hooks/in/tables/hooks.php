<?php

namespace integrations\hooks\in;
use \e10\utils, \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable;


/**
 * Class TableHooks
 * @package integrations\hooks\in
 */
class TableHooks extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('integrations.hooks.in.hooks', 'integrations_hooks_in_hooks', 'Příchozí Webhooks', 0);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (!isset($recData['urlPart1']) || $recData['urlPart1'] === '')
			$recData['urlPart1'] = utils::guidv4();
		if (!isset($recData['urlPart2']) || $recData['urlPart2'] === '')
			$recData['urlPart2'] = utils::createToken(30);

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		$url = '/hooks/'.$recData['urlPart1'].'/'.$recData['urlPart2'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $url];

		return $hdr;
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		if ($columnId === 'hookSettings')
		{
			$hookTypeCfg = $this->app()->cfgItem('integration.hooks.in.types.'.$recData['hookType'], NULL);
			if (!$hookTypeCfg || !isset($hookTypeCfg['baseDir']))
				return FALSE;

			$cfgFileName = __SHPD_MODULES_DIR__.$hookTypeCfg['baseDir'].'/settings.json';
			$cfg = utils::loadCfgFile($cfgFileName);
			if ($cfg)
				return $cfg['fields'];

			return FALSE;
		}

		return parent::subColumnsInfo ($recData, $columnId);
	}
}


/**
 * Class ViewHooks
 * @package integrations\hooks\in
 */
class ViewHooks extends TableView
{
	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$props = [];

		$props[] = ['text' => $item['urlPart1'], 'class' => 'label label-default'];
		$props[] = ['text' => $item['urlPart2'], 'class' => 'label label-default'];

		if (count($props))
			$listItem ['t2'] = $props;


		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [integrations_hooks_in_hooks] AS hooks';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' hooks.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'hooks.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormHook
 * @package integrations\hooks\in
 */
class FormHook extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
		$tabs ['tabs'][] = ['text' => 'Webhook', 'icon' => 'icon-cloud-download'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-wrench'];
		$this->openTabs ($tabs, TRUE);
			$this->openTab ();
				$this->addColumnInput('fullName');
				$this->addColumnInput('hookType');
				$this->addColumnInput('runAsUser');
			$this->closeTab();
			$this->openTab ();
				$this->addSubColumns('hookSettings');
			$this->closeTab();
		$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailHook
 * @package integrations\hooks\in
 */
class ViewDetailHook extends TableViewDetail
{
}


