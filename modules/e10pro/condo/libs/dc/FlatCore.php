<?php

namespace e10pro\condo\libs\dc;
use \Shipard\Base\DocumentCard;


/**
 * class FlatCore
 */
class FlatCore extends DocumentCard
{
  var \e10pro\condo\libs\FlatInfo $flatInfo;

  public function createContentBody ()
	{
    foreach ($this->flatInfo->data['vdsContent'] as $cc)
    {
      $cc['pane'] = 'e10-pane e10-pane-table';
      $cc['params'] = ['hideHeader' => 1, ];
      $this->addContent('body', $cc);
    }

    if ($this->flatInfo->data['personsList'])
      $this->addContent('body', $this->flatInfo->data['personsList']);

    if ($this->flatInfo->data['rowsContent'])
      $this->addContent('body', $this->flatInfo->data['rowsContent']);

    if ($this->flatInfo->data['rowsMetersReadings'])
      $this->addContent('body', $this->flatInfo->data['rowsMetersReadings']);
  }

  public function createContent ()
	{
    $this->flatInfo = new \e10pro\condo\libs\FlatInfo($this->app());
    $this->flatInfo->setWorkOrder($this->recData['ndx']);
    $this->flatInfo->loadInfo();

		$this->createContentBody ();
	}
}