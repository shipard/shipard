<?php

namespace e10settings\base;

use E10\json;
use \E10\utils, \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable, \e10\DataModel;


/**
 * Class TableCfgs
 * @package e10settings\base
 */
class TableCfgs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10settings.base.cfgs', 'e10settings_base_cfgs', 'Konfigurace aplikace');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function saveConfig ()
	{
		array_map ('unlink', glob (__APP_DIR__ . '/config/_z_*.json'));

		$rows = $this->app()->db->query ('SELECT * FROM [e10settings_base_cfgs] WHERE docState != 9800 ORDER BY [id]');
		foreach ($rows as $r)
		{
			$fileName = __APP_DIR__ . '/config/_z_'.utils::safeChars($r['id'].'-'.$r['ndx'], TRUE).'.json';
			if ($r['docState'] == 9800)
			{
				if (is_file($fileName))
				{
					unlink($fileName);
					continue;
				}
			}

			if (!$r['valid'])
				continue;

			$cfgPath = explode('.', $r['id']);
			$cfgRoot = [];
			$cfgDest = &$cfgRoot;
			foreach ($cfgPath as $key)
			{
				$cfgDest[$key] = [];
				$cfgDest = &$cfgDest[$key];
			}

			$cfgDest = json::decode($r['code']);
			file_put_contents($fileName, json::lint($cfgRoot));
		}
	}
}


/**
 * Class ViewCfgs
 * @package e10settings\base
 */
class ViewCfgs extends TableView
{
	public function init ()
	{
		parent::init();
		$this->linesWidth = 33;
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t2'] = $item['id'];
		$listItem ['t1'] = $item['title'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		if (!$item['valid'])
		{
			$listItem ['class'] = 'e10-warning2';
			$listItem ['icon'] = 'system/iconWarning';
		}

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10settings_base_cfgs]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' [title] LIKE %s', '%'.$fts.'%', ' OR [id] LIKE %s', '%'.$fts.'%',
					' OR [code] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[id]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailCfg
 * @package e10settings\base
 */
class ViewDetailCfg extends TableViewDetail
{
	public function createDetailContent ()
	{
		$code = '';
		$code .= "<pre class='json'><code>";
		$code .= $this->item['code'];
		$code .= '</code></pre>';

		$line = [
			['text' => 'Kód nastavení', 'class' => 'h2 title'],
			['code' => $code],
		];

		$class = 'e10-pane e10-pane-table';
		if (!$this->item['valid'])
			$class .= ' e10-warning2';
		$this->addContent(['pane' => $class, 'type' => 'line', 'line' => $line]);
	}
}


/**
 * Class FormCfg
 * @package e10settings\base
 */
class FormCfg extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Kód', 'icon' => 'formScript'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('code', NULL, TableForm::coFullSizeY, DataModel::ctCode);
				$this->closeTab ();

				$this->openTab ();
					$this->addColumnInput ('title');
					$this->addColumnInput ('id');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	public function checkBeforeSave (&$saveData)
	{
		$test = json::decode($this->recData['code']);
		if (!$test)
		{
			$saveData['recData']['valid'] = 0;
			$this->saveResult['notifications'][] = ['style' => 'error', 'title' => 'Kód nastavení nelze zpracovat - patrně obsahuje syntaktickou chybu',
					'msg' => "<code>".json_last_error_msg().'</code>', 'mode' => 'top'];
			$this->saveResult['disableClose'] = 1;
		}
		else
			$saveData['recData']['valid'] = 1;

		parent::checkBeforeSave($saveData);
	}
}

