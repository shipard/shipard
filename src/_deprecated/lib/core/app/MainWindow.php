<?php

namespace lib\core\app;

use e10\utils, \translation\dicts\e10\base\system\DictSystem;

/**
 * Class MainWindow
 * @package lib\core\app
 */
class MainWindow extends \Shipard\Base\BaseObject
{
	/** @var  \e10\Application */
	public $panels;
	public $panel;

	var $leftBarCode = '';
	var $leftBarClass = 'e10-app-lb-icons';

	var $leftSubMenusCode = '';


	public function __construct ($app, $panels = NULL, $panel = NULL)
	{
		parent::__construct($app);
		$this->panels = ($panels) ? $panels : $app->appSkeleton ["panels"];
		$this->panel = ($panel) ? $panel : $app->panel;
	}

	function createBrowserCode ($page, $rightBarCode="", $leftBarCode2="", $oneWidget = FALSE)
	{
		$enableAppMenu = ($this->app->testGetParam('disableAppMenu') === '');

		if (isset($this->panel['disableAppMenu']) && $this->panel['disableAppMenu'])
			$enableAppMenu = FALSE;

		$disableLeftMenu = (isset($this->panel['disableLeftMenu']) && $this->panel['disableLeftMenu']);
		$mainWidgetMode = FALSE;

		$disableRightMenu = (isset($this->panel['disableRightMenu']) && $this->panel['disableRightMenu']) || ($this->app->testGetParam('disableAppRightMenu') !== '');


		if ((isset($this->panel['mainWidgetMode']) && $this->panel['mainWidgetMode']) || ($this->app->testGetParam('mainWidgetMode') === '1'))
		{
			$enableAppMenu = FALSE;
			$disableLeftMenu = TRUE;
			$mainWidgetMode = TRUE;
		}

		if ($rightBarCode === '' && !$enableAppMenu)
		{
			$rbc = '';
			$rbc .=  "<span id='e10-nc-button' class='appMenuButton e10-ntf-button' style='display: block; text-align: right; padding: 1ex; cursor: pointer;' title='Oznamovací centrum'>";
			$rbc .=  "<span style='width: 2em; display: inline-block; ' id='e10-nc-button-cn'></span> ";
			$rbc .=  $this->app()->ui()->icon('system/actionNotifications');
			$rbc .=  "<span style='display: inline-block; padding-left: 1ex; display: none;' id='e10-nc-button-ra'> <i class='fa fa-play e10-success'></i></span>";
			$rbc .= '</span>';

			$rightBarCode = $rbc;
		}

		$page['params']['bodyClass'] = 'e10-appNormal';
		if ($mainWidgetMode)
			$page['params']['bodyClass'] = 'e10-appMainWidgetMode';
		else
			if ($disableLeftMenu)
				$page['params']['bodyClass'] = 'e10-appDisabledLeftMenu';

		if ($disableRightMenu)
			$page['params']['bodyClass'] .= ' e10-appDisabledRightMenu';

		$this->createLeftBarCode($leftBarCode2, $oneWidget);

		$panelTlbr = $this->createToolbarCode ();

		$topMenu = "<div id='e10-panel-topmenu' class='subnav'>".
			"<ul class='nav nav-pills'>".
			"<li id='e10-tm-searchbox'></li>".
			"<li id='e10-tm-viewerbuttonbox'></li>".
			"<li id='e10-tm-detailbuttonbox'></li>".
			"<li id='e10-tm-toolsbuttonbox'></li>".
			"<li id='e10-tm-panelbuttonbox'>$panelTlbr</li>".
			"</ul>".
			'</div>';


		$userInfo = '';
		//$bottomButtons = '';
		if ($this->app->user ()->isAuthenticated () && $enableAppMenu)
		{
			$userInfo .= "<ul class='appMenu appMenuRight' style='float: right;'>";

			// -- workplace
			$isWorkplace = 0;
			if (isset ($this->app->workplace['name']))
			{
				$userInfo .=  "<li><span>";
				$userInfo .=  $this->app()->ui()->icon('system/iconWorkplace') . ' ' . utils::es ($this->app->workplace['name']);
				$userInfo .=  '</span></li>';
				$isWorkplace = 1;
			}

			// -- web socket servers
			if ($isWorkplace)
			{
				$wss = $this->app->webSocketServers();
				$userInfo .= $this->wssCode($wss);
			}

			$userInfo .= "<li><span>".$this->app()->ui()->icon('system/iconOwner');
			$ownerName = $this->app->cfgItem ('options.core.ownerShortName', '');
			if ($ownerName == '')
				$ownerName = '#'.$this->app->cfgItem ('dsid', '!');
			$userInfo .= ' '.utils::es ($ownerName);
			$userInfo .= '</span></li>';


			// -- "today" date
			if ($this->app->hasRole('pwuser'))
			{
				$wdText = '';
				$wd = $this->app->getUserParam('wd', FALSE);
				if ($wd)
					$wdText = ' ' . utils::datef($wd);
				$userInfo .= "<li><span class='appMenuButton df2-action-trigger' data-action='addwizard' data-class='lib.cfg.WorkingDateWizard' title='Nastavit pracovní datum'>".$this->app()->ui()->icon('system/actionCalendar')."{$wdText}</span></li>";
			}

			$userInfo .= '<li><span>';
			$userInfo .= $this->app()->ui()->icons()->icon ('system/iconUser').'&nbsp;';
			$userInfo .= utils::es ($this->app->user()->data ('name'));
			$userInfo .= '</span></li>';

			if ($this->app->userLanguage !== 'cs')
			{
				$userLang = $this->app->systemLanguages[$this->app->userLanguage];
				$userInfo .= "<li><span style='padding: 0 .5ex; font-size: 2.7ex;'>";
				$userInfo .= $userLang['f'];
				$userInfo .= '</span></li>';
			}

			$logoutUrl = $this->app->urlRoot . '/' . $this->app->appSkeleton['userManagement']['pathBase'] . '/' . $this->app->appSkeleton['userManagement']['pathLogoutCheck'];

			$userInfo .=  "<li>";

			$userInfo .=  "<a id='e10-logout-full-button' class='appMenuButton' title='".DictSystem::es(DictSystem::diBtn_Logout)."' href='$logoutUrl'>".$this->app()->ui()->icon('system/actionLogout')."</a>";

			if ($this->app->mobileMode)
			{
				$portalDomain =  $this->app->cfgItem ('dsi.portalInfo.portalDomain', '');
				$userInfo .=  "<span id='e10-logout-close-button' class='appMenuButton df2-panelaction-trigger' data-action='go' title='Ukončit' data-href='".'https://'.$portalDomain."' style='display: none;'><i class='fa fa-times'></i></span>";
			}
			$userInfo .=  "<span id='e10-nc-button' class='appMenuButton e10-ntf-button' title='Oznamovací centrum'>";
			$userInfo .=  $this->app()->ui()->icon('system/actionNotifications')." <span style='width: 2em; display: inline-block; text-align: right;' id='e10-nc-button-cn'></span>";
			$userInfo .=  "<span style='display: inline-block; padding-left: 1ex; display: none;' id='e10-nc-button-ra'> <i class='fa fa-play e10-success'></i></span>";
			$userInfo .= '</span>';

			if ($this->app->cfgItem ('develMode', 0) !== 0)
				$userInfo .=  '<span>'.$this->app()->ui()->icon('system/iconBug', 'e10-error').'</span>';
			if (!$this->app->production())
				$userInfo .=  "<span class='e10-bg-t4 e10-error' title=\"".utils::es('Toto je testovací daatabáze')."\">".$this->app()->ui()->icon('system/iconLaboratory')."</span>";
			$userInfo .=  '</li>';

			$userInfo .=  '</ul>';
		}

		$leftStatus = '';

		$appWarning = $this->app->cfgItem ('dsi.appWarning', FALSE);
		if ($appWarning)
			$leftStatus .= "<div class='e10-app-warning'>".$this->app()->ui()->composeTextLine($appWarning).'</div>';

		$leftStatus .= "<ul class='appMenu appMenuLeft'>";

		$leftStatus .= "<li class='appMenuIcon'>";
		$leftStatus .=  "<span id='e10-mm-button' class='appMenuButton' style='border-left: none;'>&nbsp;";
		$leftStatus .= $this->app()->ui()->icons()->icon ('system/iconAppInfoMenu').'&nbsp;';
		$leftStatus .= '</span>';
		$leftStatus .= '</li>';

		$allMenu = $this->app->topMenuAll ();
		forEach ($allMenu as $mi)
		{
			if (isset($mi['hidden']))
				continue;
			$pmclass = '';
			if ($mi['url'] == $this->app->requestPath ())
				$pmclass = ' active';
			$icon = '';
			if (isset($mi['icon']))
				$icon = '&nbsp;'.$this->app()->ui()->icons()->icon ($mi['icon']).'&nbsp;';

			$leftStatus .= "<li class='panel$pmclass'>";
			if ($this->app->mobileMode)
				$leftStatus .= "<span class='df2-panelaction-trigger appMenuButton' data-action='go' data-href='{$this->app->urlRoot}{$mi['url']}'>".$icon.utils::es($mi['name']).'</span>';
			else
				$leftStatus .= "<a href='{$this->app->urlRoot}{$mi['url']}'>".$icon.utils::es($mi['name']).'</a>';
			$leftStatus .= '</li>';
		}

		$leftStatus .= '</ul>';


		$ncCode = "<div id='mainBrowserNC' class='e10-nc close'>";
		$ncCode .= "<div class='e10-nc-toolbar'><span id='e10-nc-close' class='pull-right'>".$this->app()->ui()->icon('system/actionClose')." Zavřít</span></div>";

		$ncCode .= "<div class='e10-nc-viewer e10-widget-pane' data-widget-class='e10.base.NotificationCentre'></div>";
		$ncCode .= '</div>';

		$rightBarStyle = ($rightBarCode !== '' && $enableAppMenu) ? " style='width: 20vw;'" : '';

		$myCode = $this->app->createPageCodeOpen($page, TRUE);
		$myCode .= "<div id='mainBrowser' class='e10-appNormal {$this->leftBarClass}' style='width: 100%;'>";

		if ($enableAppMenu)
			$myCode .= "<div id='mainBrowserAppMenu'>$leftStatus$userInfo</div>";

		$myCode .= "<div id='mainBrowserTopBar'>" . $topMenu . '</div>';

		// -- left menu
		$myCode .= "<div id='mainBrowserLeftMenu' class='{$this->leftBarClass}'>";
		$myCode .= $this->leftBarCode;
		$myCode .= '</div>';

		$myCode .= "<div id='mainBrowserLeftMenuSubItems' class='closed'>";
		$myCode .= $this->leftSubMenusCode;
		$myCode .= '</div>';

		$myCode .= "<div id='mainBrowserContent'></div>";
		$myCode .= "<div id='mainBrowserRightBar'{$rightBarStyle}>
											<div id='mainBrowserRightBarTop'>$rightBarCode</div>
											<div id='mainBrowserRightBarDetails'></div>
											<div id='mainBrowserRightBarButtonsAdd'></div>
											<div id='mainBrowserRightBarButtonsEdit'></div>
										</div>";
		$myCode .= "</div>";
		$myCode .= $ncCode;
		$myCode .= $this->mainMenuCode();
		$myCode .= $this->app->createPageCodeClose($page);

		return $myCode;
	} // createBrowserCode

