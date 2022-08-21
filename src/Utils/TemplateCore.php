<?php

namespace Shipard\Utils;


class TemplateCore extends \Mustache
{
	/** @var \E10\Application */
	var $app;
	protected $template;
	protected $subTemplate;
	protected $subTemplateParams = [];
	protected $cntr = 1;
	public $templateRoot;
	public $templateUrlRoot;
	public $options = FALSE;
	public $dict = NULL;
	public $lang = FALSE;
	public $urlRoot;
	public $page;
	public $pagePart;
	var $pageParams = [];
	public $data = array ();

	var $templateParams = [];
	var $serverInfo = [];

	public function __construct ($app)
	{
		$this->app = $app;
		$this->pagePart = 'none';
		$this->_escape = '\E10\es';
	}

	protected function _getVariable($tag_name)
	{
		if ($tag_name [0] === '!')
			return array_values(parent::_getVariable(substr ($tag_name, 1)));
		if ($tag_name [0] === '_')
			return ($tag_name [1] === '_') ? '{{{'.substr($tag_name, 2).'}}}' : '{{'.substr($tag_name, 1).'}}';
		if ($tag_name [0] === '@')
			return $this->_resolveCmd (substr ($tag_name, 1));
		if ($tag_name [0] === '&')
			return $this->renderSubTemplate (substr($tag_name, 1));
		$ret = parent::_getVariable($tag_name);
		if ($ret instanceof \DateTimeInterface)
			return $ret->format ('d.m.Y');
		return $ret;
	}

	public function getVar($tag_name)
	{
		return $this->_getVariable($tag_name);
	}

	public function checkSubTemplate ()
	{
		if (!isset ($this->page ['subTemplate']))
			return;
		$this->page ['text'] = $this->renderSubTemplate ($this->page ['subTemplate']);
	}

	public function counter(){return $this->cntr++;}

	function dict ($params)
	{
		if (isset ($params['dataItem']))
			$txt = $this->_getVariable($params['dataItem']);
		else
			$txt = $params['text'];

		if (!$this->dict || $this->lang === FALSE)
			return $txt;

		$lang = $this->lang;
		//$biLang = TRUE;
		$biLang = FALSE;

		if (isset($this->dict [$txt]))
		{
			$dictItem = $this->dict [$txt];

			if (isset ($dictItem[$lang]))
			{
				$dictText = $dictItem[$lang];
				if ($biLang)
				{
					if ($dictText !== '')
						return $txt.' / '.$dictText;
					return $txt;
				}
				else
				{
					if ($dictText === '')
						return $txt;
					return $dictText;
				}
			}
		}

		return $txt;
	}

	function dictText ($txt)
	{
		if (!$this->dict || $this->lang === FALSE)
			return $txt;

		$lang = $this->lang;

		if (isset($this->dict [$txt]))
		{
			$dictItem = $this->dict [$txt];

			if (isset ($dictItem[$lang]))
			{
				$dictText = $dictItem[$lang];
				if ($dictText === '')
					return $txt;
				return $dictText;
			}
		}

		return $txt;
	}

	public function renderPagePart ($partType, $text)
	{
		$oldPagePart = $this->pagePart;
		$this->pagePart = $partType;
		try
		{
			$t = $this->_renderTemplate ($text);
		}
		catch (\MustacheException $e)
		{
			$t = 'Chyba šablony: '.$text;
		}

		$this->pagePart = $oldPagePart;
		return $t;
	}

	public function renderSubTemplate ($subtemplateName)
	{
		$subtemplateId = utils::parseMarkup($subtemplateName, $this->subTemplateParams);

		$fullSubTemplateName = $this->templateRoot . $subtemplateId . '.mustache';

		if (!is_file($fullSubTemplateName))
		{
			$parts = explode ('.', $subtemplateId);
			$t = array_pop ($parts);
			$fullSubTemplateName = __SHPD_MODULES_DIR__ . strtolower(implode ('/', $parts)) . '/subtemplates/'.$subtemplateId.'.mustache';
		}

		$this->subTemplate = file_get_contents ($fullSubTemplateName);
		return $this->render ($this->subTemplate);
	}

