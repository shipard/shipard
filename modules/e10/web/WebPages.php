<?php

namespace e10\web;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';


use \e10\utils, \e10\json;
use e10pro\kb\WikiEngine;


/**
 * Class WebPages
 * @package e10\web
 */
class WebPages extends \E10\utility
{
	const wptWebSecure = 1, wptSystemLogin = 3, wptWeb = 4, wptExtranet = 5, wptWiki = 6;
	const urlDecorationLeftColumn = 0, urlDecorationRightColumn = 1, urlDecorationFooterExtended = 2,
				urlDecorationFooterFull = 3, urlDecorationHeaderExt = 4, urlDecorationHeaderFull = 5, urlDecorationJavascript = 6;
	var $webPageType = 0;
	protected $page = NULL;
	var $serverInfo = NULL;
	protected $forceTemplate = FALSE;
	static $secureWebPage = FALSE;
	static $engine = FALSE;

	var $userCookieId = '';
	var $userRequestNdx = 0;

	var $template = NULL;

	var $authType = 0;
	var $loginRequired = 0;
	var $webUserNdx = 0;
	var $webUserInfo = NULL;
	/** @var \e10\web\webAuthenticator */
	var $authenticator = NULL;

	public function checkUserId ($create = FALSE)
	{
		$this->userCookieId = $this->app->testCookie ('e10-userId');
		if ($this->userCookieId === '' && $create)
		{
			$dateCreated = new \DateTime();
			$dateValid = new \DateTime();
			$dateValid->add (new \DateInterval('P1Y'));

			$requestId = sha1 (mt_rand(100000, 99999999) . time() . mt_rand(12345678, 987654321) . mt_rand());
			$requestRec = ['requestType' => 5, 'subject' => 'Nepřihlášený uživatel', 'requestId' => $requestId,
				'addressCreate' => $_SERVER ['REMOTE_ADDR'], 'created' => $dateCreated, 'validTo' => $dateValid,
				'docState' => 1000, 'docStateMain' => 0];
			$this->db()->query ("INSERT INTO [e10_persons_requests]", $requestRec);
			$this->userRequestNdx = intval ($this->db()->getInsertId ());
			$this->userCookieId = $this->userRequestNdx.'-'.$requestId;

			if ($this->app->https)
				setCookie ('e10-userId', $this->userCookieId, time() + 999 * 86400, $this->app->urlRoot . "/", $_SERVER['HTTP_HOST'], $this->app->https, 1);
			else
				setCookie ('e10-userId', $this->userCookieId, time() + 999 * 86400, $this->app->urlRoot . "/", '', 0, 1);
		}
		else
		if ($this->userCookieId !== '')
		{
			$parts = explode ('-', $this->userCookieId);
			$this->userRequestNdx = intval($parts[0]);
		}

		return $this->userCookieId;
	}

	function checkLogin()
	{
		$url = $this->app->requestPath();

		if ($url === '/robots.txt')
			return $this->getSystemPageRobotsTxt();
		elseif ($url === '/manifest.webmanifest')
			return $this->getSystemPageWebManifest();

		// login disabled
		if ($this->authType === 0)
		{
			return NULL;
		}

		// -- login via application
		if ($this->authType === 2)
		{
			//if ($this->app->authenticator->isSystemPage())
			//	return;
			/*
			if ($this->loginRequired === 0)
			{ // optional
				return;
			}

			if ($this->loginRequired === 1)
			{ // required
				if (!$this->app()->userNdx())
				{
					$loginPath = "/" . $this->app->appSkeleton['userManagement']['pathBase'] . "/" . $this->app->appSkeleton['userManagement']['pathLogin'];
					$fromPath = implode ('/', $this->app->requestPath);
					header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST']. $this->app->urlRoot . $loginPath . "/" . $fromPath);
					die();
				}
				return;
			}

			return;
			*/

			return NULL;
		}

		if (!$this->authenticator)
		{
			$this->authenticator = new \e10\web\WebAuthenticator($this->app());
			$this->authenticator->init($this);
		}

		return $this->authenticator->doIt();
/*
		// -- login as web user
		if ($this->loginRequired === 0)
		{ // optional

			return;
		}

		if ($this->loginRequired === 1)
		{ // required

			return;
		}
*/
	}

