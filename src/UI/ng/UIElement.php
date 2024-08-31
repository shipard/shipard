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
    $appTemplate = $this->uiStruct['template'] ?? 'appCore';
    $appTemplatePath = $this->uiStruct['templatePath'] ?? 'src/UI/ng/subtemplates/';

    $templateStr = file_get_contents(__SHPD_ROOT_DIR__.$appTemplatePath.$appTemplate.'.mustache');
    $c = $this->uiTemplate->render($templateStr);

    return $c;
  }
}