	public function loadTemplate ($name, $templateFileName = 'page.mustache', $forceTemplateScript = NULL)
	{
		$fullTemplateName = '';
		$fullOptionsName = '';

		$parts = explode ('.', $name);

		if (count ($parts) == 1)
		{
			$this->urlRoot = 'templates/' . $name;
			$this->templateRoot = __APP_DIR__ . '/templates/' . $name . '/';
			$this->templateUrlRoot = '/templates/' . $name . '/';
		}
		else
		{
			$this->urlRoot = __SHPD_ROOT_DIR__ . __SHPD_TEMPLATE_SUBDIR__. implode ('/', $parts);
			$this->templateRoot = __SHPD_ROOT_DIR__ . __SHPD_TEMPLATE_SUBDIR__. implode ('/', $parts) . '/';
			$this->templateUrlRoot = __SHPD_TEMPLATE_SUBDIR__. implode ('/', $parts) . '/';
		}

		$fullOptionsName = $this->templateRoot . 'template.json';
		if (is_file($fullOptionsName))
		{
			$optionsStr = file_get_contents($fullOptionsName);
			$this->options = json_decode($optionsStr, TRUE);
		}
		else
		{
			error_log ("template.json `$fullOptionsName` not found");
			$this->options = [];
		}

		$fullDictName = $this->templateRoot . 'dict.json';
		if (is_file($fullDictName))
		{
			$dictText = file_get_contents($fullDictName);
			$this->dict = json_decode ($dictText, TRUE);
		}

		if ($forceTemplateScript)
		{
			$this->template = $forceTemplateScript;
			return;
		}

		$fullTemplateName = $this->templateRoot . $templateFileName;

		if ($templateFileName !== FALSE)
			$this->template = file_get_contents ($fullTemplateName);

		if ($templateFileName !== FALSE && !$this->template)
		{
			error_log("file `$fullTemplateName` not found [TID: $name]");
			Utils::debugBacktrace();
		}
	}

	public function clientType ()
	{
		$ct[$this->app->clientType[0]] = [$this->app->clientType[1] => $this->app->clientType[2]];
		return $ct;
	}

	public function cfgItem ()
	{
		return $this->app->appCfg;
	}

	public function htmlCodeIcons()
	{
		$scRoot = $this->app->scRoot();
		$iconsCfg = $this->app->ui()->icons()->iconsCfg;
		$c = '';
		$c .= "<link rel='stylesheet' type='text/css' href='{$scRoot}/{$iconsCfg['styleLink']}'>\n";

		return $c;
	}

	public function mobileMode ()
	{
		return $this->app->mobileMode;
	}

	public function userAuthenticated ()
	{
		return $this->app->user->isAuthenticated ();
	}

	public function systemLanguages()
	{
		return array_values($this->app->systemLanguages);
	}

	public function userLanguage()
	{
		return $this->app->systemLanguages[$this->app->userLanguage];
	}

	public function userAppAccess ()
	{
		return $this->app->hasRole ('user'); // TODO: not working, any idea?
	}

	public function setPage ($page)
	{
		$this->page = $page;
		$this->checkSubTemplate ();
	}

	public function setParam ($key, $value)
	{
		if ($key === 'language')
			$this->lang = $value;
		$this->pageParams[$key] = $value;
	}

	public function pageClose ()
	{
		return $this->app->createPageCodeClose ($this->page);
	}

	public function pageOpen ()
	{
		return $this->app->createPageCodeOpen ($this->page);
	}

	public function portalInfo ()
	{
		$pi = $this->app->portalInfo();
		$pi['items'] = array_values($pi['pages']);
		return $pi;
	}

	public function renderTemplate ()
	{
		return $this->render ($this->template);
	}

	private function _resolveCmd ($markupCode)
	{
		$params = [];
		$m = utils::parseMarkup($markupCode, $params);
		$params ['owner'] = $this;
		return $this->resolveCmd ($markupCode, $m, $params);
	}

	function resolveCmd ($tagCode, $tagName, $params)
	{
		switch ($tagName)
		{
			case	'icon' 						: return $this->resolveCmd_Icon($params);
			case	'imgData' 				: return $this->resolveCmd_ImgData($params);
			case	'include' 				: return $this->resolveCmd_Include($params);
			case	'dict' 						: return $this->dict($params);
			case	'composeTextLine' : return $this->resolveCmd_ComposeTextLine($params);
			case	'qrCode' 					: return $this->resolveCmd_QrCode($params);
			case	'script' 					: return $this->resolveCmd_Script($params);
		}

		$res = $this->app->callRegisteredFunction ('template', $tagName, $params);
		if ($res !== NULL)
			return $res;

		return '';
	}

