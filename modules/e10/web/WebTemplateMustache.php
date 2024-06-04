<?php

namespace e10\web;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';


use \e10\utils, \e10\web\webPages;
use \e10\base\libs\UtilsBase;

/**
 * Class WebTemplateMustache
 * @package e10\web
 */
class WebTemplateMustache extends \Shipard\Utils\TemplateCore
{
	/** @var  \e10\web\webPages */
	public $webEngine;
	var $webBlocksList = NULL;

	public function googleAnalytics ()	{return $this->webEngine->webAnalytics();}
	public function webAnalytics ()	{return $this->webEngine->webAnalytics();}

	protected function _getVariable($tag_name)
	{
		$tag_name = str_replace('$urlId$', $this->urlId(), $tag_name);
		$tag_name = str_replace('$urlId2$', $this->urlId(2), $tag_name);
		$ret = parent::_getVariable($tag_name);
		return $ret;
	}

	public function newsHtml ()
	{
		return getNews ($this->app, NULL);
	}

	public function newsTop ()
	{ // TODO: remove
		return getNewsArray ($this->app, TRUE);
	}

	public function webBlocks ()
	{
		if ($this->webBlocksList === NULL)
		{
			$table = $this->app->table ('e10.web.blocks');
			$this->webBlocksList = $table->blocksItems();
		}
		return $this->webBlocksList;
	}

	public function webSocketServers ()
	{
		$wss = $this->app->webSocketServersNew();
		return array_values($wss);
	}

	public function pageText ()
	{
		if (isset ($this->page ['text']))
			return $this->page ['text'];
		return FALSE;
	}

	public function textLeftSidebar ()
	{
		if (isset ($this->page ['textLeftSidebar']))
			return $this->page ['textLeftSidebar'];
		return FALSE;
	}

	public function textRightSidebar ()
	{
		if (isset ($this->page ['textRightSidebar']))
			return $this->page ['textRightSidebar'];
		return FALSE;
	}

	public function coverImage ()
	{
		if (isset ($this->page['coverImage']))
			return $this->page['coverImage'];
		$this->page['coverImage'] = FALSE;

		if (isset ($this->page['tableId']) && isset ($this->page['ndx']))
		{
			$image = UtilsBase::getAttachmentDefaultImage ($this->app, $this->page['tableId'], $this->page['ndx']);
			if (isset ($image['fileName']))
				$this->page['coverImage'] = $image;
		}

		return $this->page['coverImage'];
	}

	function resolveCmd ($tagCode, $tagName, $params)
	{
		$methodName = 'resolveCmd_'.$tagName;
		if (method_exists ( $this, $methodName))
			return $this->$methodName($params);

		return parent::resolveCmd($tagCode, $tagName, $params);
	}

	function resolveCmd_Redirect ($params)
	{
		$url = $params['to'];

		if ($url !== '' && substr($url, 0, 4) !== 'http')
			$url = $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . $url;
		if ($url !== '')
		{
			header ('Location: ' . $url);
			die();
		}

		return '';
	}

	public function menu ()
	{
		if ($this->app->cfgItem ('loginRequired', 0) && !$this->app->user->isAuthenticated ())
			return '';

		$menuCfgKey = 'e10.web.menu.'.$this->webEngine->serverInfo['ndx'];
		if ($this->webEngine->webPageType === webPages::wptSystemLogin)
			return '';

		$srcMenu = $this->app->cfgItem ($menuCfgKey, NULL);
		$menu = [];
		$this->menuAdd($srcMenu, $menu, 0);

		return $menu;
	}

	function activeWebMenu ($menu)
	{
		$m = $menu;
		$subMenu = $m;

		$thisUrl = '';
		$maxLevel = count($this->app->requestPath) - 1;

		for ($ii = 0; $ii < $maxLevel; $ii++)
		{
			$thisUrl .= '/'.$this->app->requestPath ($ii);
			$subMenu = utils::searchArray ($subMenu['items'], 'url', $thisUrl);
		}

		return $subMenu;
	}

	public function activeMenu ()
	{
		$menuCfgKey = 'e10.web.menu.'.$this->webEngine->serverInfo['ndx'];

		$srcMenu = $this->app->cfgItem ($menuCfgKey, NULL);
		$am = $this->activeWebMenu ($srcMenu);
		$menu = [];
		$this->menuAdd($am, $menu, count ($this->app->requestPath) - 1);

		return $menu;
	}