	function wssCode($wss)
	{
		$c = '';

		foreach ($wss as $srv)
		{
			$title = utils::es($srv['name']);
			$c .= "<li class='e10-wss e10-wss-none' id='wss-{$srv['id']}' title=\"$title\">";
			$c .= '<span>'.$this->app()->ui()->icon($srv['icon']).'</span>';
			$c .= '</li>';
		}

		$sce = new \mac\iot\libs\SensorsAndControlsEngine($this->app);
		$sce->load();

		foreach ($sce->mainMenu as $mmi)
		{
			$c .= "<li>";
			$c .= $mmi['code'];
			$c .= '</li>';
		}

		return $c;
	}

	function mainMenuCode ()
	{
		$dsMode = 1;

		$dsId = $this->app->cfgItem ('dsid', 0);

		if ($dsId == '50842906798390')
			$dsMode = 0;

		$si = $this->app->cfgItem ('serverInfo', 0);
		$dsi = $this->app->cfgItem ('dsi');
		$dsName = $this->app->cfgItem ('dsi.name', '');
		$dsIcon = $this->app->dsIcon();
		$dsImg = $dsIcon['serverUrl'].$dsIcon['fileName'];
		$userInfo = $this->app->user()->data();
		$userImg = $this->app->user()->data('picture');

		//$portalDomain =  $this->app->cfgItem ('dsi.portalInfo.portalDomain', '');
		$supportImg =  $this->app->cfgItem ('dsi.portalInfo.logoPortal.fileName', '');
		$partnerImg =  $this->app->cfgItem ('dsi.partner.logoPartner.fileName', '');
		if ($partnerImg !== '')
			$supportImg = $partnerImg;

		$bottomImgUrl = 'https://org.shipard.app/att/2021/05/05/wkf.docs.documents/logo-header-web-light-206ry0b.svg';

		$c = "<div id='mainBrowserMM' class='e10-mm close'>";

		if ($dsMode)
		{
			$emailBase = strval ($dsId);
			if (isset($dsi['dsId1']) && $dsi['dsId1'] !== '')
				$emailBase = $dsi['dsId1'];
			$emailDomain = isset($dsi['portalInfo']['emailDomain']) ? $dsi['portalInfo']['emailDomain'] : 'shipard.email';

			$c .= "<ul class='e10-mm-toolbar e10-mm-list'>";
			$c .= "<li style='width: 5em; text-align: center;'><img src='{$dsImg}' style='width: 4em; max-width: 4em; max-height: 4em;'/></li>";
			$c .= '<li>';
			$c .= "<div class='h1'>" . utils::es($dsName) . '</div>';
			$c .= "<div class='e10-off' title=\"".utils::es('emailová adresa vaší došlé pošty')."\"><i class='fa fa-inbox'></i> ".utils::es($emailBase.'@'.$emailDomain).'</div>';
			$c .= '</li>';

			$c .= "<li id='e10-mm-close'>".$this->app()->ui()->icon('system/actionClose')."</li>";
			$c .= "</ul>";
		}

		$c .= "<ul class='e10-mm-user e10-mm-list'>";
		$c .= "<li style='width: 5em;text-align: center;'><div style='background-image: url({$userImg}); background-size: cover; width: 4em; height: 4em; border: 1px solid rgba(0,0,0,.25); border-radius: 50%;'/></li>";
		$c .= "<li>";
		$c .= "<div class='h2'>".utils::es($userInfo['name'])."</div><span class='e10-small'>".utils::es($userInfo['login']);
		$c .= '</span>'.'</li>';
		if (!$dsMode)
			$c .= "<li id='e10-mm-close'>".$this->app()->ui()->icon('system/actionClose')."</li>";
		$c .= "</ul>";

		// -- help
		if ($dsMode)
		{
			$helpUrl = 'https://shipard.org/';
			$c .= "<ul class='e10-mm-list e10-mm-help'>";
			$c .= "<li style='width: 5em; text-align: center;'><img src='".$this->app()->scRoot()."/shipard/graphics/icon-page-help.svg' style='width: 80%; padding-top: 1ex;'></li>";
			$c .= "<li style='line-height: 1.8;'>";
			$c .= "<div class='h2'>" . utils::es('Nápověda') . "</div>";

			$helpHeader = $this->app->cfgItem('help.index.header', FALSE);
			if ($helpHeader) {
				$c .= "<div>";
				foreach ($helpHeader as $hi) {
					$i = $this->app()->ui()->icon($hi['icon'] ?? 'system/iconFile');
					$c .= "<a class='nowrap' href='$helpUrl{$hi['url']}' target='_new'>{$i}&nbsp;" . utils::es($hi['title']) . "</a> ";
				}
				$c .= '</div>';
			}

			$helpIndex = $this->app->cfgItem('help.index.panels.' . $this->app->panel['url'], FALSE);
			if ($helpIndex) {
				foreach ($helpIndex as $hi) {
					$i = $this->app()->ui()->icon($hi['icon'] ?? 'system/iconFile');
					$c .= "<a class='nowrap' href='$helpUrl{$hi['url']}' target='_new'>{$i}&nbsp;" . utils::es($hi['title']) . "</a> ";
				}
			}
			$c .= '</li>';
			$c .= '</ul>';
		}

		// -- support
		if ($dsMode)
		{
			//$supportUrl = 'https://' . $portalDomain . '/';
			$c .= "<ul class='e10-mm-list'>";
			$c .= "<li style='width: 5em; text-align: center;'><img src='".$this->app()->scRoot()."/shipard/graphics/icon-page-support.svg' style='width: 80%; padding-top: 1ex;'></li>";
			$c .= "<li style='line-height: 1.8;'>";
			//$c .= "<div class='h2'>" . utils::es('Podpora') . "</div>";

			/*
			if (isset($dsi['supportSection']) && $dsi['supportSection'])
			{
				$supportUrl = 'https://system.shipard.app/';
				$sectionNdx = $dsi['supportSection'];

				$newIssueAddParams = "__issueType=0&__issueKind=3&__section=" . $sectionNdx;
				$c .= "<button class='btn btn-primary e10-document-trigger' data-action='new' data-table='wkf.core.issues' data-open-as='1' data-open-url-prefix='$supportUrl' data-addparams='$newIssueAddParams' onclick='$(\"#e10-mm-button\").click();'><i class='fa fa-bug'></i> Nahlásit problém</button>";
				//$c .= " <a class='btn btn-info' href='$supportUrl' target='_blank'><i class='fa fa-bullhorn'></i> Fórum podpory</a>";
			}
			else*/
			{
				/*
				$reportProblemButtons = $this->app->cfgItem('wkf.reportProblemButtons', []);
				foreach ($reportProblemButtons as $rp)
				{
					$addParams = '__section=' . $rp['section'] . '&__issueKind=' . $rp['issueKind'] . '&__issueType=' . $rp['issueType'];
					$c .= "<button class='btn df2-button-trigger btn btn-primary e10-document-trigger btn-primary' data-action='new' data-table='wkf.core.issues' data-addparams='$addParams' onclick='$(\"#e10-mm-button\").click();'><i class='fa-fw fa fa-bug'></i> Nahlásit problém</button>";
				}
				*/
			}
			$c .= "<div class='h2'>" . utils::es('O vaši podporu se stará') . '</div>';
			$c .= "<span class='nowrap'>".$this->app()->ui()->icon('system/iconOwner'). " <a href='{$dsi['supportUrl']}' target='_blank'>" . utils::es($dsi['supportName']) ."</a></span>&nbsp;<br>";
			$c .= "<span class='nowrap'>".$this->app()->ui()->icon('user/envelope')." <a href='mailto:{$dsi['supportEmail']}'>" . utils::es($dsi['supportEmail']) . "</a></span>&nbsp;";
			$c .= "<span class='nowrap'>".$this->app()->ui()->icon('system/iconPhone') . utils::es($dsi['supportPhone']) . "</span>";
			if ($dsi['supportName'] !== 'Shipard')
				$c .= "<img src='https://shipard.app/att/$supportImg' style='width:100%; max-height: 5em; text-align: center; margin-top: 1ex; margin-bottom: .5ex;'>";

			$c .= '</li>';
			$c .= '</ul>';
		}

		$c .= "<div class='e10-mm-dsInfo'>";
		$c .= "<small'>" . utils::es('powered by') . '</small>';
		$c .= "<a href='https://shipard.org/' target='_blank'><img src='$bottomImgUrl' style='width:100%; max-height: 1.8em; text-align: center; margin-top: .2ex;'></a>";
		$c .= "<small>";
		$c .= utils::es('Verze '.__E10_VERSION__.'.'.$si['e10commit']);
		$c .= ($this->app->mobileMode) ? '.m' : '.d';
		$c .= ".<span class='visible-xs-inline'>xs</span><span class='visible-sm-inline'>sm</span><span class='visible-md-inline'>md</span><span class='visible-lg-inline'>lg</span>";
		$c .= utils::es('.'.$si['channelId']);
		$c .= '&nbsp;#'.utils::es($dsId);
		$c .= '</small>';
		$c .= '</div>';

		$c .= '</div>';

		return $c;
	}

