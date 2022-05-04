<?php

namespace E10\Web;



use \Texy, \E10\utils, \e10\AppLog, \e10\Application, \e10\web\WebPages, \e10\web\WebTemplateMustache;

\E10\Application::RegisterFunction ('template', 'fullTextSearch', 'e10.web.fullTextSearch');
\E10\Application::RegisterFunction ('template', 'pageMenu', 'e10.web.htmlPageMenu');
\E10\Application::RegisterFunction ('template', 'articleDownload', 'e10.web.articleDownload');
\E10\Application::RegisterFunction ('template', 'articleGallery', 'e10.web.articleGallery');
\E10\Application::RegisterFunction ('template', 'articleImage', 'e10.web.articleImage');
\E10\Application::RegisterFunction ('template', 'attFileName', 'e10.web.attFileName');
\E10\Application::RegisterFunction ('template', 'galleryImages', 'e10.web.galleryImages');
\E10\Application::RegisterFunction ('template', 'webNews', 'e10.web.getNews');
\E10\Application::RegisterFunction ('template', 'latestArticles', 'e10.web.latestArticles');
\E10\Application::RegisterFunction ('template', 'latestNews', 'e10.web.latestNews');
\E10\Application::RegisterFunction ('template', 'webArticles', 'e10.web.webArticles');
\E10\Application::RegisterFunction ('template', 'urlUser', 'e10.web.urlUser');
\E10\Application::RegisterFunction ('template', 'setParams', 'e10.web.setParams');



/**
 * E10Texy
 *
 */

class E10Texy extends \Texy
{
	public $app;
	public $owner = NULL;
	var $template = NULL;
	public $externalLinks = FALSE;

	public function __construct($app, &$owner = NULL, $unsafe = FALSE)
	{
		parent::__construct();
		$this->app = $app;
		if ($owner)
			$this->owner = &$owner;

		$this->imageModule->root = $app->dsRoot . '/';
		$this->imageModule->linkedRoot = $app->dsRoot . '/';
		$this->linkModule->root = $app->urlRoot . '/';
		$this->addHandler ('script', '\E10\Web\texyScriptHandler');
		$this->addHandler ('phrase', '\E10\Web\texyPhraseHandler');
		//$this->addHandler ('linkURL', '\E10\Web\texyLinkURLHandler');
		$this->addHandler ('beforeParse', '\E10\Web\texyBeforeparseHandler');

		if ($unsafe === FALSE)
			unset ($this->allowedTags['script']);

		$this->allowedTags['article'] = TRUE;
		$this->allowedTags['section'] = TRUE;
		$this->allowedTags['main'] = TRUE;
		$this->allowedTags['footer'] = TRUE;
		$this->allowedTags['details'] = TRUE;
		$this->allowedTags['summary'] = TRUE;
		$this->allowedTags['video'] = TRUE;
		$this->allowedTags['source'] = TRUE;
		$this->allowedTags['img'] = TRUE;
		$this->allowedTags['wrapper'] = TRUE;

		$this->headingModule->top = 2;
	}

	public function setOwner (&$owner, $tableId = FALSE)
	{
		$this->owner = &$owner;
		if ($tableId !== FALSE)
			$this->owner['tableId'] = $tableId;
	}

	public function setParams ($markup)
	{
		$parts = explode (';', $markup);
		$m = array_shift ($parts);

		forEach ($parts as $param)
		{
			$prm = explode (':', $param);
			if (count($prm) >= 2)
			{
				$pk = trim($prm[0]);
				$this->owner['params'][$pk] = $prm[1];
				if ($this->template)
					$this->template->setParam ($pk, $prm[1]);
			}
		}
	}
}

function texyScriptHandler ($invocation, $cmd, $args, $raw)
{
	$markup = $cmd.$raw;
	if ($markup[0] === '_')
	{
		$html = '&#123;&#123;'.substr($cmd.$raw, 1).'&#125;&#125;';
		return $invocation->texy->protect ($html, Texy::CONTENT_BLOCK);
	}

	if (substr($markup, 0, 9) == 'setParams')
	{
		$invocation->texy->setParams ($markup);
		return $invocation->texy->protect ('', Texy::CONTENT_BLOCK);
	}

	if ($markup === 'dsRoot')
	{
		return $invocation->texy->app->dsRoot;
	}

	$subparams = ";tableId:{$invocation->texy->owner['tableId']};ndx:{$invocation->texy->owner['ndx']}";

	if ($markup[0] === '$')
	{
		$str = utils::cfgInfo ($invocation->texy->app, substr($markup, 1));
		return $invocation->texy->protect ($str, Texy::CONTENT_BLOCK);
	}

	if ($markup[0] === '&')
	{
		$html = '{{{'.$markup.$subparams.'}}}';
		return $invocation->texy->protect ($html, Texy::CONTENT_BLOCK);
	}

	$html = '{{{@'.$markup.$subparams.'}}}';
	return $invocation->texy->protect ($html, Texy::CONTENT_BLOCK);
}

function texyPhraseHandler ($invocation, $phrase, $content, $modifier, $link)
{
	if ($link && $invocation->texy->externalLinks)
	{
		$link->modifier->attrs['target'] = '_blank';
		$link->modifier->attrs['rel'] = 'noopener';
	}

	if ($link && $link->URL[0] === '/')
		$link->URL = $invocation->texy->linkModule->root.substr($link->URL, 1);

	return $invocation->proceed();
}

function texyLinkURLHandler($invocation, $link)
{
	if ($link && $invocation->texy->externalLinks)
	{
		$link->modifier->attrs['target'] = '_blank';
		$link->modifier->attrs['rel'] = 'noopener';
	}
	return $invocation->proceed();
}

function texyBeforeparseHandler($texy, &$text, $singleLine)
{
	if ($texy->template)
		$text = str_replace("{{templateRoot}}",$texy->template->templateRoot(), $text);
	$text = str_replace("{{urlRoot}}", $texy->app->urlRoot, $text);
	$text = str_replace("{{dsRoot}}", $texy->app->dsRoot, $text);
}

function renderPage ($app, &$page, $params)
{
	$texy = new E10Texy($app, $page);
	$texy->headingModule->top = 2;

	if ($params !== FALSE && isset ($params ['owner']))
		$template = $params ['owner'];
	else
	{
		$template = new WebTemplateMustache ($app);
		$template->webEngine = WebPages::$engine;
		$template->loadTemplate ('e10pro.templates.basic', 'page.mustache');
	}

	$texy->template = $template;

	$page ['html'] = $texy->process ($page ['text']);
	$page ['html'] = $template->renderPagePart($template->pagePart, $page ['html']);

	if (isset ($page ['perex']))
	{
		$texy->headingModule->top = 2;
		$page ['htmlPerex'] = $texy->process ($page ['perex']);
		$page ['htmlPerex'] = $template->renderPagePart($template->pagePart, $page ['htmlPerex']);
	}
	if (isset ($page ['text_paper_doc']))
	{
		$texy->headingModule->top = 2;
		$page ['htmlPaperDoc'] = $texy->process ($page ['text_paper_doc']);
		$page ['htmlPaperDoc'] = $template->renderPagePart($template->pagePart, $page ['htmlPaperDoc']);
	}
}