	function callWebAction()
	{
		$error = 0;
		$params = NULL;
		$dataStr = $this->app()->postData();
		if ($dataStr === '')
			$error = 1;
		else
		{
			$params = json_decode($dataStr, TRUE);
			if (!$params)
				$error = 1;
		}
		if ($error || !$params)
		{
			$page = ['code' => '{"success": 0}', 'mimeType' => 'application/json', 'status' => 404];
			return $page;
		}

		$actionId = isset($params['action']) ? $params['action'] : '---';
		$actionClassId = $this->app()->cfgItem ('registeredClasses.webAction.'.$actionId.'.classId', FALSE);

		if ($actionClassId === FALSE)
		{
			$page = ['code' => '{"success": 0}', 'mimeType' => 'application/json', 'status' => 404];
			return $page;
		}

		$actionObject = $this->app()->createObject($actionClassId);
		$actionObject->setParams($params);
		$actionObject->run ();

		$page = ['code' => json_encode($actionObject->result), 'mimeType' => 'application/json', 'status' => 200];
		return $page;
	}

	function run ()
	{
		webPages::$engine = $this;
		if ($this->app->webEngine === NULL)
			$this->app->webEngine = $this;

		switch ($this->webPageType)
		{
			case webPages::wptExtranet:
				$this->createExtranetPage();
				break;
			case webPages::wptSystemLogin:
			default:
				$this->createWebPage();
				break;
		}

		if ($this->page)
		{
			if (!isset ($this->page ['status']))
				$this->page ['status'] = 200;

			if (isset($this->page['code']))
				return $this->page;
		}
		else
			$this->page = array ('text' => "Stránka nebyla nalezena.", 'title' => 'Stránka neexistuje', 'status' => 404);

		$this->page ['themeRoot'] = $this->template->urlRoot;

		if (isset($this->page ['text']))
			$this->page ['text'] = $this->template->renderPagePart('content', $this->page ['text']);

		if (isset($this->page ['textLeftSidebar']))
			$this->page ['textLeftSidebar'] = $this->template->renderPagePart ('content', $this->page ['textLeftSidebar']);
		if (isset($this->page ['textRightSidebar']))
			$this->page ['textRightSidebar'] = $this->template->renderPagePart ('content', $this->page ['textRightSidebar']);

		if (isset ($this->template->page ['articleTitle']))
		{
			$pageTitle = isset ($this->serverInfo['title']) ? $this->serverInfo['title'] : '';
			if ($pageTitle != '')
				$pageTitle .= ' | ';
			$pageTitle .= $this->template->page ['articleTitle'];
			$this->page ['pageTitle'] = $pageTitle;
		}

		$this->template->setPage ($this->page);
		if (isset ($this->template->data ['forceCode']))
		{
			$this->page ['code'] = $this->template->data ['forceCode'];
			if (isset ($this->template->data ['forceMimeType']))
				$this->page ['mimeType'] = $this->template->data ['forceMimeType'];
		}
		elseif (isset ($this->page ['forceSubtemplate']))
		{
			$c = $this->template->renderSubtemplate ($this->page ['forceSubtemplate']);
			$this->page ['code'] = $c;
		}
		else
		{
			$c = $this->template->renderTemplate();
			$this->page ['code'] = $c;
		}
		if (isset ($this->template->data ['forceStatus']))
			$this->page ['status'] = $this->template->data ['forceStatus'];

		while(1)
		{
			$begin = strpos($this->page['code'], '[[[!!!base64decode!!!:');
			if ($begin === FALSE)
				break;
			$end = strpos($this->page['code'], '!!!]]]', $begin);
			if ($end === FALSE)
				break;

			$newCode = substr($this->page['code'], 0, $begin);
			$b64 = substr($this->page['code'], $begin + 22, $end - $begin - 22);//22
			$newCode .= base64_decode($b64);
			//$newCode .= $b64;
			$newCode .= substr($this->page['code'], $end + 6);
			$this->page['code'] = $newCode;
		}

		return $this->page;
	}

