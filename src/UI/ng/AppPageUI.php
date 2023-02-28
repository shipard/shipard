<?php

namespace Shipard\UI\ng;
use \Shipard\Utils\Utils;
use \e10\ui\TableUIs;


/**
 * class AppPageUI
 */
class AppPageUI extends \Shipard\UI\ng\AppPageBlank
{
  var $uiCfg = NULL;
  var $uiRecData = NULL;

  var \e10\ui\TableUIs $tableUIs;

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

    $template->loadTemplate ('e10pro.templates.basic', 'page.mustache', $this->uiRecData['template']);
    return $template->renderTemplate();
  }

  public function createContentCodeInside ()
	{
    $c = $this->createContentCodeInside_Template();
		return $c;
	}

	public function run ()
	{
    $this->init();
		parent::run();
	}
}
