<?php

namespace Shipard\UI\OldMobile;

use E10\utils;


/**
 * Class StartMenu
 * @package mobileui
 */
class StartMenu extends \Shipard\UI\OldMobile\PageObject
{
	public function createContent ()
	{
		$this->content['start'] = ['name' => 'Start', 'type' => 'tiles', 'order' => 100000, 'items' => []];

		// -- mobileui
		$ui = \e10\sortByOneKey($this->app->appSkeleton ['mobileui'], 'order', TRUE);
		foreach ($ui as $tabId => $tabContent)
		{
			if (isset($tabContent['role']) && !$this->app->hasRole($tabContent['role']))
				continue;

			if (!isset($this->content[$tabId]))
				$this->content[$tabId] = ['type' => $tabContent['type'], 'order' => ($tabContent['order']) ? $tabContent['order'] : 1, 'groups' => []];

			if (isset($tabContent['name']) && $tabContent['name'] !== '')
				$this->content[$tabId]['name'] = $tabContent['name'];

			if (isset($tabContent['items']))
			{
				foreach ($tabContent['items'] as $itemId => $item)
				{
					if (isset ($item['enabledCfgItem']) && $this->app->cfgItem($item['enabledCfgItem'], 0) == 0)
						continue;
					if ($item['object'] !== 'dashboard' && $item['object'] !== 'action' && $item['object'] !== 'maps' && !$this->app->checkAccess($item))
						continue;
					if (isset($item['role']) && !$this->app->hasRole($item['role']))
						continue;

					$this->content[$tabId]['items'][$itemId] = $item;
				}
			}
			if (isset($tabContent['groups']))
			{
				foreach ($tabContent['groups'] as $groupId => $groupContent)
				{
					if (isset($groupContent['hidden']))
						continue;
					$this->content[$tabId]['groups'][$groupId] = [
							'name' => isset($groupContent['name']) ? $groupContent['name'] : NULL, 'order' => $groupContent['order'], 'items' => []
					];
					foreach ($groupContent['items'] as $itemId => $item)
					{
						if (isset ($item['enabledCfgItem']) && $this->app->cfgItem($item['enabledCfgItem'], 0) == 0)
							continue;
						if (!$this->app->checkAccess($item))
							continue;

						$this->content[$tabId]['groups'][$groupId]['items'][$itemId] = $item;
					}
				}
			}

			if (isset($this->content[$tabId]['groups']) && !count($this->content[$tabId]['groups']))
				unset($this->content[$tabId]['groups']);
			if (isset($this->content[$tabId]['items']) && !count($this->content[$tabId]['items']))
				unset($this->content[$tabId]['items']);
		}

		// -- start tiles
		$listsClasses = $this->app->cfgItem ('registeredClasses.startMenu', []);
		foreach ($listsClasses as $class)
		{
			if (isset ($class['role']) && !$this->app->hasRole($class['role']))
				continue;
			$classId = $class['classId'];
			$listObject = $this->app->createObject($classId);
			if (!$listObject)
				continue;

			$listObject->create($this->content);
		}

		// -- user info
		$this->pageInfo['userInfo'] = ['name' => $this->app->user()->data('name'), 'login' => $this->app->user()->data('login')];
	}

	public function createContentCodeInside ()
	{
		$c = '';

		foreach ($this->content as $tabId => $tabContent)
		{
			$tabType = isset ($tabContent['type']) ? $tabContent['type'] : 'none';
			$c .= "<div class='e10-tab-{$tabType}' id='e10-page-tab-$tabId-c' style='display: none;'>";

			if ($tabType === 'tiles')
				$c .= $this->createContentCodeTiles($tabId, $tabContent);
			else
			if ($tabType === 'menu')
				$c .= $this->createContentCodeMenu($tabId, $tabContent);

			$c .= '</div>';
		}

		return $c;
	}

	public function createContentCodeTiles ($tabId, $content)
	{
		$c = '';
		$c .= "<div class='e10-gs-row full'>";

		foreach (\e10\sortByOneKey($content['items'], 'order', TRUE) as $tileId => $tile)
		{
			if (isset($tile['header']))
			{
				//$c .= "<div class='e10-gs-row full'>";
				$c .= "<div class='e10-gs-col e10-gs-col12'><div class='title'>".utils::composeTextLine($tile['header']).'</div></div>';
				//$c .= '</div>';
				continue;
			}
			if (isset ($tile['dashboard']))
			{
				$c .= $this->createContentCodeTileDashboard($tileId, $tile);
				continue;
			}

			if (isset ($tile['width']))
				$tileClass = 'e10-gs-col'.$tile['width'];
			else
				$tileClass = 'e10-gs-col6';
			$c .= "<div class='e10-gs-col $tileClass'>";

			if (isset ($tile['code']))
			{
				$c .= $tile['code'];
			}
			else
			{
				$dataPath = FALSE;

				if (isset($tile['path']))
					$dataPath = $tile['path'];
				else
					$dataPath = $tabId . '.' . $tileId;

				$mainClass = 'link';

				if ($tile['object'] === 'action')
					$mainClass = 'e10-trigger-action';

				if (isset($tile['type']))
					$mainClass .= ' ' . $tile['type'];
				$params = '';

				$params .= utils::dataAttrs ($tile);

				$tileType = isset($tile['type']) ? $tile['type'] : 'tile';
				$c .= "<div class='e10-startMenu-tile $tileType $mainClass'$params ";
				$c .= "data-path='$dataPath'";
				$c .= '>';

				$c .= "<ul>";

				$c .= "<li class='left'>";
				if (isset($tile['icon']))
					$c .= $this->app()->ui()->icon($tile['icon']);
				elseif (isset($tile['table']))
					$c .= $this->app()->ui()->icon('tables/'.$tile['table']).' ';
				$c .= '</li>';

				$c .= "<li class='content'>";
				$c .= "<div class='title1'>" . $this->app()->ui()->composeTextLine($tile['t1']) . '</div>';
				if (isset ($tile['info']))
				{
					foreach ($tile['info'] as $i)
						$c .= "<div class='info'>" . $this->app()->ui()->composeTextLine($i) . '</div>';
				}
				$c .= '</li>';

				if (isset($tile['buttons']))
				{
					foreach ($tile['buttons'] as $btn)
					{
						if (isset($btn['native']) && $this->app->clientType [1] !== 'cordova')
							continue;

						$c .= "<li class='right e10-trigger-action'";
						$c .= utils::dataAttrs ($btn);
						$c .= '>';
						if (isset($btn['icon']))
							$c .= $this->app()->ui()->icon($btn['icon']);
						elseif (isset($btn['table']))
							$c .= $this->app()->ui()->icon('tables/'.$btn['table']).' ';
						$c .= '</li>';
					}
				}

				$c .= "</ul>";

				$c .= "</div>";
			}
			$c .= "</div>";
		}

		$c .= '</div>';

		return $c;
	}

