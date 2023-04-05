<?php

namespace Shipard\Report;
use \Shipard\Utils\Utils;
use \Shipard\UI\Core\UIUtils;
use \Shipard\UI\Core\ContentRenderer;

class TemplateMustache extends \Shipard\Utils\TemplateCore
{
	protected $templateBaseName;
	public $binMode = FALSE;
	public $binCodePage = FALSE;
	public $binData = '';
	protected $info;
	protected $registeredImages;
	protected $report;

	public function appDir ()
	{
		return __APP_DIR__;
	}

	public function cfgItem ()
	{
		return $this->app->appCfg;
	}

	public function loadTemplate ($name, $templateFileName = 'page.mustache', $forceTemplateScript = NULL)
	{
		$fullTemplateName = '';
		$fullDictName = '';

		$parts = explode ('.', $name);

		$replace = $this->app->db->query ("SELECT * FROM [e10_base_templates] WHERE [replaceId] = %s", $name)->fetch ();
		if ($replace)
		{
			$this->templateRoot = __APP_DIR__ . '/templates/'.$replace['sn'].'/';
			$fullTemplateName = $this->templateRoot . $templateFileName;
			$fullDictName = $this->templateRoot . 'dict.json';
			$this->templateBaseName = $parts[0];
			$this->templateUrlRoot = 'https://'.$this->app->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid') . '/'.'/templates/'.$replace['sn'].'/';
		}
		else
		{
			$tfn = array_pop ($parts);
			$this->templateRoot = __SHPD_ROOT_DIR__.__SHPD_TEMPLATE_SUBDIR__.'/'.implode ('/', $parts).'/'.$tfn.'/';
			$fullTemplateName = $this->templateRoot . $templateFileName;
			$fullDictName = $this->templateRoot . 'dict.json';
			$this->templateBaseName = $tfn;
			$this->templateUrlRoot = __SHPD_TEMPLATE_SUBDIR__.'/'.implode ('/', $parts).'/'.$tfn.'/';
		}

		if (!is_readable($fullTemplateName))
		{
			if ($templateFileName === 'page.mustache')
				error_log("TEMPLATE NOT FOUND: `$fullTemplateName`");
			return FALSE;
		}

		$this->template = file_get_contents($fullTemplateName);

		if (is_file($fullDictName))
		{
			$dictText = file_get_contents($fullDictName);
			$this->dict = json_decode ($dictText, TRUE);
		}

		return TRUE;
	}

	function registerImage ($params)
	{
		if (isset ($params['src']))
		{
			$srcT = strtr ($params['src'], ['[' => '{{', ']' => '}}']);
			$src = $this->render($srcT);

			if (isset ($params['dest']))
			{
				$destT = strtr ($params['dest'], ['[' => '{{', ']' => '}}']);
				$dest = $this->render($destT);
			}
			else
			{
				$dest = md5($src).strrchr($src, ".");
			}

			$params['owner']->report->registerImage ($src, $dest);
			if (!isset ($params['dest']))
				return $dest;
		}

		return '';
	}

	function renderContent ($params)
	{
		if (!isset ($params['dataItem']))
			return '';

		$content = $this->_getVariable($params['dataItem']);
		$cr = new ContentRenderer ($this->app);
		$cr->content = $content;
		$code = $cr->createCode();



		return $code;
	}


	function renderData ($params)
	{
		if (isset ($params['dataItem']))
		{
			$data = $this->_getVariable($params['dataItem']);
			if (isset($params['texy']))
			{
				$texy = new \E10\Web\E10Texy($this->app);
				$texy->headingModule->top = 2;
				$data = $texy->process ($data);
				return $this->renderPagePart ('content', $data);
			}
			return $this->render ($data);
		}

		return '';
	}

	function renderTable ($params)
	{
		if (!isset ($params['dataItem']))
			return '';

		$cp = $this->_getVariable($params['dataItem']);
		if ($cp === '')
			return '';
		$tableParams = $cp['params'] ?? ['tableClass' => 'e10-vd-mainTable'];

		if (isset($params['tableClass']))
			$tableParams['tableClass'] = $params['tableClass'];
		if (isset($params['forceTableClass']))
			$tableParams['forceTableClass'] = $params['forceTableClass'];

		$c = $this->app->ui()->renderTableFromArray ($cp['table'], $cp['header'], $tableParams);
		return $c;
	}

	protected function stdReportHeader ()
	{
		return UIUtils::createReportContentHeader($this->app, $this->info);
	}

	public function setData ($report)
	{
		$this->report = $report;
		$this->data = $report->data;
		$this->info = $report->info;

		if (isset ($report->recData))
			$this->data ['head'] = $report->recData;

		if (isset($report->info['dict']))
			$this->dict = $report->info['dict'];

		$this->lang = $report->lang;

		$this->data ['reportContent'] = $report->content;
	}

	public function renderTemplate ()
	{
		$txt = $this->render ($this->template);
		if ($this->binMode)
			return $this->binData;
		return $txt;
	}