	protected function menuAdd ($srcMenu, &$dstMenu, $level)
	{
		if (!isset($srcMenu['items']))
			return;
		$dstMenu['items'] = [];
		foreach ($srcMenu['items'] as $menuItem)
		{
			if (isset ($menuItem['menuDisabled']))
				continue;
			$mi = ['title' => $menuItem['title'], 'url' => $menuItem['url']];
			if (isset($menuItem['redirectTo']) && $menuItem['redirectTo'] !== '')
				$mi['url'] = $menuItem['redirectTo'];

			$urlParts = explode ('/', substr($menuItem['url'], 1));
			$isActive = 1;
			for ($i = 0; $i <= $level; $i++)
			{
				if (!isset($urlParts [$i]) || !isset($this->app->requestPath[$i]) || $urlParts [$i] !== $this->app->requestPath[$i])
					$isActive = 0;
			}
			$mi['active'] = $isActive;

			if (isset ($menuItem['items']))
			{
				$this->menuAdd($menuItem, $mi, $level + 1);
				if (isset ($mi['items']))
					$mi ['subMenu'] = 1;
			}

			$dstMenu['items'][] = $mi;
		}
	}

	function urlId ($len = 0)
	{
		if ($len === 0)
		{
			$u = $this->app->requestPath();
			return str_replace('/', '-', substr($u, 1));
		}
		$parts = array_slice($this->app->requestPath, 0, $len);
		return implode('-', $parts);
	}

	function urlId2 ()
	{
		return $this->urlId(2);
	}

	function headMetaTags ()
	{
		if (!$this->webEngine->template)
			return '';

		$c = $this->webEngine->headMetaTags();

		$icon = utils::cfgItem($this->webEngine->serverInfo, 'images.web.icon', FALSE);
		if ($icon)
		{
			$c .= "<link rel='icon' href='{$this->app->dsRoot}/imgs/-w256{$icon}'>\n";
			if (substr($icon, -4, 4) === '.svg')
				$c .= "<link rel='icon' href='{$this->app->dsRoot}{$icon}'>\n";
		}

		$icon = utils::cfgItem($this->webEngine->serverInfo, 'images.web.iconApp', FALSE);
		if ($icon)
			$c .= "<link rel='shortcut icon' sizes='256x256' href='{$this->app->dsRoot}/imgs/-w256{$icon}'>\n";

		$icon = utils::cfgItem($this->webEngine->serverInfo, 'images.web.iconAppIos', FALSE);
		if ($icon)
		{
			$c .= "<link rel='apple-touch-icon' href='{$this->app->dsRoot}/imgs/-w512/-cffffff{$icon}'>\n";
			foreach (['152', '167', '180'] as $size)
				$c .= "<link rel='apple-touch-icon' sizes='{$size}x{$size}' href='{$this->app->dsRoot}/imgs/-w{$size}/-cffffff{$icon}'>\n";
		}

		return $c;
	}

	function jsLibs()
	{
		if (!$this->webEngine->template)
			return '';

		$c = $this->webEngine->jsLibs();
		return $c;
	}

	function resolveCmd_webForm ($params)
	{
		$formIdParam = \E10\searchParam ($params, 'id', 'wkf.core.libs.ContactForm');
		$formFwParam = \E10\searchParam ($params, 'fw', 'bs3');

		$wf = $this->app->createObject ($formIdParam);
		if (!$wf)
			return 'INVALID FORM ID';

		$webFormparams = [];
		foreach ($params as $key => $value)
		{
			if (is_string($value))
				$webFormparams[$key] = $value;
		}

		$wf->setFormParams($webFormparams);
		$wf->fw = $formFwParam;

		$wf->webFormId = $formIdParam;
		$wf->webScriptId = \E10\searchParam ($params, 'webScript', '');

		if (isset($params['owner']))
			$wf->template = $params['owner'];

		$done = intval ($this->app->testGetParam ('done'));
		if ($done === 1)
			return $wf->successMsg();

		if (!$wf->getData ())
			return $wf->createFormCode ();

		if (!$wf->validate ())
			return $wf->createFormCode ();

		$result = $wf->doIt ();

		if ($result === TRUE)
		{
			header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . $this->app->requestPath () . '?done=1');
			die();
		}

		return $result;
	}

	public function userAuthenticated ()
	{
		return $this->app->user->isAuthenticated ();
	}

	public function webModeTerminal()
	{
		if (!$this->webEngine || !$this->webEngine->authenticator || !$this->webEngine->authenticator->session)
			return 0;
		if ($this->webEngine->authenticator->session['loginType'] === 3)
			return 1;

		return 0;
	}
}

