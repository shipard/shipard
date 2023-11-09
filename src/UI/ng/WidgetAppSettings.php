<?php

namespace Shipard\UI\ng;
use \Shipard\Utils\Utils, \Shipard\Utils\Str;

/**
 * class WidgetAppSettings
 */
class WidgetAppSettings extends \Shipard\UI\Core\UIWidget
{
	var $userContext = NULL;

	function loadData()
	{
    if (!isset($this->uiTemplate->data['uiStruct']['appSettings']))
      return;

    foreach ($this->uiTemplate->data['uiStruct']['appSettings'] as $classId)
    {
      /** @var \Shipard\UI\ng\AppSettings $o */
      $o = $this->app()->createObject($classId);
      if (!$o)
      {
        continue;
      }

      $o->uiTemplate = $this->uiTemplate;
      $o->run();

      foreach ($o->resultData as $rd)
      {
        $this->uiTemplate->data['appSettings'][] = $rd;
      }
    }
	}

	function renderData()
	{
		$templateStr = $this->uiTemplate->subTemplateStr('src/UI/ng/subtemplates/appSettings');
		$code = $this->uiTemplate->render($templateStr);

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $code]);
	}

	public function createContent ()
	{
		$userContexts = $this->app()->uiUserContext ();
		$ac = $userContexts['contexts'][$this->app()->uiUserContextId] ?? NULL;

		$this->loadData();
		$this->renderData();
	}

	public function title()
	{
		return FALSE;
	}
}
