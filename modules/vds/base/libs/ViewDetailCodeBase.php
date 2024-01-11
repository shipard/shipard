<?php

namespace vds\base\libs;
use \Shipard\Viewer\TableViewDetail;
use \Shipard\UI\Core\UIUtils;


/**
 * class ViewDetailCodeBase
 */
class ViewDetailCodeBase extends TableViewDetail
{
	public function createDetailContent ()
	{
    $srcHeaderData = json_decode($this->item['data'], TRUE);
    $scDef = $this->table->subColumnsInfo($this->item, 'data');

    $scContent = UIUtils::renderSubColumns ($this->app(), $srcHeaderData, $scDef, TRUE);

    foreach ($scContent as $scContentPart)
    {
      $scContentPart['pane'] = 'e10-pane e10-pane-table';
      $this->addContent($scContentPart);
    }
	}
}
