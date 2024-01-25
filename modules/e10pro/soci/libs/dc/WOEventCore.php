<?php

namespace e10pro\soci\libs\dc;
use \Shipard\Base\DocumentCard;


/**
 * class WOEventCore
 */
class WOEventCore extends DocumentCard
{
  var \e10pro\soci\libs\WOEventInfo $woInfo;

  public function createContentBody ()
	{
    foreach ($this->woInfo->data['vdsContent'] as $cc)
    {
      $cc['pane'] = 'e10-pane e10-pane-table';
      $cc['params'] = ['hideHeader' => 1, ];
      $this->addContent('body', $cc);
    }

    if ($this->woInfo->data['personsList'])
      $this->addContent('body', $this->woInfo->data['personsList']);

    if ($this->woInfo->data['entriesList'])
      $this->addContent('body', $this->woInfo->data['entriesList']);

  }

  public function createContent ()
	{
    $this->woInfo = new \e10pro\soci\libs\WOEventInfo($this->app());
    $this->woInfo->setWorkOrder($this->recData['ndx']);
    $this->woInfo->loadInfo();

		$this->createContentBody ();
	}
}