	function createExtranetPage ()
	{
		$this->loadTemplate();

		$functionName = $this->serverInfo['function'];
		if ($functionName)
		{
			$params = array ('owner' => $this->template);
			$this->page = $this->app->callFunction ($functionName, $params);
		}

		// -- system page
		if (!$this->page)
			$this->page = $this->getSystemPage ();

		if ($this->page)
		{
			$this->page ['pageTitle'] = $this->app->cfgItem ('options.core.ownerShortName', '').' | ' . $this->page ['title'];
		}
	}

	function createWebPage ()
	{
		$fullUrl = $this->app->requestPath ();

		$this->loadTemplate();

		$this->page = $this->checkLogin();

		if ($fullUrl === '/call-web-action')
		{
			$this->page = $this->callWebAction();
		}

		// -- system page
		if (!$this->page)
			$this->page = $this->getSystemPage ();

		if ($this->app->requestPath [0] === 'imgs')
		{
			return \e10\getImage ($this->app());
		}

		if (!$this->page)
		{
			if ($this->serverInfo['hpFunction'] !== '' && $this->app->requestPath(0) !== $this->app->appSkeleton['userManagement']['pathBase'])
				$functionName = $this->app->cfgItem('registeredFunctions.' . 'weburl' . '.' . $this->serverInfo['hpFunction'], FALSE);
			else
				$functionName = $this->app->cfgItem('registeredFunctions.' . 'weburl' . '.' . $this->app->requestPath(0), FALSE);
			if ($functionName)
			{
				$params = ['owner' => $this->template, 'serverInfo' => $this->serverInfo];
				$this->page = $this->app->callFunction($functionName, $params);
			}
		}

		// -- static web page
		if (!$this->page && $this->serverInfo)
			$this->page = $this->getWebPage ($fullUrl);

		if ($this->page)
		{
			if (!isset ($this->page ['status']))
				$this->page ['status'] = 200;
			if ($this->serverInfo)
				$this->page ['serverInfo'] = $this->serverInfo;

			$this->page['url_'.$this->app->requestPath (0)] = 1;
			$this->page['url_'.$this->app->requestPath (0).'_'.$this->app->requestPath (1)] = 1;

			if (!isset($this->page['bodyClasses']))
				$this->page['bodyClasses'] = '';
			$this->page['bodyClasses'] .= ' page_url_'.$this->app->requestPath (0);
			$this->page['bodyClasses'] .= ' page_url_'.$this->app->requestPath (0).'_'.$this->app->requestPath (1);
		}
		else
		{
			$this->page ['text'] = "Stránka nebyla nalezena.";
			$this->page ['title'] = 'Stránka neexistuje';
			$this->page ['status'] = 404;
		}

		if (isset($this->serverInfo['ndx']))
			$this->page['server_'.$this->serverInfo['ndx']] = 1;
		else
			$this->page['server_ns'] = 1;

		if (isset($this->serverInfo['bodyClasses']))
			$this->page['bodyClasses'] .= ' '.$this->serverInfo['bodyClasses'];


		if (isset ($this->page['code']))
			return $this->page;

		// --resolve url decorations
		$url = '/';
		$urlLevel = 0;
		$decorations = NULL;
		if ($this->serverInfo && isset($this->serverInfo['ndx']))
			$decorations = $this->app->cfgItem ('e10.web.urlDecorations.'.$this->serverInfo['ndx'], NULL);
		while ($decorations != NULL)
		{
			if (isset($decorations [$url]))
			{
				forEach ($decorations [$url] as $udc)
				{
					if (!$udc['thisUrl'] && $url === $fullUrl)
						continue;
					if (!$udc['subUrls'] && $url !== $fullUrl)
						continue;

					$did = '';

					switch ($udc['type'])
					{
						case self::urlDecorationLeftColumn:
							$did = 'textLeftSidebar'; break;
						case self::urlDecorationRightColumn:
							$did = 'textRightSidebar'; break;
						case self::urlDecorationFooterExtended:
							$did = 'footerExtension'; break;
						case self::urlDecorationFooterFull:
							$did = 'footerFull'; break;
						case self::urlDecorationHeaderExt:
							$did = 'headerExt'; break;
						case self::urlDecorationHeaderFull:
							$did = 'headerFull'; break;
						case self::urlDecorationJavascript:
							$did = 'javascript'; break;
					}

					if (!isset ($this->page [$did]))
					{
						$this->page [$did] = '';
						$this->page['bodyClasses'] .= ' page-with-'.$did;
					}
					if (isset($udc['st']) && $udc['st'])
					{
						$this->page [$did] .= $this->template->render($udc ['text']);
					}
					else
					{
						$this->page [$did] .= $udc ['text'];
					}
				}
			}
			$thisUrl = $this->app->requestPath ($urlLevel);
			if ($thisUrl == '')
				break;
			if ($urlLevel != 0)
				$url .= '/';
			$url .= $thisUrl;
			$urlLevel++;
		}

		// -- set sidebars
		if (isset ($this->page ['textLeftSidebar']) && isset ($this->page ['textRightSidebar']))
		{
			$this->page ['layout'] = 'sidebarBoth';
			$this->page ['layoutSidebarBoth'] = true;
		}
		elseif (isset ($this->page ['textLeftSidebar']))
		{
			$this->page ['layout'] = 'sidebarLeft';
			$this->page ['layoutSidebarLeft'] = true;
		}
		elseif (isset ($this->page ['textRightSidebar']))
		{
			$this->page ['layout'] = 'sidebarRight';
			$this->page ['layoutSidebarRight'] = true;
		}

		$pageTitle = isset ($this->serverInfo['title']) ? $this->serverInfo['title'] : '';
		if ($this->page ['title'] != '')
		{
			if ($pageTitle != '')
				$pageTitle .= ' | ';
			if (isset ($this->page ['webTitle']))
				$pageTitle .= $this->page ['webTitle'];
			else
				$pageTitle .= $this->page ['title'];
		}

		$this->page ['pageTitle'] = $pageTitle;

		if (!isset ($this->page ['layout']))
		{
			$this->page ['layout'] = 'sidebarNone';
			$this->page ['layoutSidebarNone'] = true;
		}
	}