function getNews ($app, $params)
{
	$template = $params ['owner'];

	$c = "";

	$news = new \E10\Web\TableNews ($app);
	$q = "SELECT * FROM [e10_web_news] WHERE ([date_from] IS NULL OR [date_from] <= DATE(NOW())) AND ([date_to] IS NULL OR [date_to] >= DATE(NOW())) ";
	$q .= "ORDER BY [order], [ndx] DESC";
	$rows = $news->fetchAll ($q);
	forEach ($rows as $r)
	{
		$page = $r;
		$page ['tableId'] = 'e10.web.news';
		renderPage ($app, $page, $params);

		$c .= "<div class='webNew'>";
		if ($r ['date_from'])
			$c .= "<div class='newdate'>" . \E10\df ($r ['date_from'], '%D') . '</div>';
		if ($r ['title'] != '')
			$c .= '<h3>' . \E10\es ($r ['title']) . '</h3>';
		$c .= $r ['html'];
		$c .= "</div>";
	}
	return $c;
}


function getNewsArray ($app, $tops = FALSE, $paper = FALSE)
{
	$a = array ();

	$news = new \E10\Web\TableNews ($app);
	$q = "SELECT * FROM [e10_web_news] WHERE (([date_from] IS NULL OR [date_from] <= DATE(NOW())) AND ([date_to] IS NULL OR [date_to] >= DATE(NOW()))) ";

	if ($tops)
		$q .= "AND [to_top] = 1 ";
	if ($paper)
		$q .= "AND [to_paper_docs] = 1 ";

	$q .= "ORDER BY [to_top] DESC, [order], [ndx] DESC";
	$rows = $news->fetchAll ($q);
	forEach ($rows as $r)
	{
		$page = $r;
		$page ['tableId'] = 'e10.web.news';
		renderPage ($app, $page, $params);
		$a[] = array ("title" => $r ['title'], "date_from" => $r ['date_from'], "url" => $r ['url'], "htmlText" => $r ['html'], "htmlPerex" => $r ['htmlPerex'], "htmlPaperDoc" => $r ['htmlPaperDoc']);

	}
	return $a;
}

function searchPages ($app, $text)
{
	$pages = new \E10\Web\TablePages ($app);
	$q = "SELECT * FROM [e10_web_pages] where [text] LIKE %s";
	$r = $app->db()->query ($q, '%' . $text . '%');
	$pages = $r->fetchAll ();
/*	if ($page)
	{
		renderPage ($app, $page);
	}*/
	return $pages;
}

function activeWebMenu ($app, $menu, $maxLevel = -1)
{
	$m = $menu;
	$level = 0;
	$activeUrl = $app->requestPath ();
	while (1)
	{
		$thisUrl = $app->requestPath ($level);
		if ($thisUrl == '')
			break;
		$subMenu = utils::searchArray ($m, 'url', '/'.$thisUrl);
		if (!isset ($subMenu['items']))
			break;
		$m = $subMenu['items'];

		$level++;
		if ($maxLevel != -1 && $level > $maxLevel)
			break;

		if ($activeUrl === $thisUrl)
			break;
	}
	return $subMenu;
}

function htmlPageMenu ($app, $params)
{
	if ($app->cfgItem ('loginRequired', 0) && !$app->user->isAuthenticated ())
		return '';

	$menuCfgKey = 'e10.web.menu.'.$params['owner']->webEngine->serverInfo['ndx'];
	if ($params['owner']->webEngine->webPageType === WebPages::wptSystemLogin)
		return '';

	$menuKey = \E10\searchParam($params, 'menu', '');
	$menu = $app->cfgItem ($menuCfgKey, NULL);

	$menuClass = \E10\searchParam ($params, 'menuClass', 'menuMain');
	$menuClassSub = \E10\searchParam ($params, 'menuClassSub', 'menuMainSub');

	$menuMode = \E10\searchParam ($params, 'menuMode', 'simple');
	$menuLevel = \E10\searchParam ($params, 'menuLevel', -1);
	$activeMenuLevel = \E10\searchParam ($params, 'activeMenuLevel', -1);

	if ($menuKey === 'active')
		$menu = activeWebMenu ($app, $menu['items'], $activeMenuLevel);

	$menuParts = array ();
	$html = renderPageMenu2 ($app, $menu, $menuMode, $menuParts, $menuClass, $menuClassSub/*, $classSub = 'menuMainSub'*/);

	if ($menuLevel == -1)
		return $html;

	return $menuParts [$menuLevel];
}

function renderPageMenu2 ($app, $menu, $menuMode, array &$result, $class='menuMain nav', $classSubOld = 'menuMainSub', $level = 0)
{
	if ((!$menu) || !isset($menu['items']))
		return '';

	$subMenu = NULL;

	$c = "";

	$class_ul = $class;
	if ($class_ul === 'bootstrap-dropdown')
		$class_ul = 'dropdown-menu';
	$classSub = $classSubOld;
	$class_a = '';

	$rp = $app->requestPath();

	$c .= "<ul class='$class_ul'>";
	foreach ($menu['items'] as $mi)
	{
		if (isset ($mi ['menuDisabled']))
			continue;

		$menuTitle = $mi['title'];
		$menuUrl = $mi['url'];
		$linkUrl = isset ($mi['redirectTo']) ? $mi['redirectTo'] :$mi['url'];

		$urlParts = explode ('/', $menuUrl);
		$itemClassId = implode('-', $urlParts);
		$itemClass = "$class_ul$itemClassId $class-l-$level";

		if (isset($mi['items']) && count($mi['items']))
			$itemClass .= " $class-with-submenu";
		else
			$itemClass .= " $class-no-submenu";

		$isActive = false;

		if (isset ($urlParts [1]) && $urlParts [1] == $app->requestPath (0) && ($level === 0))
			$isActive = true;
		else
		if (isset ($urlParts [2]) && $urlParts [2] == $app->requestPath (1) && ($level === 1))
			$isActive = true;
		else
		if ($menuUrl == $rp || (isset ($urlParts [1]) && $urlParts [1] === $app->requestPath (0)))
			$isActive = true;
		else
		if ($linkUrl == $rp)
			$isActive = true;

		$isActive2 = FALSE;

		if (strlen ($rp) >= strlen ($linkUrl))
		{
			$xx1 = substr($rp, 0, strlen ($linkUrl));
			if ($xx1 == $linkUrl)
				$isActive2 = TRUE;
		}
		else
		if (strlen ($rp) <= strlen ($linkUrl))
		{
			$xx1 = substr($linkUrl, 0, strlen ($rp));
			if ($xx1 == $linkUrl)
				$isActive2 = TRUE;
		}

		if ($rp != '/' && $urlParts [1] == '' )
			$isActive2 = false;

		if ($isActive2)
			$itemClass .= " active";

		$href = $app->urlRoot . $linkUrl;
		if (isset ($mi['redirectTo']))
		{
			if (substr($mi['redirectTo'], 0, 4) === 'http')
				$href = $mi['redirectTo'];
			else
				$href = $app->urlRoot . $mi['redirectTo'];
		}

		if ($classSubOld === 'bootstrap-dropdown' && isset($mi['items']))
		{
			$itemCode = "<li class='dropdown'><a href='" . $href . "' class='dropdown-toggle' data-toggle='dropdown'>" . utils::es ($menuTitle) . ' <b class="caret"></b></a>';
		}
		else
			$itemCode = "<li class='$itemClass'><a href='" . $href . "'$class_a>" . utils::es ($menuTitle) . '</a>';

		if ($isActive)
			$subMenu = $mi;

		if ($menuMode == 'full')
		{
			$itemCode .= renderPageMenu2 ($app, $mi, $menuMode, $result, $classSub, $classSub,  $level + 1);
			$subMenu = NULL;
		}
		$itemCode .= '</li>';

		$c .= $itemCode;
	}
	$c .= "</ul>";

	$result [] = $c;
	if ($subMenu != NULL)
		$c .= renderPageMenu2 ($app, $subMenu, $menuMode, $result, $classSub, $classSub, $level + 1);

	return $c;
}