	public function templateRoot ()
	{
		return $this->app->dsRoot.'/'.$this->urlRoot;
	}

	public function templateUrlRoot ()
	{
		return $this->templateUrlRoot;
	}

	public function dsRoot ()
	{
		return $this->app->dsRoot;
	}

	public function dsUrl ()
	{
		return 'https://'.$this->app->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid') . '/';
	}

	public function fsRoot ()
	{
		return __APP_DIR__;
	}

	public function scRoot ()
	{
		return $this->app->scRoot();
	}

	public function urlHostRoot ()
	{
		return $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot;
	}

	public function urlRoot ()
	{
		return $this->app->urlRoot;
	}

	public function urlServer ()
	{
		if (isset($_SERVER['HTTP_HOST']))
			return $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->dsRoot . '/';
		return '';
	}

	public function userName ()
	{
		return $this->app->user ()->data ('name');
	}

	public function userImage ()
	{
		$user = $this->app->user ()->data ();
		return utils::userImage ($this->app, $user['ndx'], $user);
	}

	function resolveCmd_Icon ($params)
	{
		if (isset ($params['dataItem']))
			$icn = $this->getVar($params['dataItem']);
		else
			$icn = $params['icon'];

		return $this->app->ui()->icon($icn);
	}

	function resolveCmd_ImgData ($params)
	{
		if (!isset ($params['dataItem']))
			return 'no dataItem';
		$fn = $this->getVar($params['dataItem']);

		if (is_readable($fn['rfn']))
		{
			$imgSrcData = file_get_contents($fn['rfn']);
			$type = mime_content_type($fn['rfn']);
			$data = 'data:' . $type . ';base64,' . base64_encode($imgSrcData);
			return $data;
		}
		return 'file not found';
	}

	function resolveCmd_ComposeTextLine ($params)
	{
		if (isset ($params['dataItem']))
		{
			$l = $this->getVar($params['dataItem']);
			return $this->app->ui()->composeTextLine($l);
		}
		return '';
	}

	function resolveCmd_Include ($params)
	{
		if (isset ($params['fileName']))
		{
			$fileName = __APP_DIR__.'/includes/'.$params['fileName'];
			$content = file_get_contents($fileName);
			if ($content !== FALSE)
			{
				if (isset ($params['texy']))
				{
					$texy = new \Texy();
					$content = $texy->process($content);
					return $content;
				}
				else
				{
					/*
					if (isset ($params['format']))
					{
						$data = NULL;
						switch ($params['format'])
						{
							case 'json': $data = json_decode($content, TRUE); break;
						}

						if (isset ($params['dataItem']))
						{
							$this->data[$params['dataItem']] = $data;
							return '';
						}
					}*/
				}
				$code = "\n"."<pre><code>"."[[[!!!base64decode!!!:".base64_encode(utils::es($content))."!!!]]]"."</code></pre>";
				return $code;
			}
			error_log ("INCLUDE: file $fileName not found!");
			return '';
		}
		error_log ("INCLUDE: invalid params");
		return '';
	}

	function resolveCmd_QrCode ($params)
	{
		if (isset ($params['dataItem']))
			$qrData = $this->getVar($params['dataItem']);
		else
			$qrData = $params['text'];

		$qrCodeGenerator = new \lib\tools\qr\QRCodeGenerator($this->app);
		$qrCodeGenerator->textData = $qrData;
		$qrCodeGenerator->createQRCode();

		$url = 'https://'.$this->app->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid').'/tmp/'.basename($qrCodeGenerator->fullFileName);
		return $url;
	}

	function resolveCmd_Script ($params)
	{
		$scriptId = isset($params['id']) ? $params['id'] : '';
		if ($scriptId === '')
			return '';

		$script = new \lib\web\WebScript($this->app);
		$script->setScriptId($scriptId);
		$script->runScript($this->data);
		return $script->resultCode;
	}
}



