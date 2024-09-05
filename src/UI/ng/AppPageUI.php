<?php

namespace Shipard\UI\ng;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;
use \e10\ui\TableUIs;


/**
 * class AppPageUI
 */
class AppPageUI extends \Shipard\UI\ng\AppPageBlank
{
  var \e10\ui\TableUIs $tableUIs;
  var $uiStruct = NULL;

  CONST amtSimple = 0, amtComplex = 1;
  var $appMenuType = self::amtSimple;

  protected function init()
  {
    $this->tableUIs = new \e10\ui\TableUIs($this->app());

    if (isset($this->uiCfg['ndx']) && $this->uiCfg['ndx'])
    {
      $this->uiRecData = $this->tableUIs->loadItem($this->uiCfg['ndx']);
    }
  }

  protected function createContentCodeInside_Template()
  {
    $template = $this->uiTemplate;

    $template->data['url_path_'.$this->uiRouter->urlPart(0)] = 1;
    $template->data['url_path_'.$this->uiRouter->urlPart(0).'_active'] = ' active';
    $template->data['userImg'] = $this->app()->user()->data('picture');
    $template->data['wss'] = array_values($this->wss);
    $template->data['wssDefaultNdx'] = intval(key($this->wss));
    $template->loadTemplate ('e10pro.templates.basic', 'page.mustache', $this->uiRecData['template']);

    $c = $template->renderTemplate();

    $template->checkUIData();

    $c .= "<script>";
    $c .= "var uiData = ".Json::lint($template->uiData).";";
    $c .= "(() => {shc.iot.initIoT ();})();";
    $c .= "</script>";

    return $c;
  }

  protected function createContentCodeInside_UIStruct()
  {
    $templateCode = '{{{@appUIElement}}}';
    $urlId = $this->uiRouter->urlPath[0] ?? '';

    if ($urlId === '' && isset($this->uiStruct['appMenu']))
    {
      $redirToId = $this->uiStruct['appMenu']['defaultMenuId'] ?? $this->uiStruct['appMenu']['items'][0]['id'];
      $redirTo = str_replace('//', '/', $this->uiTemplate->data['uiRoot'].$redirToId);
      header('Location: ' . $redirTo);
      die();
    }

    $template = $this->uiTemplate;

    $template->data['url_path1'] = $this->uiRouter->urlPart(0);
    $template->data['url_path_'.$this->uiRouter->urlPart(0)] = 1;
    $template->data['url_path_'.$this->uiRouter->urlPart(0).'_active'] = ' active';
    $template->data['userImg'] = $this->app()->user()->data('picture');
    $template->data['wss'] = array_values($this->wss);
    $template->data['wssDefaultNdx'] = intval(key($this->wss));
    $template->data['appBrowserParams'] = '';

    $activeMenuItem = NULL;
    if (isset($this->uiStruct['appMenu']['items']))
    {
      $activeMenuItem = Utils::searchArray($this->uiStruct['appMenu']['items'], 'id', $urlId);
    }

    if ($activeMenuItem)
    {
      $template->data['activeMenuItem'] = $activeMenuItem;
      foreach ($this->uiStruct['appMenu']['items'] as &$mi)
      {
        if ($mi['id'] === $urlId)
        {
          $mi['active'] = 1;
          break;
        }
      }
    }

    $this->uiTemplate->data['uiStruct'] = $this->uiStruct;

    if ($activeMenuItem)
    {
      if (isset($activeMenuItem['items']))
      {
        $aiId = key($activeMenuItem['items']);
        $this->renderMenuItem($activeMenuItem['items'][$aiId]);
      }
      else
        $this->renderMenuItem($activeMenuItem);
    }

    if (isset($this->uiStruct['appMenu']['rightMenu']))
    {
      $this->renderMenuItem($this->uiStruct['appMenu']['rightMenu'], 'rightMenuCode');
    }

    $this->uiTemplate->loadTemplate ('e10pro.templates.basic', 'page.mustache', $templateCode);

    $c = $this->uiTemplate->renderTemplate();

    $this->uiTemplate->checkUIData();

    $c .= "<script>";
    $c .= "var uiData = ".Json::lint($this->uiTemplate->uiData).";";
    $c .= "(() => {shc.iot.initIoT ();})();";
    $c .= "</script>";

    return $c;
  }