function articleDownload ($app, $params)
{
 	$template = $params ['owner'];
	$c = "<ul class='article-download'>";
  $attachments = \E10\Base\getAttachments ($app, $params['tableId'], $params['ndx'], TRUE);
  forEach ($attachments as $att)
	{
		$txt = \E10\es ($att ['name']);
		$fn = $att ['path'] . $att ['filename'];
		$c .= "<li><a class='article-gallery-1' href='{$app->dsRoot}/att/{$fn}'>$txt</a></li>";
	}
  $c .= "</ul>";
  return $c;
}

function webArticles ($app, $params)
{
	$articleId = intval($app->requestPath (1));

	$page = [];
	$page ['subTemplate'] = 'e10.web.articles';
	$page ['tableId'] = 'e10.web.articles';
	$page ['params'] = [];
	$page ['url_'.$app->requestPath (0)] = 1;
	$page ['url_'.$app->requestPath (0).'_'.$app->requestPath (1)] = 1;

	$texy = new E10Texy($app, $page);
	$texy->headingModule->top = 3;

	if ($articleId)
	{
		$q[] = 'SELECT pages.*, persons.fullName as authorFullName, persons.firstName as authorFirstName, persons.lastName as authorLastName';
		array_push($q, ' FROM [e10_web_articles] AS pages LEFT JOIN e10_persons_persons AS persons ON pages.author = persons.ndx');
		array_push($q, ' WHERE pages.[ndx] = %i AND pages.[docStateMain] != 4', $articleId);
		$a = $app->db()->query ($q)->fetch ();
		if ($a)
		{
			$page ['ndx'] = $a ['ndx'];
			$page ['title'] = $a ['title'];
			$page ['article']['title'] = $a ['title'];
			$page ['tableId'] = 'e10.web.articles';
			if (WebPages::$secureWebPage)
			{
				$page ['bodyEditParams'] = " data-table='e10.web.articles' data-pk='{$page ['ndx']}'";
			}

			$texy->setOwner ($page);

			$article = [];
			$params ['owner']->setPage ($page);
			$article['text'] = $params ['owner']->renderPagePart ('content1', $texy->process ($a ['text']));
			$article['perex'] = $params ['owner']->renderPagePart ('content2', $texy->process ($a ['perex']));

			$article['authorFullName'] = $a ['authorFullName'];
			$article['authorFirstName'] = $a ['authorFirstName'];
			$article['authorLastName'] = $a ['authorLastName'];
			$article['datePub'] = \E10\df ($a['datePub'], '%D');

			$params ['owner']->page['title'] = $a['title'];
			$params ['owner']->page['articleTitle'] = $a['title'];

			$params ['owner']->page['article'] = $article;
			if (WebPages::$secureWebPage)
			{
				$params ['owner']->page['bodyEditParams'] = " data-table='e10.web.articles' data-pk='{$page ['ndx']}'";
			}

			$c = $params ['owner']->renderSubtemplate ('e10.web.articles');

			return $c;
		}
		else
		{
			$page ['title'] = 'Článek nebyl nalezen';
			$page ['text'] = 'Článek nebyl nalezen';
			$params ['owner']->page['articleTitle'] = 'Článek nebyl nalezen';
			$page['article']['text'] = 'Článek nebyl nalezen';
			$params ['owner']->page['status'] = 404;

			return 'Článek nebyl nalezen.';
		}
	}

	$partsParam = \E10\searchParam ($params, 'parts', '');
	$sectionsIds = explode(',', $partsParam);
	$sectionsNdx = [];
	foreach ($sectionsIds as $pid)
	{
		$ndx = intval($pid);
		if ($ndx)
			$sectionsNdx[] = $ndx;
	}

/*
	$webParts = $app->cfgItem ('e10.base.clsf.webParts', FALSE);
	foreach ($partsIds as $partId)
	{
		$webPart = utils::searchArray ($webParts, 'id', $partId);
		if ($webPart)
			$partsNdx[] = $webPart ['ndx'];
	}
*/

	$page ['articles'] = [];

	$pageSize = intval(\E10\searchParam ($params, 'pageSize', 4));

	$firstPage = intval($app->testGetParam('p'));
	$firstRecord = $pageSize * $firstPage;

	$today = utils::today('Y-m-d');

	$q[] = 'SELECT COUNT(*) as [cnt] FROM [e10_web_articles] WHERE 1';
	array_push($q, ' AND articleSection IN %in', $sectionsNdx);

	if (WebPages::$secureWebPage)
		array_push($q, "AND e10_web_articles.[docStateMain] != 4");
	else
		array_push ($q, 'AND e10_web_articles.[docState] IN (4000, 8000) ',
			'AND (e10_web_articles.[datePub] IS NULL OR e10_web_articles.[datePub] <= %d)', $today,
			'AND (e10_web_articles.[dateClose] IS NULL OR e10_web_articles.[dateClose] >= %d)', $today);

	$cntRow = $app->db()->query ($q)->fetch();
	$totalArticles = $cntRow ['cnt'];

	unset ($q);

	$q[] = 'SELECT pages.*, persons.fullName as authorFullName, persons.firstName as authorFirstName, persons.lastName as authorLastName,';
	array_push ($q, ' attCoverImages.path AS coverImagePath, attCoverImages.fileName AS coverImageFileName');
	array_push ($q, ' FROM [e10_web_articles] AS pages');
	array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON pages.author = persons.ndx ');
	array_push ($q, ' LEFT JOIN e10_attachments_files AS attCoverImages ON pages.coverImage = attCoverImages.ndx');
	array_push ($q, ' WHERE 1');

	if (count($sectionsNdx))
	{
		array_push($q, ' AND pages.articleSection IN %in', $sectionsNdx);
	}

	if (WebPages::$secureWebPage)
		array_push ($q, "AND pages.[docStateMain] != 4 ");
	else
		array_push ($q, 'AND pages.[docState] IN (4000, 8000) ',
			'AND (pages.[datePub] IS NULL OR pages.[datePub] <= %d)', $today,
			'AND (pages.[dateClose] IS NULL OR pages.[dateClose] >= %d)', $today);

	array_push ($q, 'ORDER BY pages.[onTop] DESC, [datePub] DESC, [ndx] DESC', " LIMIT $firstRecord, $pageSize");

	$pks = [];
	$rows = $app->db()->query ($q);

	$page ['articles'] = [];
	$params ['owner']->setPage ($page);

	forEach ($rows as $a)
	{
		$article = [];
		$article ['ndx'] = $a ['ndx'];
		$article ['title'] = $a ['title'];
		$article ['tableId'] = 'e10.web.articles';
		$texy->setOwner ($article);

		$article ['text'] = $params ['owner']->renderPagePart ('content',  $texy->process ($a ['text']));
		$article ['perex'] = $params ['owner']->renderPagePart ('content',  $texy->process ($a ['perex']));

		$article ['url'] = $app->urlRoot . $app->requestPath () . '/' . $a ['ndx'];
		$article ['authorFullName'] = $a ['authorFullName'];
		$article ['authorFirstName'] = $a ['authorFirstName'];
		$article ['authorLastName'] = $a ['authorLastName'];
		$article ['datePub'] = utils::datef ($a['datePub'], '%D');
		$article ['article'] = 1;
		if (isset ($a['datePub']))
			$article ['order'] = $a['datePub']->format('Ymd');

		if ($a['coverImagePath'])
		{
			$article ['coverImagePath'] = $a['coverImagePath'].$a['coverImageFileName'];
		}

		$page ['articles'][$a ['ndx']] = $article;
		$pks[] = $a ['ndx'];
	}

	// -- classifications
	$classification = \e10\base\loadClassification ($app, 'e10.web.pages', $pks, '', FALSE, TRUE);
	foreach ($classification as $articleNdx => $articleClassification)
	{
		$page ['articles'][$articleNdx]['classification'] = $articleClassification;
		foreach ($articleClassification['webParts'] as $articleWebPart)
			$page ['articles'][$articleNdx]['webpart_'.$articleWebPart['id']] = 1;
	}

	// -- news
	$withNews = \E10\searchParam ($params, 'news', FALSE);
	if ($withNews !== FALSE && isset($params ['owner']->data[$withNews]))
	{
		$news = $params ['owner']->data[$withNews]['all'];
		foreach ($news as $n)
		{
			$article = [];
			$article ['title'] = $n ['title'];
			$article ['perex'] = $n ['htmlPerex'];
			$article ['text'] = $n ['htmlText'];
			$article ['perexIllustrationFileName'] = $n['perexIllustrationFileName'];
			$article ['news'] = 1;
			if (isset ($n['date_from']))
				$article ['order'] = $n['date_from']->format('Ymd');

			$page ['articles'][] = $article;
		}
	}

	$thisUrl = $app->urlRoot . $app->requestPath ();
	$page ['pager'] = "celkem: $totalArticles" .
		"<a href='$thisUrl'>Starší články</a>";

	$needPager = 0;
	if ($firstPage > 0)
	{
		$pg = $firstPage - 1;
		$page ['pagePrev'] = "$thisUrl?p=$pg";
		$params ['owner']->page['pagePrev'] = "$thisUrl?p=$pg";
		$needPager = 1;
	}

	if ($firstRecord + $pageSize < $totalArticles)
	{
		$pg = $firstPage + 1;
		$page ['pageNext'] = "$thisUrl?p=$pg";
		$params ['owner']->page['pageNext'] = "$thisUrl?p=$pg";
		$needPager = 1;
	}

	$page ['needPagination'] = $needPager;

	$params ['owner']->page['articles'] = \e10\sortByOneKey($page['articles'], 'order', FALSE, FALSE);
	$params ['owner']->page['needPagination'] = $page ['needPagination'] = $needPager;

	if (count ($page ['articles']) == 0)
	{
		unset ($page ['articles']);
		unset ($page ['subTemplate']);

		$page ['title'] = 'Článek nebyl nalezen';
		$page ['text'] = 'Nebyl nalezen žádný článek.';
	}

	$c = $params ['owner']->renderSubtemplate ('e10.web.articles');

	return $c;
}