	function getSystemPage ()
	{
		if ($this->authType === 2 && $this->app->authenticator)
			return $this->app->authenticator->getSystemPage ();

		if ($this->authType === 1 && $this->authenticator)
			return $this->authenticator->getSystemPage();

		return NULL;
	}

	function getSystemPageRobotsTxt()
	{
		$code = '';
		$code .= "User-agent: *\n";

		if ($this->loginRequired)
			$code .= "Disallow: /\n";
		else
		$code .= "Disallow: \n";

		$page = [];
		$page ['code'] = $code;
		$page ['status'] = 200;
		$page ['mimeType'] = 'text/plain';

		return $page;
	}

	function getSystemPageWebManifest()
	{
		$wm = [
			'name' => $this->serverInfo['fn'],
			'short_name' => $this->serverInfo['sn'],
			'start_url' => '/',
			'display' => ($this->serverInfo['mode'] === 'display') ? 'fullscreen' : 'standalone',
			'background_color' => $this->serverInfo['themeColor'],
			'theme_color' => $this->serverInfo['themeColor'],
			'scope' => '/',
			'icons' => [],
		];

		$urlKeyParam = $this->app()->testGetParam ('k');
		if ($urlKeyParam !== '')
			$wm['start_url'] = '/user/k/'.$urlKeyParam;

		$icon = utils::cfgItem($this->serverInfo, 'images.web.icon', FALSE);
		if ($icon)
		{
			$wm['icons'][] = ['src' => "{$this->app->dsRoot}/imgs/-w256{$icon}", 'sizes' => '256x256', 'type' => 'image/png'];
			if (substr($icon, -4, 4) === '.svg')
			{
				$wm['icons'][] = ['src' => "{$this->app->dsRoot}{$icon}", 'type' => 'image/svg+xml'];
				$wm['icons'][] = ['src' => "{$this->app->dsRoot}/imgs/-w512{$icon}", 'sizes' => '512x512', 'type' => 'image/png'];
				$wm['icons'][] = ['src' => "{$this->app->dsRoot}/imgs/-w1024{$icon}", 'sizes' => '1024x1024', 'type' => 'image/png'];
			}
		}

		$code = json::lint($wm);

		$page = [];
		$page ['code'] = $code;
		$page ['status'] = 200;
		$page ['mimeType'] = 'application/manifest+json';

		return $page;
	}

