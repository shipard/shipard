<?php

namespace Shipard\UI\ng\renderers;

use \Shipard\Base\Utility;


/**
 * class Renderer
 */
class Renderer extends Utility
{
  var ?\Shipard\UI\ng\Router $uiRouter = NULL;

  var $renderedData = [];

  public function render()
  {

  }

  public function objectId()
  {
    return '__INVALID__OBJECT__ID__';
  }
}

