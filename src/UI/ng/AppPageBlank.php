<?php

namespace Shipard\UI\ng;
use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;
use \Shipard\Application\Application;


/**
 * class AppPageBlank
 */
class AppPageBlank extends Utility
{
	const backIcon = 'system/actionBack';

	var $uiThemeId = 'default';
	var $uiThemeCfg = NULL;
	static $themeStatusColor =
			['default' => '#212529'];

	protected $pageTabs = FALSE;
	protected $content = [];
	protected $definition = NULL;

	var $appMode = FALSE;
	var $embeddMode = 0;
	var $dsMode = 1;
	var $wss = [];
	var \Shipard\UI\ng\TemplateUI $uiTemplate;
	var ?\Shipard\UI\ng\Router $uiRouter = NULL;

	var $pageInfo = [];
  var ?array $uiCfg = NULL;
  var ?array $uiRecData = NULL;

	protected function createPageCodeEmbedStyle ()
	{
		if ($this->uiRecData && $this->uiRecData['style'] !== '')
		{
			return "<style>\n".$this->uiRecData['style']."\n</style>\n";
		}
		return '';
	}

	public function createPageCode ()
	{
		//$firstUrlPart = $this->app->requestPath(1);

		$c = '';

		if ($this->appMode)
		{
			$originPath = $this->app->requestPath(1);

			$this->pageInfo['httpOriginPath'] = $originPath;
			$this->pageInfo['sessionId'] = $this->app->sessionId;
			$this->pageInfo['guiTheme'] = $this->uiThemeId;
			$this->pageInfo['wss'] = $this->wss;
			$this->pageInfo['pageType'] = $this->pageType();
		}

		if (!$this->appMode)
			$c .= $this->createPageCodeBegin();

		//$c .= $this->createPageCodeTitle();

		$c .= $this->createContentCodeBegin ();
		$c .= $this->createContentCodeInside ();
		$c .= $this->createContentCodeEnd ();

		if (!$this->appMode)
			$c .= $this->createPageCodeEnd();

		return $c;
	}

	public function run ()
	{
		if ($this->app->testGetParam('app') !== '')
			$this->appMode = TRUE;

		$this->wss = $this->app->webSocketServers ();
		$this->createContent();
		$this->pageTabs = $this->pageTabs();
	}

	public function createContentCodeBegin ()
	{
		$c = '';

		return $c;
	}

	public function createContentCodeEnd ()
	{
		return '';
	}

	public function createContentCodeInside ()
	{
		$c = '';

		return $c;
	}

