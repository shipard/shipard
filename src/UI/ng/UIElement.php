<?php

namespace Shipard\UI\ng;

/**
 * class UIElement
 */
class UIElement extends \Shipard\UI\ng\TemplateUIControl
{
  var $uiStruct = NULL;

  public function render(string $tagName, ?array $params)
  {
    $this->uiStruct = $this->uiTemplate->data['uiStruct'];
    $c = $this->renderAppCore();

    return $c;
  }

  protected function renderAppCore()
  {
    /*
    $c = 'bla bla bla<br>';
    $c .= json_encode($this->uiStruct);
    return $c;
    */

    $appTemplate = $this->uiStruct['template'] ?? 'appCore';


    $templateStr = file_get_contents(__SHPD_ROOT_DIR__.'src/UI/ng/subtemplates/'.$appTemplate.'.mustache');
    $c = $this->uiTemplate->render($templateStr);

    return $c;
  }
}