	function createBrowserCodeEmbedd ($page)
	{
		//$actionClass = $this->app->requestPath (2);
		$table = $this->app->requestPath (3);
		$action = $this->app->requestPath (4);
		$pk = intval($this->app->requestPath (5));

		$params = " data-table='{$table}' data-action='{$action}'";
		if ($pk !== 0)
			$params .= " data-pk='{$pk}'";

		// -- addParams
		$addParams = '';
		foreach ($_GET as $c => $v)
		{
			if (strpos ($c, '__') !== 0)
				continue;
			if ($addParams !== '')
				$addParams .= '&';
			$addParams .= $c.'='.$v;
		}

		if ($addParams !== '')
			$params .= " data-addparams='$addParams'";

		$page['params']['bodyClass'] = 'e10-appEmbed';

		$e10window = $this->app->testGetParam ('e10window');

		if ($e10window === 'popup')
			$page['params']['bodyClass'] .= ' e10-appEmbedPopup';
		elseif ($e10window === 'app-iframe')
			$page['params']['bodyClass'] .= ' e10-appEmbedAppIframe';

		$myCode = $this->app->createPageCodeOpen($page, TRUE);
		$myCode .= "<div id='mainBrowser' class='{$page['params']['bodyClass']}' style='width: 100%;'$params>";
		$myCode .= "<div id='mainBrowserAppMenu'></div>";
		$myCode .= "<div id='mainBrowserTopBar'>" . '</div>';

		if ($e10window === 'app-iframe')
		{
			$dsIcon = $this->app->dsIcon();
			$dsImg = $dsIcon['serverUrl'].$dsIcon['fileName'];
			$ownerName = $this->app->cfgItem ('options.core.ownerShortName', '');

			$myCode .= "<div id='mainBrowserLeftMenu'>";
			$myCode .= "<div style='opacity:.75; font-size:2em;transform: rotate(-90deg); position: absolute; bottom: 0; white-space: pre; text-align: center;width: 100%;'>";
			$myCode .= "&nbsp;<img src='{$dsImg}' style='width:100%; margin-right: 1ex;'/>";
			$myCode .= utils::es($ownerName);
			$myCode .= '</div>';
			$myCode .= '</div>';
		}

		$myCode .= "<div id='mainBrowserContent'></div>";
		$myCode .= "</div>";
		$myCode .= $this->app->createPageCodeClose($page);

		return $myCode;
	}