function articleGallery ($app, $params)
{
	$attIdParam = \E10\searchParam ($params, 'id', '');
	if ($attIdParam !== '')
	{
		$idParts = explode (',', $attIdParam);
		$ids = array ();
		forEach ($idParts as $num)
			$ids [] = intval (trim($num));

		$attachments = \E10\Base\loadAttachments ($app, $ids);
		$images = $attachments['images'];
	}
	else
	{
		$articleNdx = intval ($params['ndx']);
		$attachments = \E10\Base\loadAttachments ($app, array($articleNdx), $params['tableId']);
		$images = $attachments[$articleNdx]['images'];
	}

	$cnt = 0;
	$totalCnt = count ($images);
	$gallery = array ('id' => 'article-gallery-'.$params ['owner']->counter());
	forEach ($images as $att)
	{
		$img = array (
			'imgUrl' => \E10\Base\getAttachmentUrl ($app, $att),
			'thumbUrl' => \E10\Base\getAttachmentUrl ($app, $att, 400),
			'fileName' => $att['path'] . $att['filename'],
			'name' => $att['name'], 'perex' => $att['perex']);

		$cnt++;

		if ($cnt !== $totalCnt)
		{
			if ($cnt % 2 === 0) $img['col2'] = 1;
			elseif ($cnt % 3 === 0) $img['col3'] = 1;
			elseif ($cnt % 4 === 0) $img['col4'] = 1;
			elseif ($cnt % 5 === 0) $img['col5'] = 1;
		}
		$gallery['images'][] = $img;
	}

	$params ['owner']->data['gallery'] = $gallery;
	$c = $params ['owner']->renderSubtemplate ('e10.web.articleGallery');

	return $c;
}


