<?php

namespace e10pro\kb;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10/web/web.php';

use E10\Utility, \E10\utils;


/**
 * wiki - generování wikitextů pro web
 *
 * @param $app
 * @param $params
 * @return array
 */
function wiki ($app, $params)
{
	$pageId = $app->requestPath (0);
	$wikiEngine = new WikiEngine ($app);

	if (isset($params['serverInfo']))
		$wikiEngine->wikiNdx = intval($params['serverInfo']['wiki']);

	$wikiEngine->setPageId($pageId, $params['owner']);
	$wikiEngine->run();

	$page = [
			'pageType' => $wikiEngine->page['pageType'],
			'title' => isset($wikiEngine->page['title']) ? $wikiEngine->page['title'] : '!!!',
			'text' => $wikiEngine->page['code'],
			'status' => $wikiEngine->status
	];

	return $page;
}


/**
 * Class kbTextsEngine
 * @package E10Pro\KB
 */
class kbTextsEngine extends \E10\utility
{
	var $tableTexts;
	var $rootText;
	var $page = [];
	var $books = [];

	public $bookNdx = 0;
	public $pageNdx = 0;

	var $oneBigText = '';
	var $oneBigTextTOCHtml = '';

	var $bookContent = [];
	var $pageContent = [];

	CONST pmBigText = 1, pmChunks = 2;
	//var $pageMode = self::pmBigText;
	var $pageMode = self::pmChunks;

	var $template;

	var $firstHLevel = 3;
	var $externalLinks = FALSE;

	public function __construct ($app, $pageMode)
	{
		parent::__construct ($app);
		$this->pageMode = $pageMode;
	}

	function init ()
	{
		$this->tableTexts = $this->app->table ('e10pro.kb.texts');
		$this->resetPage();
	}

	public function loadBooks ()
	{
		$q [] = 'SELECT texts.*, persons.fullName as authorFullName FROM [e10pro_kb_texts] AS texts '.
				' LEFT JOIN e10_persons_persons AS persons ON texts.author = persons.ndx ' .
				' WHERE 1';

		array_push ($q, ' AND [treeLevel] = 0 && [mainType] != %i', 0);
		array_push ($q, ' AND texts.[docStateMain] < 4');
		array_push ($q, ' ORDER BY [treeId]');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$b = ['title' => $r['title'], 'ndx' => $r['ndx'],
					'url' => $this->app->urlRoot.'/kb/'.$r['ndx']];
			if ($this->bookNdx === $r['ndx'])
				$b['active'] = 1;
			$this->books[] = $b;
		}

