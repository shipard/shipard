<?php

namespace Shipard\UI\ng;

/**
 * class AppPageAnonymousRequest
 */
class AppPageAnonymousRequest extends \Shipard\UI\ng\AppPageBlank
{
  public function createContentCodeInside ()
	{
    $appTemplate = 'pageAnonymousRequest';
    $appTemplatePath = 'src/UI/ng/subtemplates/';

    $templateStr = file_get_contents(__SHPD_ROOT_DIR__.$appTemplatePath.$appTemplate.'.mustache');
    $c = $this->uiTemplate->render($templateStr);

    return $c;
	}

	public function run ()
	{
//    $this->init();
		parent::run();
	}
}