function articleImage ($app, $params)
{
	$attIdParam = \E10\searchParam ($params, 'id', '');
	$srcParam = \E10\searchParam ($params, 'src', '');
	$srcVarParam = \E10\searchParam ($params, 'srcVar', '');

	$attDictIdParam = \E10\searchParam ($params, 'dictId', '');
	if ($attDictIdParam !== '')
	{
		if (isset($params ['owner']->dict[$attDictIdParam][$params ['owner']->lang]))
			$attIdParam = $params ['owner']->dict[$attDictIdParam][$params ['owner']->lang];
	}

	if ($srcParam !== '' || $srcVarParam !== '')
	{
		$attachments = ['images' => []];

		if ($srcVarParam !== '')
		{
			$srcParam = utils::cfgItem($params ['owner']->data, $srcVarParam);
		}

		$imgFileNames = explode (',', $srcParam);
		$ndx = 1;
		$ids = [];
		foreach ($imgFileNames as $oneFn)
		{
			$img = ['ndx' => $ndx, 'folder' => '', 'attplace' => 0];
			$img ['path'] = '';
			$img ['filename'] = $oneFn;

			$img['url'] = $app->dsRoot.$oneFn;
			$img['filetype'] = strtolower(substr(strrchr($oneFn, '.'), 1));

			if (strtolower($img['filetype']) === 'pdf' || strtolower($img['filetype']) === 'svgxxx')
				$img['original'] = 1;
			if (strtolower($img['filetype']) === 'svg')
				$img['svg'] = 1;
			if (strtolower($img['filetype']) === 'pdf')
				$img['original'] = 1;

			$attachments['images'][] = $img;
			$ids[] = $ndx;
			$ndx++;
		}
	}
	elseif ($attIdParam == '')
	{
		$articleNdx = isset ($params['ndx']) ? intval ($params['ndx']) : 0;
		if (!$articleNdx)
			$articleNdx = $params['owner']->data['mainNdx'];
		$attachments = \E10\Base\loadAttachments ($app, [$articleNdx], $params['tableId'])[$articleNdx];
		forEach ($attachments['images'] as $att)
			$ids[] = $att['ndx'];
	}
	else
	{
		$idParts = explode (',', $attIdParam);
		$ids = array ();
		forEach ($idParts as $num)
			$ids [] = intval (trim($num));

		$attachments = \E10\Base\loadAttachments ($app, $ids);
	}

	$clickable = \E10\searchParam ($params, 'clickable', 0);

	$cnt = 0;
	$totalCnt = isset($attachments['images']) ? count ($attachments['images']) : 0;
	$gallery = ['id' => 'article-gallery-'.$params ['owner']->counter(),
							'inline' => \E10\searchParam ($params, 'inline', 1), 'rows' => []];
	$style = \E10\searchParam ($params, 'style', NULL);
	if ($style)
	{
		$gallery['style'] = $style;
		$gallery['style-'.$style] = 1;
	}
	else
	if ($totalCnt === 1)
	{
		$gallery['style'] = 'single';
		$gallery['style-single'] = 1;
	}

	$auto = \E10\searchParam ($params, 'auto', 1);
	$cntPerRow = \E10\searchParam ($params, 'cnt', 0);
	if ($cntPerRow)
		$auto = 0;

	if ($auto)
	{
		if ($totalCnt === 2/* || $totalCnt === 4*/)
		{
			$cntPerRow = 2;
			$auto = 0;
		}
		elseif ($totalCnt > 4)
			$cntPerRow = 3;
	}

	if (!$cntPerRow)
		$cntPerRow = 3;

	$imgCss = '';
	$width = intval(\E10\searchParam ($params, 'x', 0));
	if ($width)
		$imgCss .= "width: {$width}px;";

	if ($imgCss != '')
		$gallery['imgcss'] = $imgCss;

	$activeRow = 0;
	$cntInRow = 0;

	$maxImages = \E10\searchParam ($params, 'max', 0);
	$autoColumns = [2, 3, 4];
	//$autoColumns = [1, 2, 3, 4];

	$first = 1;
	foreach ($ids as $attNdx)
	{
		$att = utils::searchArray($attachments['images'], 'ndx', $attNdx);
		if ($att === NULL)
			continue;

		if ($auto && isset($autoColumns[$activeRow]))
			$cntPerRow = $autoColumns[$activeRow];

		if ($cntInRow >= $cntPerRow)
		{
			if ($maxImages && $cnt >= $maxImages)
				break;
			$cntInRow = 0;
			$activeRow++;
		}

		if (!isset ($gallery['rows'][$activeRow]))
			$gallery['rows'][$activeRow] = ['cnt' => 0, 'images' => []];

		$img = [
			'folder' => $att['folder'],
			'imgUrl' => \E10\Base\getAttachmentUrl ($app, $att),
			'thumbUrl' => \E10\Base\getAttachmentUrl ($app, $att, 400),
			'fileName' => $att['path'] . $att['filename'],
			'name' => $att['name'], 'perex' => $att['perex'], 'ndx' => $attNdx
		];

		if (isset($att['original']))
			$img['original'] = $att['original'];
		if (isset($att['svg']))
			$img['svg'] = $att['svg'];

		if ($cnt === 0)
			$img['first'] = 1;

		$cnt++;

		$gallery['images'][] = $img;
		$gallery['rows'][$activeRow]['images'][] = $img;
		$gallery['rows'][$activeRow]['cnt']++;

		$cntInRow++;
	}

	$gallery['clickable'] = $clickable;

	foreach ($gallery['rows'] as &$row)
		$row ['cnt'.$row['cnt']] = 1;

	$params ['owner']->data['gallery'] = $gallery;

	$showAs = \E10\searchParam ($params, 'showAs', 'images');

	if ($showAs === 'carousel')
		$c = $params ['owner']->renderSubtemplate ('e10.web.articleCarousel');
	else
		$c = $params ['owner']->renderSubtemplate ('e10.web.articleImage');

  return $c;
}



function attFileName ($app, $params)
{
	$attNdx = 0;

	$attIdParam = \E10\searchParam ($params, 'id', '');
	if ($attIdParam !== '')
		$attNdx = intval($attIdParam);
	else
	{
		$attVarParam = \E10\searchParam ($params, 'var', '');
		if ($attVarParam !== '')
		{
			$attNdx = intval($params ['owner']->getVar($attVarParam));
		}
	}

	if ($attNdx === 0)
		return '';

	$a = $app->db->query ('SELECT * FROM [e10_attachments_files] WHERE ndx = %i', $attNdx)->fetch();
	if (!$a)
		return '';

	$fn = '';
	if ($a['attplace'] === 0) // TableAttachments::apLocal
		$fn = '/att/' . $a['path'] . $a['filename'];

	return $fn;
}

function createExtranetPage ($app)
{
	return createWebPage ($app, WebPages::wptExtranet);
}

function createWebPageSec ($app)
{
	$servers = $app->cfgItem ('e10.web.servers.list');

	// blank url? redirect to first server...
	if ($app->requestPath(1) === '')
	{
		$s = array_pop($servers);
		header ('Location: ' . $app->urlProtocol . $_SERVER['HTTP_HOST'] . $app->urlRoot . '/www/'.$s['urlStartSec'].'/');
		die();
	}

	// -- search web server
	$urlStart = $app->requestPath(1);
	forEach ($servers as $s)
	{
		if (mb_substr ($urlStart, 0, mb_strlen ($s['urlStartSec'], 'UTF-8'), 'UTF-8') === $s['urlStartSec'])
			return createWebPage ($app, WebPages::wptWebSecure, $s);
	}

	return array ('status' => 404, 'code' => 'invalid url');
}

function createWebPageWiki ($app)
{
	$wikiNdx = 0;
	$parts = explode('-', $app->requestPath[1]);
	if (count($parts) === 2 && $parts[0] === 'wiki')
		$wikiNdx = intval($parts[1]);

	$serverInfo = [
		'sn' => 'Wiki', 'fn' => 'Wiki '.$app->cfgItem('options.core.ownerFullName'),
		'title' => 'Pokus', 'template' => 'web.core-bs5',
		'mode' => 'app',
		'hpFunction' => 'wiki', 'wiki' => $wikiNdx,
		'look'=>'102100-default',
		'templateStylePath' => 'www-root/templates/web/' . 'core-bs5' . '/styles/',
		'bodyClasses' => 'e10-in-app-wiki',
		'templateParams' => [
			'defaultTemplateType'=>'page-wiki',
			'topMenuStyle' => 'top-menu-tabs',
			'headerTitle' => '',
			'topMenuFixedTop' => 1,
			'icon' => 'icon-book',
			'dsIcon' => $app->dsIcon(),
			'pageTitle' => $app->cfgItem('options.core.ownerFullName')
		]
	];

	$wiki = $app->cfgItem ('e10pro.kb.wikies.'.$wikiNdx, FALSE);
	if ($wiki)
	{
		$serverInfo['title'] = $wiki['title'];
		if (isset($wiki['icon']) && $wiki['icon'])
			$serverInfo['templateParams']['icon'] = $wiki['icon'];
	}

	return createWebPage ($app, WebPages::wptWiki, $serverInfo);
}