		$this->page['books'] = $this->books;
	}

	public function createOneBigText ()
	{
		$this->oneBigText = '';
		$texy = new \E10\Web\E10Texy($this->app, $this->page);
		$texy->headingModule->generateID = TRUE;

		$contentHtml = '';

		if (0 && $this->rootText['text'] !== '')
		{
			$a = $this->rootText;

			$newTocItem = array ('title' => $a['title'], 'id' => $a['treeId'], 'content' => array ());
			$contentHtml .= "<li><a href='#{$a['treeId']}'>".utils::es($a['title']).'</a>';
			$subContent = '';
			foreach ($texy->headingModule->TOC as $toc)
			{
				if (isset ($toc['el']->attrs['id']))
				{
					$newTocItem['content'][] = array ('title' => $toc['el']->getText(), 'id' => $toc['el']->attrs['id']);
					$subContent .= "<li><a href='#{$toc['el']->attrs['id']}'>".utils::es($toc['el']->getText()).'</a></li>';
				}
			}
			if ($subContent !== '')
				$contentHtml .= "<ul class='nav' style='padding-left: 2em;'>".$subContent.'</ul>';
			$contentHtml .= '</li>';
		}

		$q [] = 'SELECT * FROM [e10pro_kb_texts] WHERE 1 ';
		if ($this->rootText['thisType'] === 99)
			array_push ($q, "AND [mainOwnerText] = %i", $this->rootText['mainOwnerText']);
		else
			array_push ($q, "AND [mainOwnerText] = %i", $this->rootText['ndx']);
		array_push ($q, ' AND docState != 9800');
		array_push ($q, "ORDER BY [treeId], [title]");

		$lastLevel = 0;
		$rows = $this->db()->query ($q);
		forEach ($rows as $a)
		{
			if ($a['ndx'] !== $a['mainOwnerText'])
			{
				if ($a['treeLevel'] > $lastLevel)
					$contentHtml .= "\n<ul class='nav' style='padding-left: 1em;'>";
				else
				if ($a['treeLevel'] < $lastLevel)
					$contentHtml .= "</li>\n</ul>\n";
				else
				if ($a['treeLevel'] == $lastLevel)
					$contentHtml .= "</li>";
			}

			$texy->headingModule->idPrefix = 'toc-'.$a['ndx'].'-';
			$texy->headingModule->top = $a['treeLevel']+2;
			$texy->setOwner ($a, 'e10pro.kb.texts');
			$txt = $texy->process ($a ['text']);

			if ($a['ndx'] !== $a['mainOwnerText'])
			{
				$contentHtml .= "\n<li><a href='#{$a['treeId']}'>".utils::es($a['title'])."</a>";
				$subContent = '';
				foreach ($texy->headingModule->TOC as $toc)
				{
					if (isset ($toc['el']->attrs['id']))
					{
						$subContent .= "<li><a href='#{$toc['el']->attrs['id']}'>".utils::es($toc['el']->getText()).'</a></li>';
					}
				}
				if ($subContent !== '')
					$contentHtml .= "<ul class='nav' style='padding-left: 1em;'>".$subContent.'</ul>';

				$lastLevel = $a['treeLevel'];
			}

			$this->oneBigText .= "<h2 id='{$a['treeId']}'>".utils::es($a['title']).'</h2>';
			$this->oneBigText .= $txt;
		}
		$contentHtml .= str_repeat ("</li>\n</ul>\n", $lastLevel);

		$this->oneBigTextTOCHtml = $contentHtml;
		$this->page ['kbText'] = $contentHtml;
	}

	public function createBookContent ()
	{
		$q [] = 'SELECT ndx, title, ownerText, treeId, treeLevel FROM [e10pro_kb_texts]';
		array_push ($q, ' WHERE [mainOwnerText] = %i', $this->bookNdx, ' AND [mainOwnerText] != [ndx]', ' AND docState != 9800');
		array_push ($q, ' ORDER BY [treeId], [title]');

		$rows = $this->app->db()->query ($q);

		$prevPageNdx = 0;
		foreach ($rows as $r)
		{
			$this->bookContent[$r['ndx']] = ['ndx' => $r['ndx'], 'title' => $r['title'], 'treeLevel' => $r['treeLevel'], 'treeId' => $r['treeId'],
																			 'ownerText' => $r['ownerText'],
																			 'url' => $this->app->urlRoot.'/kb/'.$this->bookNdx.'.'.$r['ndx']];
			if ($r['ndx'] === $this->pageNdx)
			{
				$this->bookContent[$r['ndx']]['active'] = 1;

				if ($prevPageNdx)
				{
					$prevPage = $this->bookContent[$prevPageNdx];
					//if ($prevPage['ownerText'] === $r['ownerText'])
						$this->page['pageNavPrev'] = ['title' => $prevPage['title'], 'ndx' => $prevPage['ndx'],
																					'url' => $this->app->urlRoot.'/kb/'.$this->bookNdx.'.'.$prevPage['ndx']];
				}
			}
			else
			if ($prevPageNdx)
			{
				$prevPage = $this->bookContent[$prevPageNdx];

				$this->bookContent[$prevPageNdx]['pageNext'] = ['ndx' => $r['ndx'], 'title' => $r['title']];

				if ($prevPageNdx === $this->pageNdx)
					$this->page['pageNavNext'] = ['title' => $r['title'], 'ndx' => $r['ndx'],
																				'url' => $this->app->urlRoot.'/kb/'.$this->bookNdx.'.'.$r['ndx']];

				//if ($prevPage['ownerText'] === $r['ownerText'])
					$this->bookContent[$r['ndx']]['pagePrev'] = ['ndx' => $prevPageNdx, 'title' => $prevPage['title']];
			}

			if ($r['ownerText'] == $this->pageNdx)
			{
				$this->pageContent[$r['ndx']] = ['ndx' => $r['ndx'], 'title' => $r['title'], 'treeId' => $r['treeId'],
																				 'treeLevel' => $r['treeLevel'] - $this->rootText['treeLevel'],
																				 'url' => $this->app->urlRoot.'/kb/'.$this->bookNdx.'.'.$r['ndx']];
			}

			if ($r['ownerText'] && isset($this->bookContent[$r['ownerText']]))
			{
				$upPage = $this->bookContent[$r['ownerText']];
				$this->bookContent[$r['ndx']]['pageUp'] = ['ndx' => $r['ownerText'], 'title' => $upPage['title']];
			}
			else
				$this->bookContent[$r['ndx']]['pageUp'] = ['ndx' => $this->rootText['ndx'], 'title' => $this->rootText['title']];

			//if ($r['treeLevel'] == $this->rootText['treeLevel'])
				$prevPageNdx = $r['ndx'];
		}

		if ($this->pageNdx)
		{
			if ($this->rootText['ownerText'] != 0)
			{
				if ($this->rootText['ownerText'] === $this->bookNdx)
				{
					$upPage = $this->tableTexts->loadItem ($this->bookNdx);
					$this->page['pageNavUp'] = ['title' => $upPage['title'], 'ndx' => $upPage['ndx'],
																			'url' => $this->app->urlRoot.'/kb/'.$this->bookNdx];
				}
				else
				{
					$upPage = $this->bookContent[$this->rootText['ownerText']];
					$this->page['pageNavUp'] = ['title' => $upPage['title'], 'ndx' => $upPage['ndx'],
																			'url' => $this->app->urlRoot.'/kb/'.$this->bookNdx.'.'.$upPage['ndx']];
				}
			}

			$bcn = [];
			$bcnNdx = $this->pageNdx;
			while ($bcnNdx)
			{
				if ($bcnNdx === $this->bookNdx)
				{
					$bcnPage = $this->tableTexts->loadItem ($this->bookNdx);
					$this->page['title'] = 'Dokumentace'.' / '.$bcnPage['title'];
					$bcn[] = ['title' => $bcnPage['title'], 'url' => $this->app->urlRoot.'/kb/'.$this->bookNdx.'/', 'bookNdx' => $this->bookNdx];
				}
				else
				{
					$bcnPage = $this->bookContent[$bcnNdx];
					$bcn[] = [
							'title' => $bcnPage['title'], 'url' => $this->app->urlRoot.'/kb/'.$this->bookNdx.'.'.$bcnPage['ndx'],
							'bookNdx' => $this->bookNdx, 'bookPageNdx' => $bcnPage['ndx']
					];
				}

				$bcnNdx = $bcnPage['ownerText'];
			}
			$this->page ['textHasBreadcrumbs'] = 1;
			$this->page ['breadcrumbs'] = array_reverse($bcn);
		}

		$this->page['bookContent'] = array_values($this->bookContent);
		if (count($this->pageContent))
			$this->page['pageContent'] = array_values($this->pageContent);
	}

	public function createPageText ()
	{
		$texy = new \E10\Web\E10Texy($this->app, $this->page);
		$texy->headingModule->top = $this->firstHLevel;
		$texy->headingModule->generateID = TRUE;
		$texy->externalLinks = $this->externalLinks;
		$txt = $texy->process ($this->rootText['text']);
		if (isset($this->template))
			$this->page ['kbText'] = $this->template->renderPagePart ('content', $txt);
		else
			$this->page ['kbText'] = $txt;
	}

	public function resetPage ()
	{
		unset ($this->page);
		$this->page = ['tableId' => 'e10pro.kb.texts', 'params' => []];
	}

	public function setText ($ndx)
	{
		$this->rootText = $this->tableTexts->loadItem ($ndx);
		if (!$this->rootText)
		{
			$this->page['title'] = 'Stránka neexistuje';
			$this->page['text'] = 'Stránka neexistuje.';
			$this->page['status'] = 404;
			return FALSE;
		}

		$this->page['title'] = 'Dokumentace';
		$this->page['kbTitle'] = $this->rootText['title'];
		$this->page ['ndx'] = $ndx;
		return TRUE;
	}
}