	function getWebPage ($url)
	{
		$serverPages = $this->app()->cfgItem ('e10.web.pages.'.$this->serverInfo['ndx'], NULL);

		$isForcedUrl = 0;

		$q[] = 'SELECT * FROM [e10_web_pages] WHERE 1';
		array_push ($q, ' AND [server] = %i', $this->serverInfo['ndx']);
		array_push ($q, ' AND [url] = %s', $url);
		array_push ($q, ' AND [docStateMain] != 4 ');

		$page = $this->app->db()->query ($q)->fetch ();

		if (!$page && $serverPages)
		{
			$isForcedUrl = 0;
			$forcedUrl = '/';
			for ($i = 0; $i < count($this->app()->requestPath); $i++)
			{
				if (in_array($forcedUrl, $serverPages['forcedUrls']))
				{
					$isForcedUrl = 1;
					break;
				}
				if (!str_ends_with($forcedUrl, '/'))
					$forcedUrl .= '/';
				$forcedUrl .= $this->app()->requestPath[$i];
			}
			if ($isForcedUrl)
			{
				$q = [];
				$q[] = 'SELECT * FROM [e10_web_pages] WHERE 1';
				array_push($q, ' AND [server] = %i', $this->serverInfo['ndx']);
				array_push($q, ' AND [url] = %s', $forcedUrl);
				array_push($q, ' AND [docStateMain] != 4 ');
				$page = $this->app->db()->query($q)->fetch();
			}
		}

		if ($page)
		{
			$article = [];
			$article ['ndx'] = $page ['ndx'];
			$article ['title'] = $page ['title'];
			$article ['tableId'] = 'e10.web.pages';
			$article ['params'] = [];

			$text = $page['text'];

			if ($this->webPageType === self::wptWebSecure)
			{
				$addParams = '__server='.$page['server'];
				$article ['bodyEditParams'] = " data-table='e10.web.pages' data-pk='{$page ['ndx']}' data-addparams='$addParams'";
			}

			if ($page['pageMode'] === 'articles')
			{
				$articlesSection = [];
				$pmpParts = explode (' ', $page['pageModeParams']);
				foreach ($pmpParts as $pmp)
					$articlesSection[] = intval($pmp);
				$pp = webArticle ($this->app, $articlesSection, $this->template, ($isForcedUrl) ? $forcedUrl : $url);

				if ($this->webPageType === self::wptWebSecure)
				{
					$addParams = '__server='.$page['server'];
					$pp ['bodyEditParams'] = " data-table='e10.web.pages' data-pk='{$page ['ndx']}' data-addparams='$addParams'";
				}

				if (!isset($pp['title']))
					$pp['title'] = $page ['title'];

				$pp['mainTextClass'] = 'pageArticles';

				// -- page properties (left/right column)
				$pageProperties = \E10\Base\getProperties ($this->app, 'e10.web.pages', 'props', $page['ndx']);

				if (isset ($pageProperties ['sideBarLeft']) && $pageProperties ['sideBarLeft'][0]['value'] != '')
					$pp ['textLeftSidebar'] = $pageProperties ['sideBarLeft'][0]['value'];
				if (isset ($pageProperties ['sideBarRight']) && $pageProperties ['sideBarRight'][0]['value'] != '')
					$pp ['textRightSidebar'] = $pageProperties ['sideBarRight'][0]['value'];

				return $pp;
			}
			elseif ($page['pageMode'] === 'wiki')
			{
				$pageId = $this->app()->requestPath ($page['treeLevel'] + 1);
				//$this->template->page['params']['myLayout'] = '1';
				//$this->template->pageParams['myLayout'] = '1';

				$this->template->page['pageModeWiki'] = '1';

				$wikiEngine = new WikiEngine ($this->app());
				$wikiEngine->urlBegin = $this->app()->urlRoot.(($isForcedUrl) ? $forcedUrl : $url).'/';
				$wikiEngine->wikiNdx = $page['wiki'];
				$wikiEngine->setPageId($pageId, $this->template);
				$wikiEngine->run();
				$article = [
					'pageType' => $wikiEngine->page['pageType'],
					'title' => $wikiEngine->page['title'],
					'text' => $wikiEngine->page['code'],
					'status' => $wikiEngine->status,
					//'params' => ['myLayout' => '1'],
					'bodyClasses' => 'e10-edit-no-iframe',
					'pageModeWiki' => 1
				];

				return $article;
			}
			elseif ($page['pageMode'] == 'textPlain')
			{
				$article = [
					'code' => $text,
					'mimeType' => 'text/plain',
					'status' => 200,
				];

				return $article;
			}

			$texy = new E10Texy($this->app, $article, TRUE);
			$texy->template = $this->template;
			$article ['text'] = $texy->process ($text);

			// -- page properties (left/right column)
			$pageProperties = \E10\Base\getProperties ($this->app, 'e10.web.pages', 'props', $page['ndx']);

			if (isset ($pageProperties ['sideBarLeft']) && $pageProperties ['sideBarLeft'][0]['value'] != '')
				$article ['textLeftSidebar'] = $pageProperties ['sideBarLeft'][0]['value'];
			if (isset ($pageProperties ['sideBarRight']) && $pageProperties ['sideBarRight'][0]['value'] != '')
				$article ['textRightSidebar'] = $pageProperties ['sideBarRight'][0]['value'];

			$article['mainTextClass'] = 'pageText';
			return $article;
		}
		return NULL;
	}

