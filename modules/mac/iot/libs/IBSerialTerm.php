<?php

namespace mac\iot\libs;


class IBSerialTerm extends \Shipard\UI\Core\UIWidgetBoard
{
	var $code;

	protected function composeCode ()
	{
		$templateStr = $this->uiTemplate->subTemplateStr('modules/mac/iot/libs/subtemplates/IBSerialTerm');
		$c = $this->uiTemplate->render($templateStr);

		$c .= $this->composeCodeInitScript();

		return $c;
	}

	protected function composeCodeInitScript ()
	{
    $c = '';

		//$c = "\n<script>(() => {initWidgetIBSerialTerm ('{$this->widgetId}');})();</script>";
		//$c = "\n<script>document.addEventListener('DOMContentLoaded', () => {initWidgetIBSerialTerm ('{$this->widgetId}');});</script>";


		//$c = "\n<script>document.addEventListener('DOMContentLoaded', () => {initWidgetIBSerialTerm ('{$this->widgetId}');});</script>";
		$c .= "<script>setTimeout (function(){initWidgetIBSerialTerm ('{$this->widgetId}');}, 2000);</script>";

		//document.addEventListener('DOMContentLoaded', () => {});
		return $c;
	}

	public function createContent ()
	{
    $this->panelStyle = self::psNone;
		$this->code = $this->composeCode();
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $this->code]);
	}

	public function title()
	{
		return FALSE;
	}

	public function setDefinition ($d)
	{
		$this->definition = ['class' => 'e10-widget-terminal', 'type' => 'terminal'];
	}

	public function fullScreen()
	{
		return 1;
	}

	public function pageType()
	{
		return 'terminal';
	}
}
