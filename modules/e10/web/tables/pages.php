<?php

namespace e10\web;
use \Shipard\Application\DataModel, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail;
use \Shipard\Form\TableForm, \Shipard\Viewer\TableViewPanel;
use \Shipard\Table\DbTable, \Shipard\Utils\Utils;


/**
 * class TablePages
 */
class TablePages extends DbTable
{
	var $pagesInfo = [];

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.web.pages', 'e10_web_pages', 'Stránky');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$urlParts = explode ('/', $recData ['url']);
		array_pop($urlParts);
		if (count ($urlParts) <= 1)
				$recData ['parentUrl'] = '';
		else
			$recData ['parentUrl'] = implode ('/', $urlParts);

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);

		$pageModeParams = '';
		if ($recData['pageMode'] === 'articles')
		{
			$sections = [];

			$ql = [];
			array_push ($ql, 'SELECT dstRecId FROM e10_base_doclinks WHERE linkId = %s', 'e10-web-pageArticles-sections');
			array_push ($ql, ' AND srcTableId = %s', 'e10.web.pages', ' AND srcRecId = %i', $recData['ndx']);
			$rows = $this->db()->query ($ql);
			foreach ($rows as $r)
			{
				$sections[] = $r['dstRecId'];
			}
			if (count($sections))
				$pageModeParams = implode(' ', $sections);
		}

		if ($pageModeParams !== $recData['pageModeParams'])
		{
			$recData['pageModeParams'] = $pageModeParams;
			$this->db()->query ('UPDATE [e10_web_pages] SET [pageModeParams] = %s', $pageModeParams, ' WHERE [ndx] = %i', $recData['ndx']);
		}


		$webMap = $this->checkTree ($recData['server'], '', '', 0, NULL);
		$cfg ['e10']['web']['menu'][strval($recData['server'])]['items'] = $webMap;
		file_put_contents(__APP_DIR__ . "/config/_e10.web.menu{$recData['server']}.json", Utils::json_lint (json_encode ($cfg)));


		$cfg = [];
		$cfg ['e10']['web']['pages'][strval($recData['server'])] = $this->pagesInfo;
		file_put_contents(__APP_DIR__ . "/config/_e10.web.pages{$recData['server']}.json", Utils::json_lint (json_encode ($cfg)));

		\E10\compileConfig ();
	}

	public function checkTree ($server, $ownerUrl, $ownerTreeId, $level, $ownerMapItem)
	{
		$webMap = [];

		$treeRows = $this->app()->db->query ("SELECT * FROM [e10_web_pages] WHERE [server] = %i AND [parentUrl] = %s ORDER BY [order], [url]", $server, $ownerUrl);
		$rowIndex = 1;
		forEach ($treeRows as $row)
		{
			if ($row['url'] == '')
				continue;

			if ($row['pageMode'] === 'web' || $row['pageMode'] === 'textPlain')
			{
				if ($row['includeSubUrl'])
					$this->pagesInfo['forcedUrls'][] = $row['url'];
			}
			elseif ($row['pageMode'] !== 'web')
			{
				$this->pagesInfo['forcedUrls'][] = $row['url'];
				if ($row['pageModeParams'] !== '')
				{
					$pmpParts = explode (' ', $row['pageModeParams']);
					foreach ($pmpParts as $pmp)
					{
						$this->pagesInfo['urlsWithArticles'][$row['url']][] = $pmp;
					}
				}
			}

			$thisMap = array ('url' => $row['url']);
			if ($row['menuTitle'] != '')
				$thisMap ['title'] = $row['menuTitle'];
			else
				$thisMap ['title'] = $row['title'];

			if ($row['menuDisabled'])
				$thisMap ['menuDisabled'] = 1;

			$rowTreeId = $ownerTreeId . sprintf ("%03d", $rowIndex);

			if ($row['treeLevel'] != $level || $row['treeId'] != $rowTreeId)
			{
				$rowUpdate ['treeLevel'] = $level;
				$rowUpdate ['treeId'] = $rowTreeId;
				$this->app()->db->query ("UPDATE [e10_web_pages] SET", $rowUpdate, " WHERE [ndx] = %i", $row ['ndx']);
			}
			$subMap = $this->checkTree ($server, $row ['url'], $rowTreeId, $level + 1, $thisMap);
			if (count($subMap))
				$thisMap ['items'] = $subMap;

			if ($row['redirectTo'] != '')
				$thisMap ['redirectTo'] = $row['redirectTo'];

			if ($row['docStateMain'] != 4)
				$webMap [] = $thisMap;
			$rowIndex++;
		}

		return $webMap;
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);
		$recData ['author'] = $this->app()->user()->data ('id');
		$recData ['datePub'] = Utils::today();
		$recData ['pageMode'] = 'web';
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'info', 'value' => array ('text' => $recData ['url'], 'url' => $this->previewUrl($recData))];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function previewUrl ($recData)
	{
		if (!$recData)
			return '';
		$server = $this->app()->cfgItem ('e10.web.servers.list.'.$recData['server'], NULL);
		if (!$server)
			return '';
		$url = $this->app()->urlProtocol . $_SERVER['HTTP_HOST'].$this->app()->dsRoot . '/www/'.$server['urlStartSec'];
		$url .= ($recData['redirectTo']) ? $recData['redirectTo'] : $recData['url'];
		return $url;
	}
}


/**
 * class ViewPages
 */
class ViewPages extends TableView
{
	var $websParam = NULL;

	public function init ()
	{
		$this->linesWidth = 27;

		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);

