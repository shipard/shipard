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
