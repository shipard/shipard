<?php

namespace e10doc\base\libs;
use \Shipard\Base\Content;


/**
 * class DocDetailPart
 */
class DocDetailPart extends Content
{
  var $gens = NULL;
  var $recData = NULL;

  /** @var \e10doc\core\TableHeads */
	var $tableDocsHeads;

  var $srcViewer = NULL;


  public function init()
  {
    $this->gens = $this->app()->cfgItem('e10doc.gen.cfgs', NULL);
    $this->tableDocsHeads = $this->app->table ('e10doc.core.heads');
  }


  public function setDocument($docRecData)
  {
    $this->recData = $docRecData;
  }

  public function create()
  {
  }
}