function createWebPage ($app, $webPageType, $serverInfo = NULL)
{
	Application::$appLog->setTaskType(AppLog::ttWeb, AppLog::tkNone);

	$engine = new webPages($app);
	$engine->setServerInfo ($serverInfo);
	$engine->setPageType ($webPageType);
	return $engine->run();
}

function checkWebPage ($app)
{
	// -- search web server
	$urlStart = $_SERVER['HTTP_HOST'].$app->urlRoot.$app->requestPath();
	$servers = $app->cfgItem ('e10.web.servers.list');
	forEach ($servers as $s)
	{
		if (mb_substr ($urlStart, 0, mb_strlen ($s['urlStart'], 'UTF-8'), 'UTF-8') === $s['urlStart'])
			return createWebPage ($app, WebPages::wptWeb, $s);
	}

	// -- system 'users' pages
	if ($app->requestPath(0) === 'user')
	{
		$serverInfo = [
			'ndx' => 0, 'sn' => 'Přihlášení', 'fn' => 'Přihlášení k '.$app->cfgItem('options.core.ownerFullName'), 'title' => 'Přihlášení',
			'authType' => 2, 'loginRequered' => 1,
		];
		return createWebPage($app, WebPages::wptSystemLogin, $serverInfo);
	}

	// blank url? redirect to application
	if ($app->requestPath(0) === '')
	{
		header ('Location: ' . $app->urlProtocol . $_SERVER['HTTP_HOST'] . $app->urlRoot . '/app/');
		die();
	}

	return array ('status' => 404, 'code' => 'invalid url');
}

function fullTextSearch ($app, $params)
{
	$q = \E10\Application::testGetParam ('q');

	if ($q == '')
	{
		$c = "Není zadán hledaný výraz.";
		return $c;
	}

	$pages = \E10\Web\searchPages ($app, $q);
	if (count ($pages) == 0)
	{
		$c = "Nabyla nalezena žádná stránka.";
		return $c;
	}
	else
	{
		$c = '<ol>';
		forEach ($pages as $page)
		{
			$c .= "<li><a href='{$app->urlRoot}{$page ['url']}'>" . $page ['title'] . "</a></li>";
		}
		$c .= '</ol>';
	}

	return $c;
}


function setParams ($app, $params)
{

	$template = $params ['owner'];
	//$urlType = \E10\searchParam($params, 'type', 'login');
	forEach ($params as $paramKey => $paramValue)
	{
		if (is_string($paramValue))
		{
			$template->page ['params'][$paramKey] = $paramValue;
			$template->setParam ($paramKey, $paramValue);
		}
		//error_log ("##### $paramKey => $paramValue");
	}


	return '';
}

function urlUser ($app, $params)
{
	if (!$app->authenticator)
		return '';

	$template = $params ['owner'];
	$urlType = \E10\searchParam($params, 'type', 'login');

	$uu = $app->authenticator->option ('pathBase').'/';

	switch ($urlType)
	{
		case 'login':
						$uu .= $app->authenticator->option ('pathLogin'); break;
		case 'logout':
						$uu .= $app->authenticator->option ('pathLogoutCheck'); break;
		case 'registration':
						$uu .= $app->authenticator->option ('pathRegistration'); break;
		case 'lostPassword':
						$uu .= $app->authenticator->option ('pathLostPassword'); break;
		case 'setLanguage':
						$uu .= $app->authenticator->option ('pathSetLanguage'); break;
	}
	return $app->urlRoot.'/'.$uu;
}

/**
 * webArticle
 *
 * Articles (blog?)
 */

