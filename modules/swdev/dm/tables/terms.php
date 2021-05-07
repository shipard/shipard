<?php

namespace swdev\dm;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableTerms
 * @package swdev\dm
 */
class TableTerms extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.dm.terms', 'swdev_dm_terms', 'Pojmy', 1324);
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];

		return $h;
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset($recData['srcLanguage']) || $recData['srcLanguage'] == 0)
			$recData['srcLanguage'] = 6;
	}

	public function checkAfterSave2 (&$recData)
	{
		if (isset($recData['ndx']) && $recData['ndx'] == 1)
			$this->db()->query('UPDATE [swdev_dm_terms] SET ndx = 60000 WHERE ndx = 1');
	}

	public function getSeeAlsoLinks($recData, &$links, $withText = FALSE)
	{
		$textRenderer = NULL;
		if ($withText)
			$textRenderer = new \lib\core\texts\Renderer($this->app());

		// -- see also doclinks
		$q[] = 'SELECT docLinks.*, [terms].fullName AS termName, [terms].dmWikiPage AS dmWikiPage';
		if ($withText)
			array_push($q, ', [terms].[text]');
		array_push($q, ' FROM [e10_base_doclinks] AS docLinks');
		array_push($q, ' LEFT JOIN [swdev_dm_terms] AS [terms] ON docLinks.dstRecId = [terms].ndx');
		array_push($q, ' WHERE srcTableId = %s', 'swdev.dm.terms', 'AND dstTableId = %s', 'swdev.dm.terms');
		array_push($q, ' AND docLinks.linkId = %s', 'swdev-dm-terms-see-also', 'AND srcRecId = %i', $recData['ndx']);
		array_push($q, ' ORDER BY [terms].fullName');

		$seeAlso = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['type' => 0, 'ndx' => $r['dstRecId'], 'title' => $r['termName'], 'dmWikiPage' => $r['dmWikiPage']];
			if ($withText)
			{
				$textRenderer->render($r['text']);
				$item['textCode'] = $textRenderer->code;
			}
			$seeAlso[] = $item;
		}

		if (count($seeAlso))
			$links[] = ['linkType' => 'see-also', 'linkTitle' => 'Viz také', 'links' => $seeAlso];

		// -- links from other terms
		$q = [];
		$q[] = 'SELECT docLinks.*, [terms].fullName AS termName, [terms].dmWikiPage AS dmWikiPage';
		if ($withText)
			array_push($q, ', [terms].[text]');
		array_push($q, ' FROM [e10_base_doclinks] AS docLinks');
		array_push($q, ' LEFT JOIN [swdev_dm_terms] AS [terms] ON docLinks.srcRecId = [terms].ndx');
		array_push($q, ' WHERE srcTableId = %s', 'swdev.dm.terms', 'AND dstTableId = %s', 'swdev.dm.terms');
		array_push($q, ' AND docLinks.linkId = %s', 'swdev-dm-terms-see-also',
			'AND dstRecId = %i', $recData['ndx'], 'AND srcRecId != %i', $recData['ndx']);
		array_push($q, ' ORDER BY [terms].fullName');

		$otherLinks = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['type' => 0, 'ndx' => $r['srcRecId'], 'title' => $r['termName'], 'dmWikiPage' => $r['dmWikiPage']];
			if ($withText)
			{
				$textRenderer->render($r['text']);
				$item['textCode'] = $textRenderer->code;
			}
			$otherLinks[] = $item;
		}

		if (count($otherLinks))
			$links[] = ['linkType' => 'from-other-terms', 'linkTitle' => 'Odkazy odjinud', 'links' => $otherLinks];
	}
}


/**
 * Class ViewTerms
 * @package swdev\dm
 */
class ViewTerms extends TableView
{
	var $deviceInfo = [];

	public function init ()
	{
		parent::init();

		$this->setMainQueries ();

		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		if ($item['shortName'] !== '')
			$listItem ['t2'] = $item['shortName'];
		else
			$listItem ['t2'] = ['code' => '&nbsp;'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [terms].*';

		array_push ($q, ' FROM [swdev_dm_terms] AS [terms]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'(terms.[fullName] LIKE %s', '%'.$fts.'%',
				' OR terms.[shortName] LIKE %s', '%'.$fts.'%',
				' OR terms.[text] LIKE %s', '%'.$fts.'%',
				')'
			);
			array_push($q, ')');
		}

		// -- special queries
		$qv = $this->queryValues ();

		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE devices.ndx = recid AND tableId = %s', 'mac.lan.devices');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'terms.', ['[terms].[fullName]', '[terms].[ndx]']);

		$this->runQuery ($q);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- tags
		$clsf = \E10\Base\classificationParams ($this->table);
		foreach ($clsf as $cg)
		{
			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.clsf.'.$cg['id'], ['items' => $cg['items']]);
			$qry[] = ['style' => 'params', 'title' => $cg['name'], 'params' => $params];
		}

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class FormModule
 * @package swdev\dm
 */
class FormTerm extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
		$tabs ['tabs'][] = ['text' => 'Text', 'icon' => 'icon-file-text-o'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-wrench'];
		$this->layoutOpen (TableForm::ltGrid);
			$this->openRow ('grid-form-tabs');
				$this->addColumnInput ('fullName', self::coColW8);
				$this->addColumnInput ('shortName', self::coColW4);
			$this->closeRow ();
		$this->layoutClose();
		$this->openTabs ($tabs);
			$this->openTab (self::ltNone);
				$this->addInputMemo ('text', NULL, TableForm::coFullSizeY);
			$this->closeTab ();
			$this->openTab ();
				$this->addList('doclinks');
				$this->addList('clsf');
				$this->addColumnInput ('srcLanguage');
			$this->closeTab ();
		$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailTerm
 * @package swdev\dm
 */
class ViewDetailTerm extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('swdev.dm.dc.DMTerm');
	}
}


/**
 * Class ViewDetailTermTrData
 * @package swdev\dm
 */
class ViewDetailTermTrData extends TableViewDetail
{
	public function createDetailContent ()
	{
		//$this->addDocumentCard('swdev.dm.dc.DMTermTrData');
	}
}