	function createLeftBarCode ($menuPreCode = '', $widgetFromUrl = FALSE)
	{
		$this->leftBarCode = '';
		$this->leftSubMenusCode = '';

		if (isset($this->panel['mode']) && $this->panel['mode'] === 1)
		{
			$this->leftBarClass = 'e10-app-lb-panel';
			$this->leftBarCode .= $this->createLeftBarContentCode();
		}
		else
		{
			$this->createMenuItemsCode($this->leftBarCode, $this->leftSubMenusCode, $menuPreCode, $widgetFromUrl);
		}
	}

	public function createMenuItemsCode (&$menuCode, &$subMenusCode, $menuPreCode = '', $widgetFromUrl = FALSE)
	{
		$c = '';

		// TODO: remove?
		$c .= "<div id='mainBrowserLeftMenuHeader'>";
		$c .= '</div>';
		$c .= $menuPreCode;

		$menuItems = array_merge($this->app->panelMenu(), $this->app->panelMenu('smallItems'));
		$cntBigIcons = isset($this->panel['cntBigIcons']) ? $this->panel['cntBigIcons'] : 6;

		$cnt = 0;
		$small = FALSE;
		$c .= "<ul class='df2-viewer-menu' id='mainListViewMenu'>";
		if ($widgetFromUrl)
		{
			$oneItem = [];
			switch ($this->app->requestPath [3])
			{
				case 'viewer':
					{
						$oneItem['object'] = 'viewer';
						$oneItem['table'] = $this->app->requestPath [4];
						//$oneItem['viewer'] = $this->app->requestPath [5];
						$this->createMenuItemsCode_IdWithParams ($this->app->requestPath [5], 'viewer', $oneItem);
					}
					break;
				case 'reports':
					{
						$oneItem['object'] = 'widget';
						$oneItem['class'] = 'Shipard.Report.WidgetReports';
						$oneItem['subclass'] = $this->app->requestPath [4];
					}
					break;
				case 'dashboard':
					{
						$oneItem['object'] = 'widget';
						$oneItem['class'] = 'e10.widgetDashboard';
						$oneItem['subclass'] = $this->app->requestPath [4];
					}
					break;
				case 'report':
					{
						$oneItem['object'] = 'widget';
						$oneItem['class'] = 'Shipard.Report.WidgetReports';
						$oneItem['subtype'] = 'oneReport';
						$oneItem['subclass'] = $this->app->requestPath [4];
					}
					break;
			}
			$c .= $this->menuRowCode($oneItem, FALSE);
		}
		else
		{
			foreach ($menuItems as $oneItem)
			{
				if ($cnt === $cntBigIcons)
				{
					$c .= "</ul>";
					$c .= "<ul class='e10-panelMenu-small' id='smallPanelMenu'>";
					$small = TRUE;
				}
				$c .= $this->menuRowCode($oneItem, $small);

				if (isset($oneItem['subMenu']) && isset($oneItem['subMenu']['items']) && count($oneItem['subMenu']['items']))
				{
					$subMenusCode .= "<div class='e10-panelSubMenu' id='{$oneItem['miUid']}'>";
					$subMenusCode .= "<ul>";
					foreach ($oneItem['subMenu']['items'] as $smId => $sm)
					{
						if (!$this->app()->checkAccess ($sm))
							continue;
						if (!utils::enabledCfgItem ($this->app, $sm))
							continue;
						$subMenusCode .= $this->menuRowCode($sm);
					}
					$subMenusCode .= '</ul>';
					$subMenusCode .= '</div>';
				}

				$cnt++;
			}
		}
		$c .= "</ul>";

		$menuCode .= $c;
	}

