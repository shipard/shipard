<?php

namespace e10doc\reporting\libs\dc;
use \Shipard\Base\DocumentCard;
use \Shipard\Utils\Json;
use \Shipard\UI\Core\UIUtils;


/**
 * class CalcReport
 */
class CalcReport extends DocumentCard
{
  public function createContentBody ()
	{
    $srcHeaderData = json_decode($this->recData['srcHeaderData'], TRUE);
    $scDef = $this->table->subColumnsInfo($this->recData, 'srcHeaderData');

    $scContent = UIUtils::renderSubColumns ($this->app(), $srcHeaderData, $scDef, TRUE);

    foreach ($scContent as $scContentPart)
    {
      $scContentPart['pane'] = 'e10-pane e10-pane-table';
      $this->addContent('body', $scContentPart);
    }
  }

  public function createContent ()
	{
		$this->createContentBody ();
  }
}