/**
 * Class kbTextsEngineWeb
 * @package E10Pro\KB
 */
class kbTextsEngineWeb extends kbTextsEngine
{
	function init ()
	{
		parent::init();

		$ndxs = explode ('.', $this->app->requestPath (1));
		if (isset ($ndxs[0]))
			$this->bookNdx = intval ($ndxs[0]);
		if (isset ($ndxs[1]))
			$this->pageNdx = intval ($ndxs[1]);
	}

	public function run ()
	{
		$this->page ['subTemplate'] = 'e10pro.wkf.intraKb';

		$this->loadBooks();

		if ($this->pageMode === self::pmBigText)
		{
			if ($this->bookNdx)
			{
				if (!$this->setText($this->bookNdx))
					return;
				$this->createOneBigText();
			}
		}
		else
		if ($this->pageMode === self::pmChunks)
		{
			if ($this->pageNdx)
			{
				if (!$this->setText($this->pageNdx))
					return;
				$this->createBookContent();
				$this->createPageText();

				// -- editButton
				$this->page ['mainPageButtonParams'] = "data-action='edit' data-table='e10pro.kb.texts' data-pk='{$this->pageNdx}'";
				$this->page ['mainPageButtonEdit'] = 1;
			}
			else
			if ($this->bookNdx)
			{
				if (!$this->setText($this->bookNdx))
					return;
				$this->createPageText();
				$this->createBookContent();
				$this->page['contentToPage'] = 1;
				// -- editButton
				$this->page ['mainPageButtonParams'] = "data-action='edit' data-table='e10pro.kb.texts' data-pk='{$this->bookNdx}'";
				$this->page ['mainPageButtonEdit'] = 1;
				$this->page ['bookNdx'] = $this->bookNdx;
			}
		}
	}
}


