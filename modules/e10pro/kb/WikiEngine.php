<?php

namespace e10pro\kb;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';


use \e10\Utility, \e10\utils, \e10\json, \e10pro\kb\kbTextsEngine, \lib\persons\LinkedPersons;


/**
 * Class WikiEngine
 * @package e10pro\kb
 */
class WikiEngine extends Utility
{
	CONST modeHome = 0, modeSection = 1, modePage = 2, modeSearch = 3;

	/** @var  \e10\web\webTemplateMustache */
	var $template;
	/** @var  \lib\core\texts\Renderer */
	var $textRenderer;

	var $urlBegin = '';

	var $wikiNdx = 0;
	var $wiki;

	var $sections = [];
	var $page = [];
	var $breadcrumbs = [];

	var $mode = 0;
	var $widgetMode = FALSE;
	var $widget = NULL;

	var $sectionNdx = 0;
	var $pageNdx = 0;
	var $pageId = '/';

	var $ownerPageNdx = 0;
	var $lastOrder = 0;

	var $subTemplateId = 'e10pro.kb.wikiHome';
	var $status = 200;

	var $thisUserId = 0;
	var $sectionsPks = [];

	var $textDocStates;

	var $systemPageTypeCfg = NULL;

	function loadPage ()
	{
		$q [] = 'SELECT texts.*';
		array_push($q, ' FROM [e10pro_kb_texts] AS texts');
		array_push($q, ' WHERE texts.ndx = %i', $this->pageNdx);
		array_push($q, ' AND texts.docStateMain < %i', 4);

		$r = $this->db()->query ($q)->fetch();

		if (!$r)
		{
			$this->doError(404, 'p');
			return;
		}

		$pageSectionNdx = $this->pageSectionNdx($r);
		if (!$pageSectionNdx)
		{
			$this->doError(404, 'p');
			return;
		}

		$pageSectionRecData = $this->db()->query('SELECT * FROM [e10pro_kb_sections] WHERE [ndx] = %i', $pageSectionNdx)->fetch();
		if (!$pageSectionRecData || $pageSectionRecData['wiki'] !== $this->wikiNdx)
		{
			$this->doError(404, 'p');
			return;
		}

		$dstLanguage = $r['srcLanguage'];

		$this->page['sectionNdx'] = $pageSectionNdx;

		// -- load section content
		$this->loadSectionsContents($this->page['sectionNdx']);
		$this->template->data['sectionContent'] = array_values($this->sections[$this->page['sectionNdx']]['content']);


		$writerUser = 0;
		$section = $this->sections[$pageSectionNdx];
		if (in_array('w', $section['roles']) || in_array('a', $section['roles']))
			$writerUser = 1;

		$this->page['title'] = $r['title'];
		$this->page['subTitle'] = $r['subTitle'];
		$this->page['icon'] = $r['icon'];
		$this->page['text'] = $r['text'];

		$renderedText = $this->db()->query('SELECT * FROM [e10pro_kb_textsRendered] WHERE [wikiPage] = %i', $r['ndx'],
			' AND [dstLanguage] = %i', $dstLanguage)->fetch();

		if ($writerUser)
		{
			if ($r['docState'] === 4000)
			{
				$this->page['title'] = $renderedText['title'];
				$this->page['subTitle'] = $renderedText['subTitle'];
				$this->page['text'] = $renderedText['text'];
			}
			else
			{
				$tableTexts = $this->app()->table('e10pro.kb.texts');
				$spe = $tableTexts->systemPageEngine($r);
				$spe->init();
				$this->page['text'] = $spe->renderPage($r);
			}
		}
		else
		{
			if ($r['docState'] === 4000 && $renderedText)
			{
				$this->page['title'] = $renderedText['title'];
				$this->page['subTitle'] = $renderedText['subTitle'];
				$this->page['text'] = $renderedText['text'];
			}
			elseif ($r['docState'] === 8000 && $renderedText)
			{
				$this->page['title'] = $renderedText['title'];
				$this->page['subTitle'] = $renderedText['subTitle'];
				$this->page['text'] = $renderedText['text'];
			}
			elseif ($r['docState'] === 1000)
			{
				$this->page['title'] = $renderedText['title'];
				$this->page['subTitle'] = $renderedText['subTitle'];
				$this->page['text'] = 'Stránka se připravuje';
			}
			else
			{
				$this->page['title'] = $renderedText['title'];
				$this->page['subTitle'] = $renderedText['subTitle'];
				$this->page['text'] = 'Něco se pokazilo';
			}
		}

		$systemPageTypeId = (isset($r['pageType']) && $r['pageType'] !== '') ? $r['pageType'] : 'kb-user-page';
		$this->systemPageTypeCfg = $this->app()->cfgItem('e10pro.kb.wiki.pageTypes.'.$systemPageTypeId, NULL);


		$this->ownerPageNdx = $r['ownerText'];

		$this->page['ndx'] = $r['ndx'];
		$this->page['tableId'] = 'e10pro.kb.texts';
		$this->page['pageType'] = 'page';
		$this->page['id'] = $this->pageId;
		$this->page['sectionTitle'] = $pageSectionRecData['title'];
		$this->page['sectionPageFooter'] = '';

		if ($pageSectionRecData['pageFooter'] !== '')
		{
			$this->textRenderer->render ($pageSectionRecData['pageFooter']);
			$this->page['sectionPageFooter'] = $this->textRenderer->code;
		}

		$this->page['sectionPageFooter'] .= $this->sectionInfo ($pageSectionNdx, $pageSectionRecData['title']);

		$this->page['docState'] = $r['docState'];
		$this->page['docStateClass'] = 'e10-docstyle-'.$this->textDocStates[$r['docState']]['stateStyle'];
		$this->page['pageType'] .= ' e10-wiki-state-'.$this->textDocStates[$r['docState']]['stateStyle'];
		$this->page['docStateInfo'] = 0;

		if ($r['docState'] !== 4000)
		{
			$this->page['docStateInfo'] = 1;

			if ($r['docState'] === 1000)
				$this->page['docStateInfoText'] = 'Stránka se připravuje; díváte se na předběžnou verzi.';
			elseif ($r['docState'] === 8000)
				$this->page['docStateInfoText'] = 'Stránka se upravuje; díváte se na rozpracovanou verzi.';

			$this->page['pageType'] .= ' edited';
		}

		$this->addPageBreadcrumb ($r['ndx']);
		$this->page['breadcrumbs'] = array_reverse($this->breadcrumbs);

		// -- load page content
		$this->page['content'] = $this->loadPageContent($this->pageNdx, TRUE);
		$this->loadPagePrevNext($r, $pageSectionNdx, 'next');
		$this->loadPagePrevNext($r, $pageSectionNdx, 'prev');

		// -- tags
		$tags = \E10\Base\loadClassification($this->app, 'e10pro.kb.texts', $r['ndx'], 'label label-info pull-right');
		if (isset($tags[$this->pageNdx]) && isset($tags[$this->pageNdx]['kbTextsTags']))
			$this->page['tags'] = $tags[$this->pageNdx]['kbTextsTags'];

		// -- page buttons
		$buttons = [];
		$this->createPageButtons($this->pageNdx, $buttons);
		if (count($buttons))
			$this->page['buttons'] = $buttons;

		$this->textRenderer->setOwner($this->page);
		$this->textRenderer->render($this->page['text']);

		$this->template->data ['page'] = $this->page;
		$this->template->data ['page']['htmlPageText'] = $this->template->renderPagePart('text', $this->textRenderer->code);

		$this->sections[$pageSectionNdx]['active'] = 1;
	}