	function createMenuItemsCode_IdWithParams ($srcId, $key, &$dstItem)
	{
		$parts = explode (';', $srcId);
		if (!count($parts))
			return;
		$dstItem[$key] = $parts[0];
		unset ($parts[0]);
		if (!count($parts))
			return;

		$params = [];
		foreach ($parts as $oneParam)
		{
			$pp = explode (':', $oneParam);
			if (count($pp) !== 2)
				continue;

			$params[] = $pp[0].':'.$pp[1];
		}

		if (count($params))
			$dstItem['params'] = implode (';', $params);
	}

	public function createToolbar ()
	{
		if (isset ($this->panel ['buttons']))
			return $this->panel ['buttons'];
		return NULL;
	}

	public function createToolbarCode ()
	{
		$tlbr = $this->createToolbar ();
		if (!$tlbr)
			return '';

		$c = '';
		foreach ($tlbr as $btn)
		{
			if ($btn['type'] == 'code')
			{
				$c .= $btn['code'];
			}
			elseif (isset($btn['type']) && $btn['type'] === 'panelaction')
			{
				$class = '';
				$icon = (isset($btn['icon'])) ? $this->app()->ui()->icon($btn['icon']).'&nbsp;' : '';
				$class = (isset($btn['class'])) ? " btn-{$btn['class']}" : '';
				$btnText = $btn['text'];
				$c .= "<button class='btn btn-large$class df2-{$btn['type']}-trigger' data-action='{$btn['action']}'>{$icon}{$btnText}</button>";
			}
			else
			{
				$c .= $this->app()->ui()->composeTextLine($btn);
			}
		}
		return $c;
	}