/**
 * @param $app
 * @param $params
 * @return array
 */
function kbStatic ($app, $params)
{
	$engine = new kbTextsEngineStatic ($app);
	$engine->init();
	$engine->run ();
	return $engine->page;
}

/**
 * Class kbTextsEngineStatic
 * @package E10Pro\KB
 */
class kbTextsEngineStatic extends Utility
{
	var $bookNdx = 0;
	var $pageNdx = 0;

	var $page = [];
	var $books = [];
	var $requestPath = [];
	var $book;

	var $docRootDir;

	function init ()
	{
		$this->docRootDir = __APP_DIR__.'/documentation';
		$this->page ['urlPrefix'] = 'doc';

		$urlIdx = 1;
		while (1)
		{
			$up = $this->app->requestPath ($urlIdx);
			if ($up === '' || is_numeric($up))
				break;

			$this->docRootDir .= '/'.$up;
			$this->page ['urlPrefix'] .= '/'.$up;
			$urlIdx++;
		}

		while (1)
		{
			$up = $this->app->requestPath ($urlIdx);
			if ($up === '')
				break;

			$this->requestPath [] = $up;
			$urlIdx++;
		}

		$ndxs = explode ('.', $this->requestPath (0));

		if (isset ($ndxs[0]))
			$this->bookNdx = intval ($ndxs[0]);
		if (isset ($ndxs[1]))
			$this->pageNdx = intval ($ndxs[1]);

	}

	public function run ()
	{
		if ($this->requestPath (1) === 'images' || $this->requestPath (1) === 'download')
			return $this->sendImage ();
		if ($this->requestPath (1) === 'sitemap.xml')
			return $this->runSitemap();

		if ($this->pageNdx === 0 && $this->bookNdx === 0)
			return $this->runRoot ();
		if ($this->pageNdx === 0 && $this->bookNdx !== 0)
			return $this->runBook ();
		if ($this->pageNdx !== 0 && $this->pageNdx !== 0)
			return $this->runPage ();

		$this->page = ['title' => 'Stránka neexistuje', 'text' => 'Stránka neexistuje.', 'status' => 404];
	}

	public function runBook ()
	{

		$this->runPage();
		return;

		$this->loadBook();
		if ($this->book === FALSE)
			return $this->internalError ();

		$this->page ['subTemplate'] = 'e10pro.kb.kbstatic';

		//$this->page ['kbText'] = $pageText;
		$this->page ['kbTitle'] = $this->book['title'];
	}