	function loadTemplate ()
	{
		$templateType = 'page';

		if ($this->webPageType === webPages::wptSystemLogin)
		{
			$webTemplateId = 'app.system';
		}
		else
		if ($this->webPageType === webPages::wptExtranet)
		{
			$webTemplateId = $this->serverInfo['templateId'];
		}
		else
		{
			$webTemplateId = (isset($this->serverInfo['template']) && $this->serverInfo['template'] !== '') ? $this->serverInfo['template'] : 'web.core-bs5';
			if ($this->forceTemplate === FALSE)
			{
				if (isset($this->serverInfo['templateParams']['defaultTemplateType']))
					$templateType = $this->serverInfo['templateParams']['defaultTemplateType'];
			}
			else
				$webTemplateId = $this->forceTemplate;
		}

		$forceTemplateScript = NULL;
		if (isset($this->serverInfo['templateMainScript']) && $this->serverInfo['templateMainScript'])
		{
			$scriptItem = $this->db()->query('SELECT [ndx], [code] FROM [e10_web_scripts] WHERE [ndx] = %i', $this->serverInfo['templateMainScript'])->fetch();
			if ($scriptItem)
				$forceTemplateScript = $scriptItem['code'];
		}
		$this->template = new WebTemplateMustache ($this->app);
		if (isset($this->serverInfo['templateParams']))
			$this->template->templateParams = $this->serverInfo['templateParams'];
		$this->template->serverInfo = $this->serverInfo;
		$this->template->webEngine = $this;
		$this->template->loadTemplate ($webTemplateId, $templateType.'.mustache', $forceTemplateScript);

		if (isset($this->serverInfo['look']) && $this->serverInfo['look'] !== '' && $this->serverInfo['templateStylePath'] !== '')
		{
			$ver = utils::loadCfgFile(__APP_DIR__.'/'.$this->serverInfo['templateStylePath'].'/'.$this->serverInfo['look'].'-versions.json');
			if ($ver)
				$this->template->serverInfo['lookVersion'] = $ver;
		}

		$this->template->serverInfo['templateVersion'] = isset ($this->template->options['version']) ? $this->template->options['version'] : '0.0';
		$this->template->serverInfo['secureWebPage'] = intval(self::$secureWebPage);
	}