	function resolveCmd ($tagCode, $tagName, $params)
	{
		switch ($tagName)
		{
			case	'dict' 					: return $this->dict ($params);
			case	'renderData' 		: return $this->renderData ($params);
			case	'registerImage' : return $this->registerImage ($params);
			case	'renderContent' : return $this->renderContent ($params);
			case	'renderTable' 	: return $this->renderTable ($params);
			case	'write'					: return $this->write ($params);
			case	'barCodeImg'		: return $this->barCodeImg ($params);
		}

		return parent::resolveCmd ($tagCode, $tagName, $params);
	}

	public function templateRoot ()
	{
		return $this->templateRoot;
	}

	function write ($params)
	{
		$text = '';

		if (isset ($params['cmd']))
		{
			$this->binData .= $this->cmd ($params['cmd']);
			return '';
		}

		if (isset ($params['codePage']))
		{
			$this->binCodePage = $params['codePage'];
			return '';
		}
		if (isset ($params['binMode']))
		{
			$this->binMode = ($params['binMode']) ? TRUE : FALSE;
			return '';
		}


		if (isset ($params['text']))
			$text = $params['text'];
		if (isset ($params['var']))
			$text = $this->_getVariable($params['var']);

		$align = -1;
		if (isset ($params['align']))
		{
			switch ($params['align'])
			{
				case 'left': $align = -1; break;
				case 'center': $align = 0; break;
				case 'right': $align = 1; break;
			}
		}

		$paddingChar = ' ';
		if (isset ($params['paddingChar']))
			$paddingChar = mb_substr($params['paddingChar'], 0, 1, "UTF-8");
		if (strlen(utf8_decode($paddingChar)) == 0)
			$paddingChar = ' ';

		$width = 0;
		if (isset ($params['width']))
			$width = intval ($params['width']);

		if ($width)
		{
			$leftChars = $width - strlen(utf8_decode($text));
			while ($leftChars > 0)
			{
				switch ($align)
				{
					case -1:	$text .= $paddingChar; $leftChars--; break;
					case  1:	$text = $paddingChar.$text; $leftChars--; break;
					case  0:	$text .= $paddingChar; $leftChars--;
										if ($leftChars > 0)
										{
											$text = $paddingChar.$text;
											$leftChars--;
										}
										break;
				}
			}
		}

		$maxLen = 0;
		if (isset ($params['maxLen']))
			$maxLen = intval ($params['maxLen']);

		if ($maxLen)
		{
			$text = mb_substr($text, 0, $maxLen, "UTF-8");
		}

		if (isset ($params['ascii']))
		{
			$currentLocale = locale_get_default();
			setlocale(LC_CTYPE, 'cs_CZ.UTF-8');
			$text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
			$text = str_replace("'", '', $text);
			setlocale(LC_CTYPE, $currentLocale);
		}

		if ($this->binCodePage === FALSE)
			$binData = $text;
		else
			$binData = iconv('UTF-8', $this->binCodePage, $text);

		$format = (isset($params['format'])) ? $params['format'] : 'none';

		if ($format === 'html')
			$binData = $this->convertHtml2PrinterCode($binData);

		$this->binData .= $binData;

		return '';
	}

	function convertHtml2PrinterCode($text)
	{
		$driver = NULL;
		if ($this->report && $this->report->printerDriver)
			$driver = $this->report->printerDriver;

		if (!$driver)
		{
			error_log ("*****DRIVER NOT FOUND*****");
			return $text;
		}

		$s = strtr($text, $driver['htmlCmd']);

		return $s;
	}

	public function cmd ($command)
	{
		if ($this->report && $this->report->printerDriver)
		{
			if (isset($this->report->printerDriver['rawCmd'][$command]))
				return $this->report->printerDriver['rawCmd'][$command];
		}

		switch ($command)
		{
			case 'nl': return "\n";
			case 'crlf': return "\r\n";
			case 'dp': return ":";
			case 'sp': return " ";
			case 'reset': return chr(27).chr(64);
			case 'boldOn': return chr(27).chr(69).chr(128);
			case 'boldOff': return chr(27).chr(69).chr(0);
			case 'condensedOn': return chr(27).chr(33).chr(7);
			case 'condensedOff': return chr(27).chr(33).chr(0);
			case 'twiceOn': return chr(29).'!'.chr(0b00010001);
			case 'twiceOff': return chr(29).'!'.chr(0b00000000);
			case 'cutPaper': return chr(29).chr(86).chr(65).chr(5);
			case 'openCashDrawer': return chr(27).chr(112).chr(48).chr(55).chr(121);
			case 'cp852': return
											chr(28).chr(46). // `FS .` - Cancel Chinese character mode
											chr(27).chr(116).chr(18); // set CP852
		}
		return '';
	}

	public function xmlMode ()
	{
		//$this->_escape = '\E10\es';
		return '';
	}

	public function textMode ()
	{
		$this->_escape = function ($s) {return $s;};
		return '';
	}

	public function barCodeImg($params)
	{
		$textData = '';

		if (isset ($params['dataItem']))
			$textData = $this->_getVariable($params['dataItem']);
		elseif (isset ($params['textData']))
			$textData = $params['textData'];

		$codeType = 'qr';
		if (isset ($params['codeType']))
			$codeType = $params['codeType'];

		$barCodeGenerator = new \lib\tools\bc\BarCodeGenerator($this->app);
		$barCodeGenerator->textData = $textData;
		$barCodeGenerator->create($codeType);
		return $barCodeGenerator->url;
	}
}