	public function runPage ()
	{
		$this->loadBook();
		if ($this->book === FALSE)
			return $this->internalError ();

		$pageNdx = ($this->pageNdx) ? $this->pageNdx : $this->bookNdx;

		$pageFileName = $this->docRootDir.'/'.$this->bookNdx.'/xhtml/'.$pageNdx.'.xhtml';
		$pageText = file_get_contents($pageFileName);
		if ($pageText === FALSE)
			return $this->internalError ();

		$pageContentInfo = $this->book['content'][$pageNdx];
		$this->page ['kbText'] = $pageText;
		$this->page ['kbTitle'] = $pageContentInfo['title'];
		$this->page ['title'] = $this->book['title'];
		$this->page ['webTitle'] = $pageContentInfo['title'].' / '.$this->book['title'];

		// -- page navigation
		if (isset ($pageContentInfo['pagePrev']))
			$this->page ['pageNavPrev'] = ['ndx' => $pageContentInfo['pagePrev']['ndx'], 'title' => $pageContentInfo['pagePrev']['title'],
																		 'url' => $this->bookNdx.'.'.$pageContentInfo['pagePrev']['ndx']];
		if (isset ($pageContentInfo['pageNext']))
			$this->page ['pageNavNext'] = ['ndx' => $pageContentInfo['pageNext']['ndx'], 'title' => $pageContentInfo['pageNext']['title'],
																		 'url' => $this->bookNdx.'.'.$pageContentInfo['pageNext']['ndx']];
		if (isset ($pageContentInfo['pageUp']))
			$this->page ['pageNavUp'] = ['ndx' => $pageContentInfo['pageUp']['ndx'], 'title' => $pageContentInfo['pageUp']['title'],
																	 'url' => ($pageContentInfo['pageUp']['ndx'] == $this->bookNdx) ? $this->bookNdx : $this->bookNdx.'.'.$pageContentInfo['pageUp']['ndx']];

		// -- breadcrumbs
		if ($pageContentInfo['treeLevel'] > 0)
		{
			$bcn = [];

			$nextPage = $pageContentInfo;
			while ($nextPage['ownerText'])
			{
				$bcn[] = ['title' => $nextPage['title'], 'url' => $this->book['ndx'].'.'.$nextPage['ndx']];
				$nextPage = $this->book['content'][$nextPage['ownerText']];
			}
			$bcn[] = ['title' => $this->book['title'], 'url' => $this->book['ndx']];

			$this->page ['textHasBreadcrumbs'] = 1;
			$this->page ['breadcrumbs'] = array_reverse($bcn);
		}

		// -- downloads
		if (isset ($this->book['downloads']))
		{
			$this->page ['downloads'] = $this->book['downloads'];
			$this->page ['hasDownloads'] = 1;
		}

		if ($this->requestPath (1) === 'sitemap.xml')
		{
			$this->page ['forceSubtemplate'] = 'e10pro.kb.kbstaticSitemap';
			$this->page ['mimeType'] = 'application/xml';
		}
		else
			$this->page ['subTemplate'] = 'e10pro.kb.kbstatic';
	}

	public function runRoot ()
	{

		$this->page ['subTemplate'] = 'e10pro.kb.kbstatic';
	}

	public function runSitemap ()
	{
		//$this->page ['subTemplate'] = 'e10pro.kb.kbstatic';
		$this->runPage();
	}

	protected function loadBook ()
	{
		$fileName = $this->docRootDir.'/'.$this->bookNdx.'/xhtml/book.json';
		$this->book = $this->loadCfgFile($fileName);

		if ($this->pageNdx)
			$this->book['content'][$this->pageNdx]['active'] = 1;
		else
			$this->book['content'][$this->bookNdx]['active'] = 1;

		$this->page['bookContent'] = array_values($this->book['content']);
	}

	protected function internalError ($msg = '')
	{
		$this->page = ['title' => 'Interní chyba', 'text' => 'Interní chyba.', 'status' => 404];

		return FALSE;
	}

	protected function sendImage ()
	{
		if ($this->requestPath (1) === 'images')
			$filePath = $this->docRootDir.'/'.$this->bookNdx.'/xhtml/images/'.$this->requestPath (2);
		else
			$filePath = $this->docRootDir.'/'.$this->bookNdx.'/download/'.$this->requestPath (2);

		if (is_file($filePath))
		{
			$httpServer = $this->app->cfgItem ('serverInfo.httpServer', 0);

			$mime = mime_content_type ($filePath);
			header ("Content-type: $mime");
			header ("Cache-control: max-age=10368000");
			header ('Expires: '.gmdate('D, d M Y H:i:s', time()+10368000).'GMT'); // 120 days
			header ('Content-Disposition: inline; filename=' . basename ($filePath));

			if ($httpServer === 0)
				header ('X-SendFile: ' . $filePath);
			else
				header ('X-Accel-Redirect: ' . $this->app->dsRoot.substr($filePath, strlen(__APP_DIR__)));

			die();
		}
		$this->page = ['title' => 'Interní chyba', 'text' => 'Interní chyba.', 'status' => 404];
		return FALSE;
	}

	public function requestPath ($ndx)
	{
		if (isset ($this->requestPath[$ndx]))
			return $this->requestPath[$ndx];
		return '';
	}
}