	function setPageType ($pageType)
	{
		$this->webPageType = $pageType;

		switch ($pageType)
		{
			case webPages::wptExtranet:
			case webPages::wptWeb:
				$cntfup = count(explode('/', $this->serverInfo['urlStart']));
				if ($cntfup > 1)
				{
					$cntfup--;
					for ($i = 0; $i < $cntfup; $i++)
					{
						$first = array_shift ($this->app->requestPath);
						$this->app->urlRoot .= '/'.$first;
					}
				}
				break;
			case webPages::wptWebSecure:
			case webPages::wptWiki:
				webPages::$secureWebPage = TRUE;
				$first = array_shift ($this->app->requestPath);
				$second = array_shift ($this->app->requestPath);
				$this->app->urlRoot .= '/'.$first.'/'.$second;
				break;
			case webPages::wptSystemLogin:
				break;
		}
	}

	function setServerInfo ($serverInfo)
	{
		$this->serverInfo = $serverInfo;
		$this->authType = (isset($this->serverInfo['authType'])) ? $this->serverInfo['authType'] : 0;
		$this->loginRequired = (isset($this->serverInfo['loginRequired'])) ? $this->serverInfo['loginRequired'] : 0;
	}

	function webAnalytics ()
	{
		$c = '';

		$gaid = $this->serverInfo['gaid'] ?? '';
		if ($gaid !== '' && !webPages::$secureWebPage)
		{
			$c .= "(function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
				(i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
				m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
				})(window,document,'script','//www.google-analytics.com/analytics.js','ga');

				ga('create', '$gaid', 'auto');
				ga('send', 'pageview');\n";
		}

		$mtmSiteId = $this->serverInfo['mtmSiteId'] ?? '';
		$mtmUrl = $this->serverInfo['mtmUrl'] ?? '';
		if ($mtmSiteId != '' && $mtmUrl !== '' && !webPages::$secureWebPage)
		{
			$c .= "var _paq = window._paq = window._paq || [];
				_paq.push(['trackPageView']);
				_paq.push(['enableLinkTracking']);
				(function() {
					var u='".$mtmUrl."';
					_paq.push(['setTrackerUrl', u+'matomo.php']);
					_paq.push(['setSiteId', '".$mtmSiteId."']);
					var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
					g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
				})();\n";
		}

		if ($c === '')
			return '';