	public function createPageCodeBegin ()
	{
		$this->dsMode = $this->app->cfgItem ('dsMode', Application::dsmTesting);

		$uiThemeFN = 'www-root/.ui/'.'ng'.'/themes'.'/themes.json';
		$uiThemesCfg = Utils::loadCfgFile($uiThemeFN);
		$this->uiThemeCfg = $uiThemesCfg[$this->uiThemeId];
		$tt = $this->pageTitle();

		$absUrl = '';
		$cfgID = $this->app->cfgItem ('cfgID');

		//$mobileuiTheme = $this->app->cfgItem ('options.appearanceApp.mobileuiTheme', 'md-default');
		//if ($mobileuiTheme === '')
			$mobileuiTheme = 'default';
		$themeStatusColor = self::$themeStatusColor[$mobileuiTheme];
		$style = 'style.css';

		$dsIcon = $this->app->dsIcon();
		$originPath = $this->app->requestPath(1);
		$wssStatus = $this->createPageCodeWss();


		$c = "<!DOCTYPE HTML>\n".
					"<html lang=\"".$this->app()->userLanguage."\">\n".
					"<head>\n".
					"<title>" . Utils::es ($tt) . "</title>".
					"<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\"/>\n";

		$scRoot = $this->app()->scRoot();

		$c .= "<meta http-equiv='cache-control' content='max-age=0' />\n".
					"<meta http-equiv='Pragma' content='no-cache'>\n";
		$c .= "<meta name='apple-mobile-web-app-capable' content='yes'>\n" .
					"<meta name='apple-mobile-web-app-status-bar-style' content='black'>\n".
					"<meta name='mobile-web-app-capable' content='yes'>\n";
		$c .= "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0\"/>\n";
		$c .= "<meta name=\"format-detection\" content=\"telephone=no\">\n";
		$c .= "<meta name='theme-color' content='$themeStatusColor'>\n";

		$c .= "<link rel=\"manifest\" href=\"".$this->uiRouter->uiRoot."manifest.webmanifest\">\n";

		$c .= "<meta name=\"generator\" content=\"E10 ".__E10_VERSION__."\">\n";
		$c .= "<meta http-equiv=\"X-UA-Compatible\" content=\"IE=edge\" />\n";

		//$c .= "<link rel='stylesheet' type='text/css' href='{$scRoot}/bs/5.3/dist/css/bootstrap.min.css?v530'/>\n";

		$themeUrl = "$absUrl{$this->app->urlRoot}/www-root/.ui/ng/themes/" . $this->uiThemeId . "/$style?vv=".$this->uiThemeCfg['integrity']['sha384'];
		$c .= "<link rel='stylesheet' type='text/css' href='$themeUrl'/>\n";

		$c .= "\t<script type=\"text/javascript\">\nvar httpApiRootPath = '{$this->uiRouter->uiRoot}';var serverTitle=\"" . Utils::es ($this->app->cfgItem ('options.core.ownerShortName', '')) . "\";" .
			"var remoteHostAddress = '{$_SERVER ['REMOTE_ADDR']}'; e10ClientType = " . json_encode ($this->app->clientType) . ";\n";
		$c .= "var deviceId = '{$this->app->deviceId}';\n";
		$c .= "var webSocketServers = ".json_encode($this->wss).";\n";
		$c .= "var httpOriginPath = '$originPath';\n";
		$c .= "var e10dsIcon = '{$dsIcon['iconUrl']}';\n";
		$c .= "var e10dsIconServerUrl = '{$dsIcon['serverUrl']}';\n";
		$c .= "var e10dsIconFileName = '{$dsIcon['fileName']}';\n";


		$c .= "var e10ServiceWorkerURL = '{$this->uiRouter->uiRoot}sw.js';";
		//$c .= "var e10ServiceWorkerURL = undefined;";
		if (isset($this->pageInfo['userInfo']))
			$c .= "g_UserInfo = ".json_encode($this->pageInfo['userInfo']).";\n";

		$c .= '</script>';

		//$c .= "<script type=\"text/javascript\" src=\"{$scRoot}/libs/js/jquery/jquery-3.5.1.min.js\"></script>";
		$c .= "<script type=\"text/javascript\" src=\"{$scRoot}/bs/5.3/dist/js/bootstrap.bundle.min.js?v530\"></script>";
		$c .= "<script type=\"text/javascript\" src=\"{$scRoot}/libs/js/mqttws/mqttws31.min.js\"></script>\n";


		$iconsCfg = $this->app()->ui()->icons()->iconsCfg;
		$c .= "<link rel='stylesheet' type='text/css' href='{$scRoot}/{$iconsCfg['styleLink']}'>\n";


		if (0 && $this->dsMode !== Application::dsmDevel)
		{
			$files = unserialize (file_get_contents(__SHPD_ROOT_DIR__.'/ui/clients/files.data'));
			$c .= "\t<script type='text/javascript' integrity='{$files['ng']['client.js']['integrity']}' src='$absUrl{$this->app->urlRoot}/www-root/.ui/ng/js/client.js?v=".$files['ng']['client.js']['ver']."'></script>\n";
		}
		else
		{
			$jsFiles = Utils::loadCfgFile(__SHPD_ROOT_DIR__.'/ui/clients/ng/js/package.json');
			foreach ($jsFiles['srcFiles'] as $sf)
			{
				$cs = md5_file(__APP_DIR__."/www-root/ui-dev/clients/ng/js/{$sf['fileName']}");
				$c .= "\t<script type=\"text/javascript\" src=\"{$this->app->urlRoot}/www-root/ui-dev/clients/ng/js/{$sf['fileName']}?v=$cs\"></script>\n";
			}

		}
		$c .= "<link rel='shortcut icon' sizes='512x512' href='{$dsIcon['iconUrl']}' id='e10-browser-app-icon'>\n";
		$c .= "<link rel='apple-touch-icon' sizes='180x180' href='{$dsIcon['iconUrl']}'/>\n";


		$bodyClass = "e10-body-{$this->app->requestPath[0]} body-device-{$this->app->clientType[1]} body-client-{$this->app->clientType[0]} body-dsm-{$this->dsMode}";
		if (isset ($page['params']['bodyClass']))
			$bodyClass .= ' '.$page['params']['bodyClass'];
		if ($this->pageTabs)
			$bodyClass .= ' pageTabs';

		$c .= "</head>\n";

		$c .= $this->createPageCodeEmbedStyle();

		$c .= "<body data-app-type='datasource' class='$bodyClass' ";
		$c .= $this->createPageBodyParams();
		$c .= '>';


		// -- status
		/*
		$c .= "<ul id='e10-page-status'>";
		$c .= "<li id='e10-status-progress'></li>";
		$c .= $wssStatus;
		$c .= '</ul>';
		$c .= "<div id='e10-page-body'>";
		*/

		return $c;
	}

	public function createPageCodeWss ()
	{
		if (!count($this->wss))
			return '';

		$c = '';

		$srvidx = 0;
		forEach ($this->wss as $srv)
		{
			$title = Utils::es ($srv['name']);
			$c .=  "<li class='e10-wss e10-wss-none' id='wss-{$srv['id']}' title=\"$title\">";
			$c .=  '</li>';

			/*
			forEach ($srv['sensors'] as $sensor)
			{
				//if (!in_array ($this->app->deviceId, $sensor['devices']))
				//	continue;

				//$c .= "<div class='e10-sensor' data-sensorid='{$sensor['id']}' data-serveridx='$srvidx' id='wss-{$srv['id']}-{$sensor['id']}'>";
				//if ($sensor['class'] === 'number')
				//	$c .= "<span class='sd' id='e10-sensordisplay-{$sensor['id']}'>---</span>";
				//$c .= '</div>';
			}
			*/

			$srvidx++;
		}

		return $c;
	}