	function menuRowCode ($listItem, $small = FALSE, $panelMode = FALSE, $level = 0)
	{
		$codeLine = '';

		if ($panelMode && isset($listItem ['groupTitle']))
		{
			if (isset($listItem['beforeSeparator']))
			{
				$codeLine .= "<li class='e10-menu-separator padd5'><hr/>";
				if (is_array($listItem['beforeSeparator']))
					$codeLine .= $this->app()->ui()->composeTextLine($listItem['beforeSeparator']);
			}

			$codeLine .= "<li class='level{$level} e10-app-lb-panel-group closed'>";
			$codeLine .= "<span class='title'>";

			if (isset ($listItem ['icon']))
			{
				$codeLine .= $this->app()->ui()->icon($listItem ['icon'], '', 'span');
			}
			elseif (isset ($listItem ['icontxt']))
			{
				$codeLine .= "<span>{$listItem ['icontxt']}</span>";
			}

			$codeLine .= utils::es($listItem ['groupTitle']);

			$codeLine .= '</span>';

			$codeLine .= "<ul>";
			foreach ($listItem['items'] as $groupItem)
			{
				$codeLine .= $this->menuRowCode($groupItem, FALSE, TRUE, $level + 1);
			}
			$codeLine .= "</ul>";

			$codeLine .= '</li>';
			return $codeLine;
		}

		if (isset($listItem['beforeSeparator']))
		{
			$codeLine .= "<li class='e10-menu-separator padd5'><hr/>";
			if (is_array($listItem['beforeSeparator']))
				$codeLine .= $this->app()->ui()->composeTextLine($listItem['beforeSeparator']);
			$codeLine .= "</li>";
		}

		$codeLine .= "<li";

		$elementClass = 'mi level'.$level;
		$codeLine .= " class='$elementClass'";

		if (isset ($listItem ['object']))
			$codeLine .= " data-object='{$listItem['object']}'";

		if (isset ($listItem ['table']))
			$codeLine .= " data-table='{$listItem['table']}'";

		if (isset ($listItem ['viewer']))
			$codeLine .= " data-func='{$listItem['viewer']}'";

		if (isset ($listItem ['class']))
			$codeLine .= " data-class='{$listItem['class']}'";

		if (isset ($listItem ['subclass']))
			$codeLine .= " data-subclass='{$listItem['subclass']}'";

		if (isset ($listItem ['subtype']))
			$codeLine .= " data-subtype='{$listItem['subtype']}'";

		if (isset ($listItem ['remote']))
			$codeLine .= " data-remote='{$listItem['remote']}'";

		if (isset ($listItem ['hint']))
			$codeLine .= " class='{$listItem['hint']}'";

		if (isset ($listItem ['params']))
			$codeLine .= " data-object-params='{$listItem['params']}'";

		if ($small)
			$codeLine .= " title='".utils::es ($listItem ['t1'])."'";

		if (isset ($listItem ['miUid']))
			$codeLine .= " data-mi-uid='{$listItem['miUid']}'";

		$codeLine .= ">";

		if (isset($listItem['ntfBadgeId']))
			$codeLine .= "<span class='e10-ntf-badge' id='{$listItem['ntfBadgeId']}' style='display:none;'></span>";

		if ($panelMode)
		{
			$codeLine .= "<span class='title'>";

			if (isset ($listItem ['icon']))
				$codeLine .= $this->app()->ui()->icon($listItem ['icon'], 'icon', 'span');
			elseif (isset ($listItem ['icontxt']))
				$codeLine .= "<span class='icon'>{$listItem ['icontxt']}</span> ";

			$codeLine .= utils::es ($listItem ['t1']);

			$codeLine .= '</span>';
		}
		else
		{
			if (isset ($listItem ['icontxt']))
				$codeLine .= "<div class='i'>{$listItem ['icontxt']}</div> ";
			else
			{
				$icon = $listItem['icon'] ?? '';
				if ($icon === '' && isset($listItem['table']))
					$icon = 'tables/' . $listItem['table'];
				$codeLine .= $this->app()->ui()->icons()->icon($icon, 'i', 'div');
			}
			if (!$small && !$panelMode && isset($listItem ['t1']))
				$codeLine .= "<div class='t'>".utils::es ($listItem ['t1']).'</div>';
		}


		$codeLine .= "</li>\n";

		return $codeLine;
	}

	function createLeftBarContentCode ()
	{
		$c = '';

		$c .= "<div id='mainBrowserLeftMenuHeader'>";
		$c .= '</div>';

		$menuItems = array_merge($this->app->panelMenu(), $this->app->panelMenu('smallItems'));

		$c .= "<ul class='df2-viewer-menu' id='mainListViewMenu'>";
		$c .= $this->createLeftBarContentCodeBlock($menuItems, 0);
		$c .= "</ul>";

		return $c;
	}

	function createLeftBarContentCodeBlock($menuItems, $level)
	{
		$c = '';
		$cnt = 0;
		foreach ($menuItems as $oneItem)
		{
			$c .= $this->menuRowCode($oneItem, FALSE, TRUE, $level);
			$cnt++;
		}

		return $c;
	}
}
