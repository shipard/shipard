<?php

namespace e10\web;


use \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\TableViewPanel, \e10\DbTable, \e10\utils;


/**
 * Class TableArticles
 * @package e10\web
 */
class TableArticles extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.web.articles', 'e10_web_articles', 'Články');
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);
		$recData ['author'] = $this->app()->userNdx();
		//$recData ['datePub'] = utils::today();
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$author = $this->loadItem ($recData ['author'], 'e10_persons_persons');
		$ndx = $recData ['ndx'];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function previewUrl ($recData)
	{
		$server = NULL;
		$articlesUrl = '';

		$allServersPages = $this->app()->cfgItem ('e10.web.pages');
		foreach ($allServersPages as $serverNdx => $serverPages)
		{
			if (!isset($serverPages['urlsWithArticles']) || !count($serverPages['urlsWithArticles']))
				continue;
			foreach ($serverPages['urlsWithArticles'] as $serverArticlesUrl => $sections)
			{
				if (in_array($recData['articleSection'], $sections))
				{
					$server = $this->app()->cfgItem ('e10.web.servers.list.'.$serverNdx);
					$articlesUrl = $serverArticlesUrl;
					break;
				}
			}
		}

		if ($server)
		{
			$url = $this->app()->urlProtocol . $_SERVER['HTTP_HOST'].$this->app()->dsRoot . '/www/'.$server['urlStartSec'];
			$url .= '/'.$articlesUrl.'/'.$recData['ndx'];

			return $url;
		}

		$url = $this->app()->urlProtocol . $_SERVER['HTTP_HOST'].$this->app()->dsRoot . '/www/'.$server['urlStartSec'];
		$url .= '/error_404';

		return $url;
	}
}


/**
 * Class ViewWebArticles
 * @package e10\web
 */
class ViewWebArticles extends TableView
{
	var $tableArticlesSections;
	var $allowedSections;
	var $allSections;

	public function init ()
	{
		parent::init();

		$this->setMainQueries();

		$this->tableArticlesSections = $this->app()->table('e10.web.articlesSections');

		$this->allSections = $this->app()->cfgItem ('e10.web.articlesSections');
		$sections = $this->tableArticlesSections->usersSections();
		$this->allowedSections = array_keys($sections);
		$activeSectionNdx = key($sections);

		forEach ($sections as $sectionId => $section)
		{
			$bt [] = [
				'id' => $sectionId, 'title' => $section['sn'], 'active' => ($sectionId == $activeSectionNdx),
				'addParams' => ['articleSection' => $sectionId]
			];
		}
		$bt [] = ['id' => '0', 'title' => 'Vše', 'active' => 0];
		$this->setBottomTabs ($bt);
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$sectionNdx = intval($this->bottomTabId ());

		$q [] = 'SELECT articles.*, persons.fullName AS authorFullName FROM [e10_web_articles] AS articles';
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON articles.author = persons.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([title] LIKE %s OR [text] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');

		if ($sectionNdx > 0)
		{
			array_push($q, ' AND articles.[articleSection] = %s', $sectionNdx);
		}
		elseif (count($this->allowedSections))
		{
			array_push($q, ' AND articles.[articleSection] IN %in', $this->allowedSections);
		}
		else
		{
			array_push($q, ' AND 0');
		}

		$this->queryMain ($q, 'articles.', ['[onTop] DESC' , '[datePub] DESC', '[ndx] DESC']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['title'];

		if ($item['authorFullName'])
			$listItem ['t2'][] = ['icon' => 'icon-user', 'text' => $item['authorFullName'], 'class' => ''];

		$dates = [];
		if ($item['onTop'])
			$dates[] = ['text' => '', 'icon' => 'icon-thumb-tack', 'class' => 'e10-success'];
		if ($item['datePub'])
			$dates[] = ['text' => utils::datef($item['datePub'], '%D'), 'icon' => 'icon-play', 'class' => ''];
		if ($item['dateClose'])
			$dates[] = ['text' => utils::datef($item['dateClose'], '%D'), 'icon' => 'icon-stop', 'class' => ''];
		if (count($dates))
			$listItem ['i2'] = $dates;

		$sectionNdx = intval($this->bottomTabId ());
		if ($sectionNdx == 0)
			$listItem ['t2'][] = ['text' => $this->allSections [$item['articleSection']]['sn'], 'class' => 'label label-info', 'icon' => 'icon-folder'];

		return $listItem;
	}
}


/**
 * Class ViewDetailArticlePreview
 * @package e10\web
 */
class ViewDetailArticlePreview extends TableViewDetail
{
	public function createDetailContent ()
	{
		$url = $this->table->previewUrl ($this->item);
		$this->addContent(['type' => 'url', 'url' => $url, 'fullsize' => 1]);
	}
}


/**
 * Class FormArticle
 * @package e10\web
 */
class FormArticle extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$this->layoutOpen (TableForm::ltGrid);
				$this->openRow ('grid-form-tabs');
					$this->addColumnInput ("title", TableForm::coColW8);
					$this->addColumnInput ("datePub", TableForm::coColW4);
				$this->closeRow ();
			$this->layoutClose ();

			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Perex', 'icon' => 'formPerex'];
			$tabs ['tabs'][] = ['text' => 'Zatřídění', 'icon' => 'system/formSorting'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs);
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('text', NULL, TableForm::coFullSizeY);
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('perex', NULL, TableForm::coFullSizeY);
				$this->closeTab ();

				$this->openTab ();
					$this->addColumnInput ('coverImage');
					$this->addColumnInput ('onTop');
					$this->addColumnInput ('description');
					$this->addColumnInput ('author');
					$this->addColumnInput ('dateClose');
					$this->addColumnInput ('articleSection');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