	function pageSectionNdx($pageRecData)
	{
		if (!$pageRecData['ownerText'] && $pageRecData['section'])
			return $pageRecData['section'];

		$ownerRecData = $this->db()->query('SELECT ndx, [section], [ownerText] FROM [e10pro_kb_texts] WHERE ndx = %i', $pageRecData['ownerText'])->fetch();
		if (!$ownerRecData)
			return 0;

		return $this->pageSectionNdx($ownerRecData);
	}

	function loadPageContent ($pageNdx, $setLastOrder = FALSE)
	{
		if ($this->systemPageTypeCfg['pageContent'] === 0)
			return '';

		$content = [];
		$q = [];
		$q [] = 'SELECT texts.*';
		array_push ($q, ' FROM [e10pro_kb_texts] AS texts');
		array_push ($q, ' WHERE texts.ownerText = %i', $pageNdx);
		array_push ($q, ' AND texts.docStateMain < %i', 4);
		array_push ($q, ' ORDER BY [order], [title]');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r['ndx'], 'title' => $r['title'], 'id' => strval($r['ndx']), 'active' => 0,
				'url' => $this->urlBegin.$r['ndx'],
			];

			$item['docStateAlert'] = 0;
			$item['docState'] = $r['docState'];
			$item['docStateClass'] = 'e10-docstyle-'.$this->textDocStates[$r['docState']]['stateStyle'];
			if ($r['docState'] !== 4000)
				$item['docStateAlert'] = 1;