function webArticle ($app, $articlesSections, $template, $urlBegin)
{
	if ($app->requestPath (1) === 'atom')
		return webArticlesFeed($app, $articlesSections, $app->requestPath (1), $urlBegin);

	$articleId = intval($app->requestPath (count($app->requestPath) - 1));

	$page = [];
	$page ['subTemplate'] = 'e10.web.articles';
	$page ['tableId'] = 'e10.web.pages';
	$page ['params'] = [];
	$page ['url_'.$app->requestPath (0)] = 1;
	$page ['url_'.$app->requestPath (0).'_'.$app->requestPath (1)] = 1;

	$texy = new E10Texy($app, $page);
	$texy->headingModule->top = 3;

	if ($articleId)
	{
		$q = [];
		array_push ($q, "SELECT pages.*, persons.fullName as authorFullName, persons.firstName as authorFirstName, persons.lastName as authorLastName ");
		array_push ($q, ' FROM [e10_web_articles] AS pages');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON pages.author = persons.ndx');
		array_push ($q, ' WHERE pages.[ndx] = %i', $articleId, ' AND pages.[docStateMain] != 4');
		$a = $app->db()->query ($q)->fetch ();
		if ($a)
		{
			$as = $app->cfgItem ('e10.web.articlesSections.'.$a['articleSection']);

			$page ['ndx'] = $a ['ndx'];
			$page ['title'] = $a ['title'];
			$page ['article']['title'] = $a ['title'];
			$page ['tableId'] = 'e10.web.articles';
			if (WebPages::$secureWebPage)
			{
				$b = ['text' => 'Opravit článek', 'icon' => 'system/actionOpen',
					'attr' => [
						['k' => 'table', 'v' => 'e10.web.articles'],
						['k' => 'pk', 'v' => $page ['ndx']],
						['k' => 'action', 'v' => 'edit']
					]
				];
				$page ['buttons'][] = $b;
			}

			$texy->setOwner ($page);

			$articleText = $a ['text'];
			if ($as['addGallery'])
			{
				$articleText .= '{{articleImage}}';
			}

			$page ['article']['text'] = $template->renderPagePart('content', $texy->process ($articleText));
			$page ['article']['perex'] = $template->renderPagePart('content', $texy->process ($a ['perex']));

			$page ['article']['authorFullName'] = $a ['authorFullName'];
			$page ['article']['authorFirstName'] = $a ['authorFirstName'];
			$page ['article']['authorLastName'] = $a ['authorLastName'];
			$page ['article']['datePub'] = \E10\df ($a['datePub'], '%D');

			$page ['article']['section'] = $as;
		}
		else
		{
			$page ['title'] = 'Článek nebyl nalezen';
			$page ['text'] = 'Článek nebyl nalezen';
			$page ['status'] = 404;
		}
		return $page;
	}

	//$page ['title'] = $webPart ['name'];
	$page ['params'] = [];
	$page ['articles'] = [];

	$pageSize = 10;
	$firstPage = intval($app->testGetParam('p'));
	$firstRecord = $pageSize * $firstPage;

	//$partNdx = $webPart ['ndx'];

	$today = utils::today('Y-m-d');

	$q[] = 'SELECT COUNT(*) AS [cnt] FROM [e10_web_articles] AS pages WHERE 1 ';

	/*
	array_push ($q, 'AND EXISTS (SELECT ndx FROM [e10_base_clsf] WHERE e10_web_pages.ndx = e10_base_clsf.recid AND',
									' e10_base_clsf.tableid = %s ', 'e10.web.pages',
									' AND e10_base_clsf.group = %s AND e10_base_clsf.clsfItem = %i) ', 'webParts', $partNdx);*/

	if (count($articlesSections))
	{
		array_push($q, ' AND articleSection IN %in', $articlesSections);
	}

	if (WebPages::$secureWebPage)
		array_push($q, "AND pages.[docStateMain] != 4");
	else
		array_push ($q, 'AND pages.[docState] IN (4000, 8000) ',
										'AND (pages.[datePub] IS NULL OR pages.[datePub] <= %d)', $today,
										'AND (pages.[dateClose] IS NULL OR pages.[dateClose] >= %d)', $today);

	$cntRow = $app->db()->query ($q)->fetch();
	$totalArticles = $cntRow ['cnt'];

	unset ($q);
	$q[] = 'SELECT pages.*, persons.fullName as authorFullName, persons.firstName as authorFirstName, persons.lastName as authorLastName,';
	array_push ($q, ' attCoverImages.path AS coverImagePath, attCoverImages.fileName AS coverImageFileName');
	array_push ($q, ' FROM [e10_web_articles] AS pages');
	array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON pages.author = persons.ndx ');
	array_push ($q, ' LEFT JOIN e10_attachments_files AS attCoverImages ON pages.coverImage = attCoverImages.ndx');
	array_push ($q, ' WHERE 1');

	if (count($articlesSections))
	{
		array_push($q, ' AND articleSection IN %in', $articlesSections);
	}


	if (WebPages::$secureWebPage)
		array_push ($q, "AND pages.[docStateMain] != 4 ");
	else
		array_push ($q, 'AND pages.[docState] IN (4000, 8000) ',
								'AND (pages.[datePub] IS NULL OR pages.[datePub] <= %d)', $today,
								'AND (pages.[dateClose] IS NULL OR pages.[dateClose] >= %d)', $today);

	array_push ($q, 'ORDER BY pages.[onTop] DESC, [datePub] DESC, [ndx] DESC', " LIMIT $firstRecord, $pageSize");

	// -- page buttons
	if (WebPages::$secureWebPage)
	{
		$tableSections = $app->table('e10.web.articlesSections');
		$usersSections = $tableSections->usersSections();
		foreach ($usersSections as $usNdx => $us)
		{
			if (!in_array($usNdx, $articlesSections))
				continue;
			$bt = 'Nový článek';
			if (count($articlesSections) > 1)
				$bt .= ': '.$us['sn'];
			$b = ['text' => $bt, 'icon' => 'system/actionOpen',
				'attr' => [
					['k' => 'table', 'v' => 'e10.web.articles'],
					['k' => 'action', 'v' => 'new'],
					['k' => 'addParams', 'v' => '__articleSection=' . $usNdx]
				]
			];
			$page ['buttons'][] = $b;
		}
	}

	$rows = $app->db()->query ($q);
	forEach ($rows as $a)
	{
		$as = $app->cfgItem ('e10.web.articlesSections.'.$a['articleSection']);

		$article = [];
		$article ['ndx'] = $a ['ndx'];
		$article ['title'] = $a ['title'];
		$article ['tableId'] = 'e10.web.articles';

		$texy->setOwner ($article);

		$articleText = $a ['text'];
		if ($as['addGallery'])
		{
			$articleText .= '{{articleImage;max:6}}';
		}

		$article ['text'] = $template->renderPagePart('content', $texy->process ($articleText));
		$article ['perex'] = $template->renderPagePart('content', $texy->process ($a ['perex']));

		$article ['url'] = $app->urlRoot.$urlBegin.'/'.$a['ndx'];
		$article ['authorFullName'] = $a ['authorFullName'];
		$article ['authorFirstName'] = $a ['authorFirstName'];
		$article ['authorLastName'] = $a ['authorLastName'];
		$article ['datePub'] = \E10\df ($a['datePub'], '%D');
		$article ['article'] = 1;

		$article ['section'] = $as;

		if ($a['coverImagePath'])
		{
			$article ['coverImagePath'] = $a['coverImagePath'].$a['coverImageFileName'];
		}

		$page ['articles'][] = $article;
	}

	$thisUrl = $app->urlRoot . $app->requestPath ();
	$page ['pager'] = "celkem: $totalArticles" .
												"<a href='$thisUrl'>Starší články</a>";

	$needPager = 0;
	if ($firstPage > 0)
	{
		$pg = $firstPage - 1;
		$page ['pagePrev'] = "$thisUrl?p=$pg";
		$needPager = 1;
	}

	if ($firstRecord + $pageSize < $totalArticles)
	{
		$pg = $firstPage + 1;
		$page ['pageNext'] = "$thisUrl?p=$pg";
		$needPager = 1;
	}


	$page ['needPagination'] = $needPager;


	if (count ($page ['articles']) == 0)
	{
		unset ($page ['articles']);
		unset ($page ['subTemplate']);

		$page ['title'] = 'Článek nebyl nalezen';
		$page ['text'] = 'Nebyl nalezen žádný článek.';
	}

	return $page;
}

function webArticlesFeed ($app, $articlesSections, $format, $urlBegin)
{
	$template = new WebTemplateMustache ($app);
	$template->webEngine = WebPages::$engine;
	$template->loadTemplate ('e10pro.templates.basic', 'page.mustache');

	$page = [];
	$page ['mimeType'] = 'application/atom+xml';
//	$page ['title'] = $webPart ['name'];
	$page ['params'] = [];
	$page ['articles'] = [];

	$page ['forceSubtemplate'] = 'e10.web.articlesAtom';
	$page ['tableId'] = 'e10.web.pages';
	$page ['params'] = [];

	$page ['feedUrl'] = $app->urlProtocol.$_SERVER['HTTP_HOST'].$app->urlRoot.'/'.$urlBegin.'/' . $format;
	$page ['articlesUrl'] = $app->urlProtocol.$_SERVER['HTTP_HOST'].$app->urlRoot.'/'.$urlBegin;
	$page ['checkSum'] = md5($page ['feedUrl']);

	$texy = new E10Texy($app, $page);
	$texy->headingModule->top = 3;

	$pageSize = 20;

	$today = utils::today('Y-m-d');

	$q = [];
	array_push ($q, 'SELECT pages.*, persons.fullName as authorFullName, persons.firstName as authorFirstName, persons.lastName as authorLastName');
	array_push ($q, ' FROM [e10_web_articles] AS pages ');
	array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON pages.author = persons.ndx ');

/*
	array_push ($q, 'AND EXISTS (SELECT ndx FROM [e10_base_clsf] WHERE pages.ndx = e10_base_clsf.recid AND ',
									' e10_base_clsf.tableid = %s', 'e10.web.pages', ' AND e10_base_clsf.group = %s', 'webParts',
									' AND e10_base_clsf.clsfItem = %i) ', $partNdx);
*/

	if (count($articlesSections))
	{
		array_push($q, ' AND articleSection IN %in', $articlesSections);
	}


	if (WebPages::$secureWebPage)
		array_push ($q, 'AND pages.[docStateMain] != 4 ');
	else
		array_push ($q, 'AND pages.[docState] IN (4000, 8000) ',
										'AND (pages.[datePub] IS NULL OR pages.[datePub] <= %d)', $today,
										'AND (pages.[dateClose] IS NULL OR pages.[dateClose] >= %d)', $today);

	array_push ($q, 'ORDER BY pages.[onTop] DESC, [datePub] DESC, [ndx] DESC', " LIMIT 0, $pageSize");

	$rows = $app->db()->query ($q);
	forEach ($rows as $a)
	{
		$article = array();
		$article ['ndx'] = $a ['ndx'];
		$article ['title'] = $a ['title'];
		$article ['tableId'] = 'e10.web.articles';
		$texy->setOwner ($article);

		$article ['text'] = $texy->process ($a ['text']);
		$article ['textHtmlEncoded'] = $template->render($article['text']);

		$article ['url'] = $app->urlProtocol.$_SERVER['HTTP_HOST'].$app->urlRoot.$urlBegin.'/'.$a ['ndx'];
		$article ['authorFullName'] = $a ['authorFullName'];
		$article ['authorFirstName'] = $a ['authorFirstName'];
		$article ['authorLastName'] = $a ['authorLastName'];
		$article ['datePub'] = \E10\df ($a['datePub'], '%D');
		$article ['datePubISO'] = $a['datePub']->format('Y-m-d').'T00:00:01Z';
		$article ['checkSum'] = md5($article ['text']);

		$page ['articles'][] = $article;

		if (!isset ($page ['feedDateUpdated']))
			$page ['feedDateUpdated'] = $a['datePub']->format('Y-m-d').'T00:00:01Z';
	}

	return $page;
}