	public function createContentCodeMenu ($tabId, $content)
	{
		$c = '';

		foreach (\e10\sortByOneKey($content['groups'], 'order', TRUE) as $groupId => $groupContent)
		{
			if (count($groupContent['items']) === 0)
				continue;

			if (isset($groupContent['name']))
			{
				$c .= "<div class='e10-gs-row full'>";
				$c .= "<div class='e10-gs-col e10-gs-col12'><div class='title'>" . utils::es($groupContent['name']) . '</div></div>';
				$c .= '</div>';
			}

			$c .= "<div class='e10-gs-row full'>";
			foreach (\e10\sortByOneKey($groupContent['items'], 'order', TRUE) as $tileId => $tile)
			{
				if (isset ($tile['dashboard']))
				{
					$c .= $this->createContentCodeTileDashboard($tileId, $tile);
					continue;
				}

				if (isset ($tile['width']))
					$tileClass = 'e10-gs-col'.$tile['width'];
				else
					$tileClass = 'e10-gs-col6';

				$c .= "<div class='e10-gs-col $tileClass'>";

				$dataPath = $tabId.'.'.$groupId.'.'.$tileId;
				$c .= "<div class='e10-startMenu-button link' data-path='$dataPath'>";

				if (isset($tile['icon']))
					$c .= $this->app()->ui()->icon($tile['icon']).' ';
				elseif (isset($tile['table']))
					$c .= $this->app()->ui()->icon('tables/'.$tile['table']).' ';

				$c .= utils::es($tile['t1']);

				$c .= "</div>";
				$c .= "</div>";
			}
			$c .= '</div>';
		}

		if (!$this->appMode)
			$c .= '<script>$(function () {$("body").css("margin-top", $("#e10-page-header").outerHeight())});</script>';

		return $c;
	}

	public function createContentCodeTileDashboard ($tileId, $tile)
	{
		$c = '';
		$c .= "<div class='e10-gs-col e10-gs-col12'>";

		$widgets = $this->app->cfgItem('dashboards.mobile.'.$tile['dashboard']);
		foreach ($widgets as $w)
		{
			$widgetClass = $w['class'];

			$widget = $this->app->createObject($widgetClass);
			if (!$widget)
				continue;
			if (!$widget->checkAccess ($widgetClass))
				continue;

			$widget->setDefinition($widgetClass);
			$widget->init();
			$widget->createContent();
			if (!$widget->isBlank())
			{
				$class = '';
				$params = '';
				$isLink = $widget->linkParams ();
				if ($isLink)
				{
					$class = ' link';
					$params = ' '.$isLink;
				}
				$c .= "<div class='e10-startMenu-button e10-startMenu-widget{$class}'{$params} id='{$widget->widgetId}' data-widget-class='{$widgetClass}'>";
				$c .= $widget->renderContent();
				$c .= '</div>';
			}
		}

		$c .= '</div>';

		return $c;
	}

	public function createContentCodeBegin ()
	{
		$c = '';
		return $c;
	}

	public function createContentCodeEnd ()
	{
		$c = '';
		return $c;
	}

	public function title1 ()
	{
		return $this->app->cfgItem ('options.core.ownerShortName');
	}

	public function title2 ()
	{
		return $this->app->user()->data('name');
	}

	public function leftPageHeaderButton ()
	{
		$lmb = ['icon' => 'system/iconHamburgerMenu', 'action' => 'app-menu'];
		return $lmb;
	}

	public function rightPageHeaderButtons ()
	{
		$rmbs = [];
		$b = ['icon' => 'system/actionLogout', 'action' => 'app-logout'];
		$rmbs[] = $b;

		return $rmbs;
	}

	public function pageTabs ()
	{
		$tabs = [];

		foreach ($this->content as $tabId => $tabContent)
		{
			if ((!isset($tabContent['groups']) || !count($tabContent['groups'])) && (!isset($tabContent['items'])||!count($tabContent['items'])))
				continue;

			$tabs[$tabId] = [
				'text' => isset($tabContent['name']) ? $tabContent['name'] : 'Q:',
				'icon' => 'system/iconFile'];
		}

		return $tabs;
	}

	public function createPageBodyParams ()
	{
		return 'onhashchange="e10.pageTabsInit(1);return true;"';
	}

	public function pageTitle()
	{
		return $this->title1();
	}

	public function pageType () {return 'home';}
}