  protected function renderMenuItem($menuItem, $partId = NULL)
  {
    $destId = ($partId === NULL) ? 'coreMainElementCode' : $partId;
    $mainUIObjectId = '';

    $objectType = $menuItem['objectType'] ?? '';
    $ec = '';

    if ($objectType === 'viewer')
    {
      /** @var \Shipard\Table\DbTable */
      $table = $this->app->table ($menuItem['table'] ?? '');

      /** @var \Shipard\Viewer\TableView */
      $v = NULL;
      if ($table)
        $v = $table->getTableView ($menuItem['viewer'] ?? 'default', NULL);
      if ($v)
      {
        $renderer = new \Shipard\UI\ng\renderers\TableViewRenderer($this->app());
        $renderer->uiRouter = $this->uiRouter;
        $renderer->setViewer($v);
        $v->renderViewerData ('');
        $renderer->render();
        $ec = $renderer->renderedData['hcFull'];
        $mainUIObjectId = $renderer->objectId();
      }

      $this->uiTemplate->data[$destId] = $ec;
    }
    elseif ($objectType === 'widget')
    {
      /** @var \Shipard\UI\Core\UIWidget */
      $widget = $this->app()->createObject($menuItem['classId'] ?? 'invalid--class--id');
      if ($widget)
      {
        $widget->uiRouter = $this->uiRouter;
        $widget->uiTemplate = $this->uiTemplate;

        $responseData = [];
        $widget->init();
        $widget->createResponse($responseData);

        $ec = $responseData['hcFull'];

        $this->uiTemplate->data[$destId] = $ec;
        $mainUIObjectId = $widget->widgetId;
      }
    }
    elseif ($objectType === 'uiWidget')
    {
      $widgetId = $menuItem['id'] ?? '';

      if ($widgetId !== '')
      {
        $wtc = '{{{@uiWidget;id:'.$widgetId.'}}}';
        $this->uiTemplate->data[$destId] = $this->uiTemplate->render($wtc);
      }
    }
    elseif ($objectType === 'iframe')
    {
      $url = $menuItem['url'] ?? '';

      if ($url !== '')
      {
        $code = "<iframe src='$url' style='width: 100%; height: 100%;'></iframe>";
        $this->uiTemplate->data[$destId] = $code;
      }
    }

    if ($mainUIObjectId !== '' && $partId === NULL)
      $this->uiTemplate->data['appBrowserParams'] .= 'data-main-ui-object-id="'.Utils::es($mainUIObjectId).'" ';
  }

  public function createContentCodeInside ()
	{
    if ($this->uiRecData['uiType'] === 9)
    {
      $c = $this->createContentCodeInside_Template();
		  return $c;
    }

    if ($this->uiRecData['uiType'] === 4)
    {
      $this->createUIStruct($this->app()->cfgItem('apps.'.$this->uiRecData['appType']));
      $c = $this->createContentCodeInside_UIStruct();
		  return $c;
    }

    if ($this->uiRecData['uiType'] === 5)
    { // vlastní aplikace
      $this->createUIStruct(json_decode($this->uiRecData['uiStruct'], TRUE));
      $c = $this->createContentCodeInside_UIStruct();
		  return $c;
    }

    return 'ERROR-500';
	}