		return '<script>'.$c."</script>\n";
	}

	function googleAnalytics () {return $this->webAnalytics();} // TODO: remove in next versions

	function webManifestUrl ()
	{
		$url = '';

		if (($this->loginRequired && $this->serverInfo['mode'] === 'app') || $this->serverInfo['mode'] === 'display')
		{
			$url = $this->app()->urlRoot.'/manifest.webmanifest';
			if ($this->authenticator && $this->authenticator->session && $this->authenticator->session['loginType'] === 2)
				$url .= '?k='.$this->authenticator->session['loginKeyValue'];
		}

		return $url;
	}

	function headMetaTags ()
	{
		$c = '';

		$webManifestUrl = $this->webManifestUrl();
		if ($webManifestUrl !== '')
			$c .= "<link rel=\"manifest\" href=\"$webManifestUrl\">\n";

		return $c;
	}

	function jsLibs ()
	{
		$scRoot = $this->app()->scRoot();

		$c = '';

		if ($this->serverInfo['mode'] === 'app')
		{
			$useNewWebsockets = 1;

			$wss = $this->app()->webSocketServersNew();
			$c .= "\t<script type=\"text/javascript\">\n";
			$c .= "var remoteHostAddress = '{$_SERVER ['REMOTE_ADDR']}'; e10ClientType = " . json_encode ($this->app()->clientType) . ";\n";
			$c .= "var webSocketServers = ".json_encode($wss).";\n";
			$c .= "var g_useMqtt = {$useNewWebsockets};";
			$c .= "var deviceId = '{$this->app()->deviceId}';";
			$c .= "</script>\n";
			$c .= "<script type=\"text/javascript\" src=\"{$scRoot}/libs/js/mqttws/mqttws31.min.js\"></script>\n";
			if ($this->app->cfgItem('develMode', 0) === 0)
			{ // production
				$files = unserialize (file_get_contents(__SHPD_ROOT_DIR__.'/ui/web-apps/files.data'));
				$c .= "<script type='text/javascript' integrity='{$files['app']['client.js']['integrity']}' src='{$this->app->dsRoot}/www-root/.web-apps/app/js/client.js?v=".$files['app']['client.js']['ver']."'></script>\n";
			}
			else
			{ // development
				$jsFiles = utils::loadCfgFile(__APP_DIR__ . '/www-root/ui-dev/web-apps/app/js/package.json');
				foreach ($jsFiles['srcFiles'] as $sf)
				{
					$checkSum = md5_file(__APP_DIR__."/www-root/ui-dev/web-apps/app/js/{$sf['fileName']}");
					$c .= "<script type=\"text/javascript\" src=\"{$this->app->dsRoot}/www-root/ui-dev/web-apps/app/js/{$sf['fileName']}?v=$checkSum\"></script>\n";
				}
			}
		}
		elseif ($this->serverInfo['mode'] === 'display')
		{
			$useNewWebsockets = 1;

			$wss = $this->app()->webSocketServersNew();
			$c .= "\t<script type=\"text/javascript\">\n";
			$c .= "var remoteHostAddress = '{$_SERVER ['REMOTE_ADDR']}'; e10ClientType = " . json_encode ($this->app()->clientType) . ";\n";
			$c .= "var webSocketServers = ".json_encode($wss).";\n";
			$c .= "var g_useMqtt = {$useNewWebsockets};";
			$c .= "var deviceId = '{$this->app()->deviceId}';";
			$c .= "</script>\n";
			$c .= "<script type=\"text/javascript\" src=\"{$scRoot}/libs/js/mqttws/mqttws31.min.js\"></script>\n";
			if ($this->app->cfgItem('develMode', 0) === 0)
			{ // production
				$files = unserialize (file_get_contents(__SHPD_ROOT_DIR__.'/ui/web-apps/files.data'));
				$c .= "<script type='text/javascript' integrity='{$files['app']['client.js']['integrity']}' src='{$this->app->dsRoot}/www-root/.web-apps/app/js/client.js?v=".$files['app']['client.js']['ver']."'></script>\n";
			}
			else
			{ // development
				$jsFiles = utils::loadCfgFile(__APP_DIR__ . '/www-root/ui-dev/web-apps/app/js/package.json');
				foreach ($jsFiles['srcFiles'] as $sf)
				{
					$checkSum = md5_file(__APP_DIR__."/www-root/ui-dev/web-apps/app/js/{$sf['fileName']}");
					$c .= "<script type=\"text/javascript\" src=\"{$this->app->dsRoot}/www-root/ui-dev/web-apps/app/js/{$sf['fileName']}?v=$checkSum\"></script>\n";
				}
			}
		}

		return $c;
	}
}