	public function createPageBodyParams ()
	{
		return '';
	}

	public function createPageCodeEnd ()
	{
		return '</div></body></html>';
	}

	public function createPageCodeTitle ()
	{
		$c = '';

		$c .= "<div id='e10-page-header' class='e10mui pageHeader'>";

		$lmb = NULL;
		if (!$this->embeddMode)
			$lmb = $this->leftPageHeaderButton();
		if ($lmb)
		{
			$c .= "<span ";

			if (isset ($lmb['action']))
				$c .= "class='lmb e10-trigger-action' ";
			else
				$c .= "class='lmb link' ";

			if (isset ($lmb['backButton']))
				$c .= "id='e10-back-button'";

			if (isset($lmb['action']))
				$c .= " data-action='{$lmb['action']}'";
			else
				if (isset($lmb['path']))
					$c .= " data-path='{$lmb['path']}'";

			$c .= ">";

			$c .= $this->app()->ui()->icon($lmb['icon']);
			$c .= "</span>";
		}

		$c .= "<div class='pageTitle'>";
		$c .= "<h1>".Utils::es($this->title1())."</h1>";
		$c .= "<h2>".Utils::es($this->title2())."</h2>";
		$c .= '</div>';

		$rmbs = NULL;
		if (!$this->embeddMode)
			$rmbs = $this->rightPageHeaderButtons();
		if ($rmbs)
		{
			$c .= "<span class='rmbs'>";
			foreach ($rmbs as $rmb)
			{
				$c .= "<span ";

				if (isset ($rmb['action']))
					$c .= "class='e10-trigger-action' ";
				else
					$c .= "class='link' ";

				if (isset ($rmb['backButton']))
					$c .= "id='e10-back-button'";

				if (isset($rmb['action']))
					$c .= " data-action='{$rmb['action']}'";
				else
				if (isset($rmb['path']))
					$c .= " data-path='{$rmb['path']}'";
				else
				if (isset($rmb['url']))
					$c .= " data-url='{$rmb['url']}'";

				if (isset ($rmb['data']))
				{
					foreach ($rmb['data'] as $dataKey => $dataValue)
						$c .= " data-{$dataKey}='{$dataValue}'";
				}

				$c .= ">";

				$c .= $this->app()->ui()->icon($rmb['icon']);
				$c .= "</span>";
			}
			$c .= '</span>';
		}
		if (!$this->embeddMode)
			$c .= $this->createPageCodeHeaderTabs();
		$c .= "</div>";

		if ($this->embeddMode)
		{
			$embeddParts = [];
			for($epi = 2; $epi < 6; $epi++)
			{
				$up = $this->app->requestPath($epi);
				if ($up === '')
					break;
				$embeddParts[] = $up;
			}

			if (count($embeddParts))
				$c .= "<script>const g_initDataPath = '".implode('/', $embeddParts)."';</script>";
		}
		return $c;
	}

	public function createPageCodeHeaderTabs ()
	{
		if ($this->pageTabs === FALSE)
			return '';

		$c = '';

		$c .= "<ul id='e10-page-tabs' class='e10-page-tabs'>";
		foreach ($this->pageTabs as $tabId => $t)
		{
			$class = '';
			if (isset($t['active']) && $t['active'])
				$class = 'active';

			$c .= "<li class='$class' id='e10-page-tab-$tabId'>";
			$c .= Utils::es ($t['text']);
			$c .= "</li>";
		}
		$c .= '</ul>';

		return $c;
	}

	public function createContent ()
	{

	}

	public function createCode ()
	{
		return '';
	}

	public function pageTabs () {return FALSE;}

	public function pageType () {return '';}

	public function title1 () {return '';}

	public function title2 ()
	{
		return $this->app->cfgItem ('options.core.ownerShortName');
	}

	public function pageTitle()
	{
		$t = $this->title1() . ' / ' . $this->title2();
		return $t;
	}

	public function setDefinition ($definition)
	{
		$this->definition = $definition;
	}

	public function leftPageHeaderButton ()
	{
		return FALSE;
	}

	public function rightPageHeaderButtons ()
	{
		return FALSE;
	}

	protected function appObjectId ($menuItem)
	{
		$id = '';
		if ($menuItem['object'] === 'viewer')
		{
			$id .= 'o=v;';
			$id .= 't='.$menuItem['table'].';';
			if (isset($menuItem['viewer']))
				$id .= 'v='.$menuItem['viewer'];
		}

		return $id;
	}
}