  protected function createUIStruct($data)
  {
    $this->uiStruct = $data;
    if (isset($data['appMenuType']) && $data['appMenuType'] === 'complex')
      $this->appMenuType = self::amtComplex;

    if (!$this->uiStruct)
      $this->uiStruct = [];

    if ($this->appMenuType === self::amtSimple)
    {
      if (isset($data['appMenu']['items']))
      {
        $this->uiStruct['appMenu']['items'] = $this->createUIStructMenuItems($data['appMenu']['items']);
      }
    }
    else
    {
      $this->createUIStruct_Favorites ($data);
      $this->createUIStruct_AppSettings ($data['appMenu']['appSettings']);

      $this->uiStruct['appMenuFavorites'] = $this->createUIStructMenuItems($data['appMenu']['favorites']['items']);

      $active = 1;
      foreach ($data['appMenu'] as $partId => $partContent)
      {
        $part = $partContent;
        $part['id'] = $partId;
        $part['active'] = $active;
        if (isset($partContent['groups']))
          $part['groups'] = $this->createUIStructMenuItems($partContent['groups']);
        if (isset($partContent['items']))
          $part['items'] = $this->createUIStructMenuItems($partContent['items']);

        unset($this->uiStruct['appMenu'][$partId]);
        $this->uiStruct['appMenu'][] = $part;

        $active = 0;
      }
    }

    $this->uiStruct['themeVariants'] = [];
    foreach ($this->uiThemeCfg['variants'] as $themeVariantId => $themeVariant)
    {
      $tv = $themeVariant;
      $tv['id'] = $themeVariantId;
      $this->uiStruct['themeVariants'][] = $tv;
    }
  }

  protected function createUIStructMenuItems($menuItems)
  {
    $items = [];
    foreach ($menuItems as $menuId => $menuItem)
    {
      if (isset($menuItem['mainRole']) && !$this->app()->hasMainRole($menuItem['mainRole']))
        continue;

      if (!isset($menuItem['id']))
        $menuItem['id'] = $menuId;

      if (isset($menuItem['items']))
        $menuItem['items'] = $this->createUIStructMenuItems($menuItem['items']);

      $items[] = $menuItem;
    }

    return $items;
  }

  public function createUIStruct_AppSettings (&$dstItem)
	{
		$appOptions = \e10\sortByOneKey($this->app()->appOptions(), 'order', TRUE);
		$groups = \E10\sortByOneKey($this->app()->cfgItem ('e10.appOptions.groups', []), 'order', TRUE);
		foreach ($groups as $groupId => $group)
		{
			$groupCfg = ['title' => $group['title'], 'icon' => $group['icon'] ?? 'system/actionAdd', 'items' => []];
			forEach ($appOptions as $id => $c)
			{
				if ($c['group'] !== $groupId)
					continue;
				if (!utils::enabledCfgItem($this->app(), $c))
					continue;

				if ($c ['type'] === 'viewer')
				{
					$c['object'] = 'viewer';
					//if ($this->app()->checkAccess($c) === 0)
					//	continue;
					$si = [
						'title' => $c['name'],
						'objectType' => "viewer",
            'table' => $c['table'],
            'viewer' => $c['viewer'],
            'icon' => $c['icon'] ?? 'system/iconUser',
            'order' => $c['order'],
					];
					$groupCfg['items'][] = $si;
				}
			}
      $dstItem['groups'][] = $groupCfg;
		}
	}

  public function createUIStruct_Favorites (&$data)
	{
    foreach ($this->uiStruct['appMenu'] as $partId => $partContent)
    {
      if (!isset($partContent['groups']))
        continue;
      foreach ($partContent['groups'] as $groupId => $groupContent)
      {
        foreach ($groupContent['items'] as $itemId => $item)
        {
          if (isset($item['autoFav']))
          {
            $data['appMenu']['favorites']['items'][$itemId] = $item;
            $data['appMenu']['favorites']['items'][$itemId]['order'] = intval($item['autoFav']);
          }
        }
      }
    }
  }

	public function run ()
	{
    $this->init();
		parent::run();
	}
}