			if ($r['ndx'] === $this->pageNdx)
			{
				$item['active'] = 1;
			}
			$content[] = $item;
			$lastOrder = $r['order'];
			if ($setLastOrder)
				$this->lastOrder = $lastOrder;
		}

		return $content;
	}

	function loadPagePrevNext($pageRec, $pageSectionNdx, $part)
	{
		// -- prev
		$q = [];
		$q [] = 'SELECT texts.ndx, texts.title';
		array_push ($q, ' FROM [e10pro_kb_texts] AS texts');
		array_push ($q, ' WHERE 1');

		if ($pageRec['ownerText'] != 0)
			array_push ($q, ' AND texts.ownerText = %i', $pageRec['ownerText']);
		else
			array_push ($q, ' AND texts.section = %i', $pageSectionNdx, ' AND texts.ownerText = %i', 0);

		array_push ($q, ' AND texts.docStateMain < %i', 4);

		if ($part === 'prev')
		{
			array_push($q, ' AND (texts.[order] < %i', $pageRec['order'],
				' OR (texts.[order] = %i', $pageRec['order'],
				' AND [texts].[title] < %s', $pageRec['title'], '))');

			array_push($q, ' ORDER BY [order] DESC, [title] DESC');
		}
		else
		{ // next
			array_push($q, ' AND (texts.[order] > %i', $pageRec['order'],
				' OR (texts.[order] = %i', $pageRec['order'],
				' AND [texts].[title] > %s', $pageRec['title'], '))');

			array_push($q, ' ORDER BY [order] ASC, [title] ASC');
		}
		array_push ($q, ' LIMIT 1');

		$pgr = $this->db()->query($q)->fetch();
		if ($pgr)
		{
			$this->page['prevNextButtons'][$part] = ['title' => $pgr['title'], 'ndx' => $pgr['ndx'], 'id' => $pgr['ndx']];
		}
	}

	function addPageBreadcrumb ($pageNdx)
	{
		if ($pageNdx)
		{
			$q [] = 'SELECT texts.*, sections.title AS sectionTitle';
			array_push($q, ' FROM [e10pro_kb_texts] AS texts');
			array_push($q, ' LEFT JOIN e10pro_kb_sections AS sections ON texts.section = sections.ndx ');
			array_push($q, ' WHERE texts.ndx = %i', $pageNdx);

			$r = $this->db()->query($q)->fetch();

			$item = ['ndx' => $r['ndx'], 'title' => $r['title'], 'id' => strval($r['ndx']), 'url' => $this->urlBegin.$r['ndx'],];
			if ($pageNdx === $this->pageNdx)
				$item['active'] = 1;

			$this->breadcrumbs[] = $item;

			if ($pageNdx === $this->ownerPageNdx)
			{
				$this->page['ownerContent'] = $this->loadPageContent ($this->ownerPageNdx);
				$this->page['ownerPageTitle'] = $r['title'];
				$this->page['ownerPageId'] = strval($r['ndx']);
			}
		}
		else
		{
			$this->breadcrumbs[] = ['title' => $this->page['sectionTitle'], 'id' => 's'.$this->page['sectionNdx'], 'url' => $this->urlBegin.'s'.$this->page['sectionNdx']];
			$this->breadcrumbs[] = ['title' => '', 'id' => '', 'url' => $this->urlBegin, 'icon' => 'icon-home'];
			return;
		}

		if ($r['ownerText'])
			$this->addPageBreadcrumb($r['ownerText']);
		else
		{
			$this->breadcrumbs[] = ['title' => $r['sectionTitle'], 'id' => 's'.$r['section'], 'url' => $this->urlBegin.'s'.$r['section']];
			$this->breadcrumbs[] = ['title' => '', 'id' => '', 'icon' => 'icon-home', 'url' => $this->urlBegin];
		}
	}

	function loadSections ()
	{
		$q [] = 'SELECT sections.* FROM [e10pro_kb_sections] AS [sections]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [wiki] = %i', $this->wikiNdx);
		array_push ($q, ' AND docStateMain < %i', 4);

		array_push ($q, ' AND (');
		array_push ($q, ' EXISTS (',
				'SELECT docLinks.dstRecId FROM [e10_base_doclinks] as docLinks',
				' WHERE [sections].ndx = srcRecId AND srcTableId = %s', 'e10pro.kb.sections',
				' AND dstTableId = %s', 'e10.persons.persons',
				' AND docLinks.dstRecId = %i', $this->thisUserId);
		array_push ($q, ')');

		$ug = $this->app()->userGroups ();
		if (count ($ug) !== 0)
		{
			array_push ($q, ' OR ');
			array_push ($q, ' EXISTS (',
					'SELECT docLinks.dstRecId FROM [e10_base_doclinks] as docLinks',
					' WHERE [sections].ndx = srcRecId AND srcTableId = %s', 'e10pro.kb.sections',
					' AND dstTableId = %s', 'e10.persons.groups',
					' AND docLinks.dstRecId IN %in', $ug);
			array_push ($q, ')');
		}

		array_push ($q, 'OR ([sections].[publicRead] = 1)');

		array_push ($q, ')');

		array_push ($q, ' ORDER BY [order], [title]');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r['ndx'], 'id' => 's'.$r['ndx'], 'title' => $r['title'], 'active' => 0,
				'url' => $this->urlBegin.'s'.$r['ndx'],
				'topMenu' => 0, 'topMenuText' => 0,
				'homeTile' => 0, 'homeTileContent' => 0,
				'bookEnable' => $r['bookEnable'],
				'content' => [], 'roles' => []
			];
			if ($r['icon'] !== '')
				$item['icon'] = $r['icon'];

			if ($this->pageId === 's'.$r['ndx'])
			{
				$item['active'] = 1;
			}

			if ($r['topMenuStyle'] !== 2)
				$item['topMenu'] = 1;
			if ($r['topMenuStyle'] === 0)
				$item['topMenuText'] = 1;

			if ($r['homeTileStyle'] !== 2)
				$item['homeTile'] = 1;
			if ($r['homeTileStyle'] === 0)
				$item['homeTileContent'] = 1;

			if ($r['perex'] !== '')
			{
				$this->textRenderer->render ($r['perex']);
				$item['perex'] = $this->textRenderer->code;
			}
			if ($r['pageFooter'] && $r['pageFooter'] !== '')
			{
				$this->textRenderer->render ($r['pageFooter']);
				$item['sectionPageFooter'] = $this->textRenderer->code;
			}

			$this->sections[$r['ndx']] = $item;
			$this->sectionsPks[] = $r['ndx'];

			if ($r['publicRead'])
				$this->sections[$r['ndx']]['roles'][] = 'r';
		}

		// -- linked persons
		if (count($this->sectionsPks))
		{
			$qlp = [];
			array_push ($qlp, 'SELECT links.* FROM e10_base_doclinks AS links ');
			array_push ($qlp, ' WHERE srcTableId = %s', 'e10pro.kb.sections');
			array_push ($qlp, ' AND srcRecId IN %in', $this->sectionsPks);

			$rows = $this->db()->query ($qlp);
			foreach ($rows as $r)
			{
				if ($r['dstTableId'] === 'e10.persons.groups' && !in_array($r['dstRecId'], $ug))
					continue;
				if ($r['dstTableId'] === 'e10.persons.persons' && $r['dstRecId'] !== $this->thisUserId)
					continue;

				$sectionNdx = $r['srcRecId'];

				switch ($r['linkId'])
				{
					case 'e10pro-kb-sections-admins': $this->sections[$sectionNdx]['roles'][] = 'a'; break;
					case 'e10pro-kb-sections-authors': $this->sections[$sectionNdx]['roles'][] = 'w'; break;
					case 'e10pro-kb-sections-readers': $this->sections[$sectionNdx]['roles'][] = 'r'; break;
				}
			}
		}
	}

	function loadSection ()
	{
		if (!isset($this->sections[$this->sectionNdx]))
		{
			$this->doError(404, 's');
			return;
		}

		$this->loadSectionsContents($this->sectionNdx);

		$r = $this->sections[$this->sectionNdx];
		$this->page['pageType'] = 'section';
		$this->page['title'] = $r['title'];
		$this->page['content'] = array_values($r['content']);
		$this->page['sectionPageFooter'] = $r['sectionPageFooter'].$this->sectionInfo ($this->sectionNdx, $r['title']);

		$this->breadcrumbs[] = ['title' => $r['title'], 'id' => 's'.$this->sectionNdx, 'active' => 1];
		$this->breadcrumbs[] = ['title' => '', 'id' => '', 'url' => $this->urlBegin, 'icon' => 'icon-home', 'active' => 0];

		$this->page['breadcrumbs'] = array_reverse($this->breadcrumbs);

		if (isset($r['buttons']))
			$this->page['buttons'] = $r['buttons'];

		$this->template->data ['page'] = $this->page;
	}

	function loadSectionsContents ($sectionNdx = 0)
	{
		if (!$sectionNdx && !count($this->sectionsPks))
			return;

		$q [] = 'SELECT texts.*';
		array_push ($q, ' FROM [e10pro_kb_texts] AS texts');
		array_push ($q, ' WHERE 1');

		if ($sectionNdx)
			array_push ($q, ' AND [section] = %i', $sectionNdx);
		else
			array_push ($q, ' AND [section] IN %in', $this->sectionsPks);

		array_push ($q, ' AND [ownerText] = %i', 0);
		array_push ($q, ' AND texts.[docStateMain] < 4');
		array_push ($q, ' ORDER BY [order], [title], [ndx]');

		$firstLevelPks = [];
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = [
				'title' => $r['title'], 'ndx' => $r['ndx'], 'id' => strval($r['ndx']), 'active' => 0,
				'url' => $this->urlBegin.$r['ndx'],
			];

			$item['docStateAlert'] = 0;
			$item['docState'] = $r['docState'];
			$item['docStateClass'] = 'e10-docstyle-'.$this->textDocStates[$r['docState']]['stateStyle'];
			if ($r['docState'] !== 4000)
				$item['docStateAlert'] = 1;
			if ($r['ndx'] === $this->pageNdx)
				$item['active'] = 1;

			$this->sections[$r['section']]['content'][$r['ndx']] = $item;
			$this->sections[$r['section']]['content2'][] = $item;
			$this->sections[$r['section']]['lastOrder'] = $r['order'];

			$firstLevelPks[] = $r['ndx'];
		}

		//-- sections buttons
		foreach ($this->sections as $sectionNdx => $section)
		{
			$buttons = [];
			$this->createSectionButtons($sectionNdx, $buttons);
			if (count($buttons))
				$this->sections[$sectionNdx]['buttons'] = $buttons;
		}

		// -- second level
		$q = [];
		array_push ($q, 'SELECT texts.*, ownerTexts.section AS ownerSection');
		array_push ($q, ' FROM [e10pro_kb_texts] AS texts');
		array_push($q, ' LEFT JOIN [e10pro_kb_texts] AS ownerTexts ON [texts].ownerText = ownerTexts.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [texts].[ownerText] IN %in', $firstLevelPks);
		array_push ($q, ' AND [texts].[docStateMain] < 4');
		array_push ($q, ' ORDER BY [texts].[order], [texts].[title], [texts].[ndx]');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['title' => $r['title'], 'ndx' => $r['ndx'], 'id' => strval($r['ndx']), 'active' => 0];

			$item['docStateAlert'] = 0;
			$item['docState'] = $r['docState'];
			$item['docStateClass'] = 'e10-docstyle-'.$this->textDocStates[$r['docState']]['stateStyle'];
			if ($r['docState'] !== 4000)
				$item['docStateAlert'] = 1;
			if ($r['ndx'] === $this->pageNdx)
				$item['active'] = 1;

			if (!isset($this->sections[$r['ownerSection']]))
				error_log ("---ws-!{$r['ownerSection']}!--".$r['title']);

			$this->sections[$r['ownerSection']]['content'][$r['ownerText']]['items'][] = $item;
		}
	}

	public function loadSearch ()
	{
		$this->page['pageType'] = 'search';
		$this->page['title'] = 'Hledání';
		$this->page['text'] = '';
		$this->page['queryText'] = $this->app()->testGetParam('q');

		if ($this->page['queryText'] !== '')
			$this->doFulltextSearch ();

		$this->template->data ['page'] = $this->page;
		$this->textRenderer->render ($this->page['text']);
		$this->template->data ['page']['htmlPageText'] = $this->template->renderPagePart('text', $this->textRenderer->code);
	}

	public function load ()
	{
		$this->loadSections();

		if ($this->mode === self::modeSection)
		{
			$this->loadSection();
		}
		elseif ($this->mode === self::modePage)
		{
			$this->loadPage();
		}
		elseif ($this->mode === self::modeSearch)
		{
			$this->loadSearch();
		}
		else
		{
			$this->loadSectionsContents();

			$buttons = [];
			$this->createWikiButtons($buttons);
			if (count($buttons))
				$this->page['buttons'] = $buttons;

			$this->template->data ['page'] = $this->page;
		}
	}

	function createPageButtons ($pageNdx, &$target)
	{
		if (!$this->page['sectionNdx'])
			return;

		$section = $this->sections[$this->page['sectionNdx']];

		if (in_array('w', $section['roles']) || in_array('a', $section['roles']))
		{
			$b = [
				'text' => 'Upravit', 'title' => 'Upravit stránku', 'icon' => 'icon-edit',
				'actionClass' => 'e10-document-trigger',
				'attr' => [
					['k' => 'table', 'v' => 'e10pro.kb.texts'],
					['k' => 'pk', 'v' => $pageNdx],
					['k' => 'action', 'v' => 'edit']
				]
			];
			$target[] = $b;

			$b = [
				'text' => 'Přidat', 'title' => 'Přidat podstránku', 'icon' => 'icon-plus',
				'actionClass' => 'e10-document-trigger',
				'attr' => [
					['k' => 'table', 'v' => 'e10pro.kb.texts'],
					['k' => 'action', 'v' => 'new'],
					['k' => 'addParams', 'v' => '__thisType=1&__mainType=1&__ownerText=' . $this->pageNdx . '&__section=' . $this->page['sectionNdx'] . '&__order=' . ($this->lastOrder + 1000)]
				]
			];
			$target[] = $b;

			$b = [
				'text' => 'Přesunout', 'title' => 'Přesunout stránku', 'icon' => 'icon-arrow-circle-right',
				'actionClass' => 'df2-action-trigger',
				'attr' => [
					['k' => 'table', 'v' => 'e10pro.kb.texts'],
					['k' => 'pk', 'v' => $pageNdx],
					['k' => 'type', 'v' => 'action'],
					['k' => 'action', 'v' => 'addwizard'],
					['k' => 'class', 'v' => 'e10pro.kb.libs.PageMoveWizard']
				]
			];
			$target[] = $b;
		}
	}

	function createSectionButtons ($sectionNdx, &$target)
	{
		$section = $this->sections[$sectionNdx];

		if (!isset($section['roles']))
		{

			return;
		}

		if ($this->app()->hasRole('kb') || in_array('a', $section['roles']))
		{
			$b = ['text' => 'Upravit sekci', 'icon' => 'icon-edit',
				'attr' => [
					['k' => 'table', 'v' => 'e10pro.kb.sections'],
					['k' => 'pk', 'v' => $sectionNdx],
					['k' => 'action', 'v' => 'edit']
				]
			];
			$target[] = $b;
		}

		if (in_array('w', $section['roles']))
		{
			$lso = 1000;
			if (isset($this->sections[$sectionNdx]['lastOrder']))
				$lso += $this->sections[$sectionNdx]['lastOrder'];
			$b = ['text' => 'Přidat podstránku', 'icon' => 'icon-plus', 'attr' => [
					['k' => 'table', 'v' => 'e10pro.kb.texts'],
					['k' => 'action', 'v' => 'new'],
					['k' => 'addParams', 'v' => '__thisType=1&__mainType=1&__ownerText=0' . '&__section=' . $sectionNdx . '&__order=' . $lso]
			]];
			$target[] = $b;
		}

		if (($section['bookEnable']) && in_array('a', $section['roles']))
		{
			$b = ['text' => 'Vygenerovat knihu', 'icon' => 'icon-book', 'actionClass' => 'df2-action-trigger',
				'attr' => [
					['k' => 'pk', 'v' => 's-'.$sectionNdx],
					['k' => 'action', 'v' => 'addwizard'],
					['k' => 'class', 'v' => 'e10pro.kb.WikiBookWizard'],
				]
			];
			$target[] = $b;
		}

		if ($this->app()->hasRole('kb') && in_array('a', $section['roles']))
		{
			$b = ['text' => 'Spravovat stránky', 'icon' => 'icon-pencil-square', 'actionClass' => 'df2-action-trigger',
				'attr' => [
					['k' => 'popup-url', 'v' => $this->app()->dsRoot.'/app/!/widget/viewer/e10pro.kb.texts/default'],
					['k' => 'action', 'v' => 'open-popup'],
					['k' => 'popup-id', 'v' => 'wiki-pages']
				]
			];
			$target[] = $b;
		}
	}

	function createWikiButtons (&$target)
	{
		$userNdx = $this->app()->userNdx();
		$userGroups = $this->app()->userGroups();

		$enabled = 0;
		if (isset($this->wiki['admins']) && in_array($userNdx, $this->wiki['admins']))
			$enabled = 1;
		elseif (isset($this->wiki['adminsGroups']) && count($userGroups) && count(array_intersect($userGroups, $this->wiki['adminsGroups'])) !== 0)
			$enabled = 1;

		if ($enabled)
		{
			$b = ['text' => 'Přidat sekci', 'title' => 'Přidat sekci', 'icon' => 'icon-plus', 'attr' => [
				['k' => 'table', 'v' => 'e10pro.kb.sections'],
				['k' => 'action', 'v' => 'new'],
				['k' => 'addParams', 'v' => '__wiki=' . $this->wikiNdx]
			]];
			$target[] = $b;
		}
	}

	public function setPageId ($id, $template = NULL)
	{
		$this->page['pageType'] = 'home';

		$this->thisUserId = $this->app()->userNdx();
		$this->pageId = $id;

		$this->textRenderer = new \lib\core\texts\Renderer($this->app());
		if ($this->widgetMode)
			$this->textRenderer->wikiWidgetMode = TRUE;
		$this->textRenderer->setLinkRoot($this->urlBegin);
		if (!$template)
		{
			$this->template = new \e10\web\webTemplateMustache ($this->app());
			$this->template->webEngine = \e10\web\webPages::$engine;
		}
		else
		{
			$this->template = $template;
		}

		$this->template->data['pageId'] = $this->pageId;

		if ($this->widget)
		{
			$this->template->data['srcObjectType'] = 'widget';
			$this->template->data['srcObjectId'] = $this->widget->widgetId;
		}

		if ($id === '')
		{
		}
		elseif ($id[0] === 'q')
		{
			$this->mode = self::modeSearch;
			$this->subTemplateId = 'e10pro.kb.wikiSearch';
		}
		elseif ($id[0] === 's')
		{
			$this->sectionNdx = intval(substr($id, 1));;
			$this->mode = self::modeSection;
			$this->subTemplateId = 'e10pro.kb.wikiSection';
		}
		elseif (is_numeric($id) && intval($id) == $id)
		{ // page
			$this->pageNdx = intval($id);
			$this->mode = self::modePage;
			$this->subTemplateId = 'e10pro.kb.wikiPage';
		}
		elseif ($id !== '/' && $id !== '')
		{
			$this->doError(404, 'X');
		}
	}

	function doError ($statusCode, $pageType)
	{
		$this->status = $statusCode;

		$this->page['pageType'] = 'error';
		$this->page['title'] = 'Stránka neexistuje';
		$this->page['text'] = 'stránka, kterou hledáte, nebyla nalezena...';
		$this->page['id'] = $this->pageId;
		$this->breadcrumbs[] = ['title' => '', 'id' => $this->app()->urlRoot.'/', 'url' => $this->urlBegin, 'icon' => 'icon-home'];

		$this->page['breadcrumbs'] = array_reverse($this->breadcrumbs);

		$this->template->data ['page'] = $this->page;

		$this->textRenderer->render ($this->page['text']);
		$this->template->data ['page']['htmlPageText'] = $this->template->renderPagePart('text', $this->textRenderer->code);

		$this->subTemplateId = 'e10pro.kb.wikiError';
	}

	function doFulltextSearch ()
	{
		$q [] = 'SELECT texts.*, sections.title AS sectionTitle';
		array_push($q, ' FROM [e10pro_kb_texts] AS texts');
		array_push($q, ' LEFT JOIN e10pro_kb_sections AS sections ON texts.section = sections.ndx ');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND texts.[section] IN %in', $this->sectionsPks);
		array_push($q, ' AND texts.docStateMain < %i', 4);


		array_push($q, ' AND (');
		array_push($q, ' [texts].title LIKE %s', '%'.$this->page['queryText'].'%');
		array_push($q, ' OR [texts].[text] LIKE %s', '%'.$this->page['queryText'].'%');
		array_push($q, ')');

		array_push($q, ' LIMIT 30');

		$rows = $this->db()->query ($q);
		$pages = [];
		foreach ($rows as $r)
		{
			$txt = preg_replace('#\{\{.*\}\}#m', '', $r['text']);
			$this->textRenderer->render (strip_tags($txt));
			$item = ['id' => strval ($r['ndx']), 'title' => $r['title'], 'content' => $this->excerpt(strip_tags($this->textRenderer->code), $this->page['queryText'])];
			$pages[$r['ndx']] = $item;
		}

		$this->page['foundedPages'] = array_values($pages);
	}

	function excerpt ($text, $query)
	{
		$words = join('|', explode(' ', preg_quote($query)));

		$s = '\s\x00-/:-@\[-`{-~';
		preg_match_all('#(?<=['.$s.']).{1,60}(('.$words.').{1,60})+(?=['.$s.'])#uis', $text, $matches, PREG_SET_ORDER);

		$results = array();
		foreach($matches as $line)
		{
			$results[] = $line[0];
		}
		$result = join(' <b>(...)</b> ', $results);

		$result = preg_replace('#'.$words.'#iu', "<span class=\"e10-wiki-search-highlight\">\$0</span>", $result);

		return $result;
	}

	protected function sectionInfo ($sectionNdx, $sectionTitle)
	{
		if (!$this->app()->userNdx())
			return '';

		$info = [];

		$lp = new LinkedPersons($this->app());
		$lp->setSource('e10pro.kb.sections', $sectionNdx);
		$lp->setFlags(LinkedPersons::lpfNicknames|LinkedPersons::lpfExpandGroups);
		$lp->load();

		if (!count($lp->lp))
			return;

		$lp = $lp->lp[$sectionNdx];

//		$info[] = ['text' => $thisSection['fn'], 'class' => 'e10-bold block'];

		if (isset($lp['e10pro-kb-sections-admins']))
		{
			$info[] = ['text' => 'Správci'.': ', 'class' => 'break e10-bold'];
			foreach ($lp['e10pro-kb-sections-admins']['labels'] as $l)
			{
				$info[] = $l;
			}
		}
		if (isset($lp['e10pro-kb-sections-authors']))
		{
			$info[] = ['text' => 'Autoři'.': ', 'class' => 'break e10-bold'];
			foreach ($lp['e10pro-kb-sections-authors']['labels'] as $l)
			{
				$info[] = $l;
			}
		}
		if (isset($lp['e10pro-kb-sections-readers']))
		{
			$info[] = ['text' => 'Čtenáři'.': ', 'class' => 'break e10-bold'];
			foreach ($lp['e10pro-kb-sections-readers']['labels'] as $l)
			{
				$info[] = $l;
			}
		}

		$c = '';

		$c .= "<div class='e10-small' style='font-size:75%;'>";
		$c .= '<hr/>';
		$c .= "<div>".utils::es('Sekce')."<span class='e10-bold'> ".utils::es($sectionTitle).'</span></div>';
		$c .=$this->app()->ui()->composeTextLine($info);
		$c .= '</div>';

		return $c;
	}

	public function setWidgetMode ($widget)
	{
		$this->widget = $widget;
		$this->widgetMode = TRUE;
	}

	public function run()
	{
		$this->textDocStates = $this->app()->cfgItem ('e10pro.kb.texts.docStates');
		$this->wiki = $this->app->cfgItem ('e10pro.kb.wikies.'.$this->wikiNdx, FALSE);

		//if ($this->widgetMode)
		//	$this->subTemplateId .= 'Widget';

		if ($this->status === 200)
		{
			$this->load();
			$this->template->data['sections'] = array_values($this->sections);
			$this->template->data['urlBegin'] = $this->urlBegin;
		}

		$this->page ['code'] = $this->template->renderSubTemplate ($this->subTemplateId);
	}
}