		// -- bottom tabs / left panel; web servers
		$servers = $this->table->app()->cfgItem ('e10.web.servers.list');

		if (count($servers) > 3)
		{
			$this->usePanelLeft = TRUE;
			$this->linesWidth = 33;

			$enum = [];
			$active = 1;
			$bt = [];
			forEach ($servers as $s)
			{
				$addParams = ['server' => $s['ndx']];
				$nbt = ['id' => $s['ndx'], 'title' => $s['sn'], 'active' => $active, 'addParams' => $addParams];
				$bt [] = $nbt;
				$active = 0;

				$enum[$s['ndx']] = ['text' => $s['sn'], 'addParams' => ['server' => $s['ndx']]];
			}

			$this->websParam = new \E10\Params ($this->app);
			$this->websParam->addParam('switch', 'server', ['title' => '', 'switch' => $enum, 'list' => 1]);
			$this->websParam->detectValues();
		}
		elseif (count($servers) > 1)
		{
			$active = 1;
			$bt = array();
			forEach ($servers as $s)
			{
				$bt [] = [
					'id' => $s['ndx'], 'title' => $s['sn'], 'active' => $active,
					'addParams' => array ('server' => $s['ndx'])
				];
				$active = 0;
			}
			$this->setBottomTabs ($bt);
		}
		else
			$this->addAddParam ('server', key($servers));

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['title'];
		$listItem ['t2'] = $item['url'];
		$listItem ['level'] = $item['treeLevel'];

		$props = [];

		if ($item['menuTitle'])
			$props [] = ['i' => 'hand-up', 'text' => $item['menuTitle']];
		if ($item ['order'] != 0)
			$props [] = ['i' => 'sort', 'text' => \E10\nf ($item ['order'], 0)];
		if ($item ['redirectTo'] != '')
			$props [] = ['icon' => 'system/iconLink', 'text' => $item ['redirectTo']];
		if ($item ['menuDisabled'])
			$props [] = ['icon' => 'icon-times', 'text' => ''];

		if (count($props))
			$listItem ['i2'] = $props;
		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$serverNdx = 0;
		if ($this->websParam)
			$serverNdx = intval($this->websParam->detectValues()['server']['value']);
		else
			$serverNdx = intval($this->bottomTabId ());

		$q [] = "SELECT pages.*, persons.fullName as authorFullName FROM [e10_web_pages] AS pages LEFT JOIN e10_persons_persons AS persons ON pages.author = persons.ndx " .
						"WHERE 1";

		// -- server
		if ($serverNdx)
			array_push ($q, " AND pages.[server] = %i", $serverNdx);
		else
		if (isset ($this->addParams ['__server']))
			array_push ($q, " AND pages.[server] = %i", $this->addParams ['__server']);

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([title] LIKE %s OR [url] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		// -- aktuální
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND pages.[docStateMain] < 4");

		// koš
		if ($mainQuery == 'trash')
			array_push ($q, " AND pages.[docStateMain] = 4");

		array_push ($q, ' ORDER BY [treeId] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	} // selectRows

	public function createPanelContentLeft (TableViewPanel $panel)
	{
		if (!$this->websParam)
			return;

		$qry = [];
		$qry[] = ['style' => 'params', 'params' => $this->websParam];
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * class ViewDetailPageText
 */
class ViewDetailPageText extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'text', 'subtype' => 'plain', 'text' => $this->item['text']]);
	}
}


/**
 * class ViewDetailPagePreview
 */
class ViewDetailPagePreview extends TableViewDetail
{
	public function createDetailContent ()
	{
		$url = $this->table->previewUrl ($this->item);
		$this->addContent(['type' => 'url', 'url' => $url, 'fullsize' => 1]);
	}
}


/**
 * class FormPagesPage
 */
class FormPagesPage extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
		$this->addColumnInput ('title');
		$this->addColumnInput ('url');

		$properties = $this->addList ('properties', '', TableForm::loAddToFormLayout|TableForm::loWidgetParts);
		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		forEach ($properties ['memoInputs'] as $mi)
			$tabs ['tabs'][] = ['text' => $mi ['text'], 'icon' => $mi ['icon']];

		$this->openTabs ($tabs);
			$this->openTab (TableForm::ltNone);
				if ($this->recData['editTextAsCode'])
					$this->addInputMemo ('text', NULL, TableForm::coFullSizeY, DataModel::ctCode);
				else
					$this->addInputMemo ('text', NULL, TableForm::coFullSizeY);
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();

			$this->openTab ();
				$this->addColumnInput('description');
				$this->addColumnInput('order');
				$this->addColumnInput('menuTitle');
				$this->addColumnInput('menuDisabled');
				$this->addColumnInput('redirectTo');
				$this->addColumnInput('server');
				$this->addColumnInput('editTextAsCode');
				$this->addColumnInput('pageMode');
				if ($this->recData['pageMode'] === 'wiki')
					$this->addColumnInput('wiki');
				if ($this->recData['pageMode'] === 'web')
					$this->addColumnInput('includeSubUrl');

				$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
			$this->closeTab ();

			forEach ($properties ['memoInputs'] as $mi)
			{
				$this->openTab ();
					$this->appendCode ($mi ['widgetCode']);
				$this->closeTab ();
			}
		$this->closeTabs ();
		$this->closeForm ();
	}

	public function docLinkEnabled ($docLink)
	{
		if ($this->recData['pageMode'] !== 'articles')
			return FALSE;

		return parent::docLinkEnabled ($docLink);
	}
}