function latestNews ($app, $params)
{
	$varName = \E10\searchParam ($params, 'var', 'latestNews');
	unset ($params ['owner']->data[$varName]);

	if (isset ($params['dateVar']))
		$today = $params ['owner']->getVar($params['dateVar']);
	else
		$today = utils::today();

	$news = new \E10\Web\TableNews ($app);
	$q[] = 'SELECT news.*, ';
	array_push($q, 'attPerexIllustrations.path AS perexIllustrationPath, attPerexIllustrations.fileName AS perexIllustrationFileName');
	array_push($q, ' FROM [e10_web_news] as news');
	array_push($q, ' LEFT JOIN e10_attachments_files AS attPerexIllustrations ON news.perexIllustration = attPerexIllustrations.ndx');

	array_push($q, ' WHERE (([date_from] IS NULL OR [date_from] <= %d) AND ([date_to] IS NULL OR [date_to] >= %d))', $today, $today);

	$printParam = \E10\searchParam ($params, 'print', FALSE);
	$topParam = \E10\searchParam ($params, 'top', FALSE);
	$cntParam = \E10\searchParam ($params, 'cnt', 5);

	if ($printParam !== FALSE)
	{
		if ($printParam)
			array_push($q, ' AND [to_paper_docs] = 1');
		else
			array_push($q, ' AND [to_paper_docs] = 0');
	}

	if ($topParam !== FALSE)
	{
		if ($topParam)
			array_push($q, ' AND [to_top] = 1');
		else
			array_push($q, ' AND [to_top] = 0');
	}

	if (WebPages::$secureWebPage)
		array_push ($q, 'AND news.[docStateMain] != 4 ');
	else
		array_push($q, ' AND news.[docStateMain] = 2');

	array_push ($q, ' ORDER BY [to_top] DESC, [order], [ndx] DESC', " LIMIT 0, $cntParam");

	$rows = $news->fetchAll ($q);
	forEach ($rows as $r)
	{
		$page = $r->toArray ();
		$page ['tableId'] = 'e10.web.news';
		renderPage ($app, $page, $params);

		$newsItem = [
			'title' => $r ['title'], 'date_from' => $r ['date_from'], 'url' => $r ['url'],
			'htmlText' => $page ['html'], 'htmlPerex' => $page ['htmlPerex'], 'htmlPaperDoc' => $page ['htmlPaperDoc'],
			'perexIllustrationNdx' => $r['perexIllustration'], 'perexIllustrationFileName' => $r['perexIllustrationPath'].$r['perexIllustrationFileName']
		];

		if ($r['to_top'])
			$params ['owner']->data[$varName]['top'][] = $newsItem;
		else
			$params ['owner']->data[$varName]['normal'][] = $newsItem;
		$params ['owner']->data[$varName]['all'][] = $newsItem;
	}
	return '';
}

/**
 * @param $app
 * @param $params
 * @return string
 */
function latestArticles ($app, $params)
{
	unset ($params ['owner']->data['latestArticles']);

	$partParam = \E10\searchParam ($params, 'part', '1');
	$cntParam = \E10\searchParam ($params, 'cnt', 5);

	$sectionNdx = intval($partParam);
	if (!$sectionNdx)
		return '';

	$today = utils::today('Y-m-d');

	$q[] = 'SELECT pages.*, persons.fullName as authorFullName, persons.firstName as authorFirstName, persons.lastName as authorLastName ';
	array_push ($q, ' FROM [e10_web_articles] AS pages LEFT JOIN e10_persons_persons AS persons ON pages.author = persons.ndx WHERE 1');

	array_push($q, ' AND articleSection = %i', $sectionNdx);

	if (WebPages::$secureWebPage)
		array_push ($q, 'AND pages.[docStateMain] != 4 ');
	else
		array_push ($q, 'AND pages.[docState] IN (4000, 8000) ',
			'AND (pages.[datePub] IS NULL OR pages.[datePub] <= %d)', $today,
			'AND (pages.[dateClose] IS NULL OR pages.[dateClose] >= %d)', $today);

	array_push ($q, 'ORDER BY pages.[onTop] DESC, [datePub] DESC, [ndx] DESC', " LIMIT 0, $cntParam");

	$rows = $app->db()->query ($q);
	forEach ($rows as $a)
	{
		$article = [];
		$article ['ndx'] = $a ['ndx'];
		$article ['title'] = $a ['title'];

		$article ['url'] = $app->urlRoot . '/' . $a ['ndx']; // !!!!!!
		$article ['authorFullName'] = $a ['authorFullName'];
		$article ['authorFirstName'] = $a ['authorFirstName'];
		$article ['authorLastName'] = $a ['authorLastName'];
		$article ['datePub'] = \E10\df ($a['datePub'], '%D');

		$params ['owner']->data['latestArticles'][] = $article;
	}
	return '';
}


function galleryImages ($app, $params)
{
	$varName = 'gimgs';

	unset ($params ['owner']->data[$varName]);

	$cntParam = \E10\searchParam ($params, 'cnt', 30);
	if ($cntParam > 300)
		$cntParam = 300;

	$q[] = 'SELECT att.* FROM [e10_attachments_files] AS att ';

	array_push($q, ' WHERE 1');
	array_push($q, ' AND att.[deleted] = 0');
	array_push ($q, 'AND [filetype] IN %in', ['jpg', 'jpeg', 'png', 'gif', 'svg']);

	array_push ($q, 'AND (');
	array_push ($q,
			' EXISTS (SELECT ndx FROM e10pro_wkf_documents WHERE att.recid = ndx AND tableId = %s', 'e10pro.wkf.documents',
			' AND [type] = %s', 'gallery', 'AND [webShare] = 1', ' AND e10pro_wkf_documents.docStateMain <= 2',
			')');
	array_push ($q, ')');

	array_push ($q, 'ORDER BY RAND()');
	array_push($q, ' LIMIT %i', $cntParam);

	$rows = $app->db()->query ($q);

	forEach ($rows as $r)
	{
		$img = [];

		$img['fileName'] = $r['path'].$r['filename'];

		$params ['owner']->data[$varName][] = $img;
	}
	return '';
}

