<?php

namespace e10\web;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableUrlDecorations
 */
class TableUrlDecorations extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.web.urldecorations', 'e10_web_urldecorations', 'Dekorace stránek');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$urlParts = explode ('/', $recData ['url']);
		$l = array_pop($urlParts);
		if (count ($urlParts) <= 1)
		{
			if ($recData ['url'] == '/')
				$recData ['parentUrl'] = '';
			else
				$recData ['parentUrl'] = '/';
		}
		else
			$recData ['parentUrl'] = implode ('/', $urlParts);

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['url']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);
		$this->checkTree ('', '', 0);

		$this->saveConfig();
		\E10\compileConfig ();
	}

	public function checkTree ($ownerUrl, $ownerTreeId, $level)
	{
		$treeRows = $this->app()->db->query ("SELECT * FROM [e10_web_urldecorations] WHERE [docState] !=9800 AND [parentUrl] = %s ORDER BY [url]", $ownerUrl);
		$rowIndex = 1;
		forEach ($treeRows as $row)
		{
			$rowTreeId = $ownerTreeId . sprintf ("%03d", $rowIndex);

			$rowUpdate = [];
			$rowUpdate ['treeLevel'] = $level;
			$rowUpdate ['treeId'] = $rowTreeId;

			$this->app()->db->query ("UPDATE [e10_web_urldecorations] SET", $rowUpdate, " WHERE [ndx] = %i", $row ['ndx']);
			$this->checkTree ($row ['url'], $rowTreeId, $level + 1);

			$rowIndex++;
		}
	}

	public function saveConfig ()
	{
		// -- item types
		$urlDecorations = array ();

		$rows = $this->app()->db->query ('SELECT * FROM [e10_web_urldecorations] WHERE docState != 9800 ORDER BY [order], [ndx]');

		foreach ($rows as $r)
		{
			$ud = [
				'type' => $r ['decorationType'], 'text' => $r['text'], 'st' => $r['useAsSubtemplate'],
				'thisUrl' => $r['useOnThisUrl'], 'subUrls' => $r['useOnSubUrls']
			];
			$urlDecorations [$r['server']][$r['url']][] = $ud;
		}

		// save types to file
		$cfg ['e10']['web']['urlDecorations'] = $urlDecorations;
		file_put_contents(__APP_DIR__ . '/config/_e10.web.urlDecorations.json', json_encode ($cfg));
	}
}


/**
 * class ViewUrlDecorations
 */
class ViewUrlDecorations extends TableView
{
	public function init ()
	{
		parent::init();

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['title'];
		$listItem ['t2'] = $item['url'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = "SELECT * FROM [e10_web_urldecorations] WHERE 1";

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([title] LIKE %s OR [url] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '', ['[treeId]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class ViewDetailUrlDecorations
 */
class ViewDetailUrlDecorations extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'text', 'subtype' => 'code', 'text' => $this->item['text']]);
	}
}


/**
 * class FormUrlDecoration
 */
class FormUrlDecoration extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Text', 'icon' => 'formText'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('url');
					$this->addColumnInput ('decorationType');
					$this->addColumnInput ('title');
					$this->addColumnInput ('useOnThisUrl');
					$this->addColumnInput ('useOnSubUrls');
					$this->addColumnInput ('useAsSubtemplate');
					$this->addColumnInput ('order');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ("text", NULL, TableForm::coFullSizeY);
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('server');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

