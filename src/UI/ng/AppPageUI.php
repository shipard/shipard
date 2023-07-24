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
    $template = new \Shipard\UI\ng\TemplateUI ($this->app());

    $template->data['url_path_'.$this->app()->requestPath (2)] = 1;
    $template->data['url_path_'.$this->app()->requestPath (2).'_active'] = ' active';
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
    $urlId = $this->app()->requestPath (2);
    $mainUIObjectId = '';

    $template = new \Shipard\UI\ng\TemplateUI ($this->app());

    $template->uiRoot = $this->app()->dsRoot;
    $template->uiRoot .= '/ui/'.$this->app->requestPath(1);
    if ($this->app->requestPath(1) !== '')
      $template->uiRoot .= '/';


    $template->data['url_path1'] = $this->app()->requestPath (2);
    $template->data['url_path_'.$this->app()->requestPath (2)] = 1;
    $template->data['url_path_'.$this->app()->requestPath (2).'_active'] = ' active';
    $template->data['userImg'] = $this->app()->user()->data('picture');
    $template->data['wss'] = array_values($this->wss);
    $template->data['wssDefaultNdx'] = intval(key($this->wss));
    $template->data['appBrowserParams'] = '';

    $template->data['uiStruct'] = $this->uiStruct;

    $activeMenuItem = NULL; // uiStruct.appMenu.items
    if (isset($this->uiStruct['appMenu']['items']))
    {
      $activeMenuItem = Utils::searchArray($this->uiStruct['appMenu']['items'], 'id', $urlId);
    }

    if ($activeMenuItem)
    {
      $template->data['activeMenuItem'] = $activeMenuItem;
    }

    if ($activeMenuItem)
    {
      $objectType = $activeMenuItem['objectType'] ?? '';
      $ec = '';

      if ($objectType === 'viewer')
      {
        /** @var \Shipard\Table\DbTable */
        $table = $this->app->table ($activeMenuItem['table'] ?? '');

        /** @var \Shipard\Viewer\TableView */
        $v = NULL;
        if ($table)
          $v = $table->getTableView ($activeMenuItem['viewer'] ?? 'default', NULL);
        if ($v)
        {
          $v->renderViewerData ('');
          $renderer = new \Shipard\UI\ng\renderers\TableViewRenderer($this->app());
          $renderer->setViewer($v);
          $renderer->render();
          $ec = $renderer->renderedData['hcFull'];
          $mainUIObjectId = $renderer->objectId();
        }

        $template->data['coreMainElementCode'] = $ec;
      }
    }

    if ($mainUIObjectId !== '')
      $template->data['appBrowserParams'] .= 'data-main-ui-object-id="'.Utils::es($mainUIObjectId).'" ';

    $template->loadTemplate ('e10pro.templates.basic', 'page.mustache', $templateCode);

    $c = $template->renderTemplate();

    $template->checkUIData();

    $c .= "<script>";
    $c .= "var uiData = ".Json::lint($template->uiData).";";
    $c .= "(() => {shc.iot.initIoT ();})();";
    $c .= "</script>";

    return $c;
  }

  public function createContentCodeInside ()
	{
    if ($this->uiRecData['uiType'] === 9)
    {
      $c = $this->createContentCodeInside_Template();
		  return $c;
    }

    if ($this->uiRecData['uiType'] === 5)
    {
      $this->uiStruct = json_decode($this->uiRecData['uiStruct'], TRUE);
      if (!$this->uiStruct)
      $this->uiStruct = [];

      $c = $this->createContentCodeInside_UIStruct();
		  return $c;
    }

    return 'ERROR-500';
	}

	public function run ()
	{
    $this->init();
		parent::run();
	}
}
