<?php

namespace e10pro\ofre\libs\dc;
use \Shipard\Base\DocumentCard;


/**
 * class OfficeCore
 */
class OfficeCore extends DocumentCard
{
  var \e10pro\ofre\libs\OfficeInfo $officeInfo;

  public function createContentBody ()
	{
    if (count($this->officeInfo->data['vdsContent']))
    {
      foreach ($this->officeInfo->data['vdsContent'] as $cc)
      {
        $cc['pane'] = 'e10-pane e10-pane-table';
        $cc['params'] = ['hideHeader' => 1, ];
        $this->addContent('body', $cc);
      }
    }

    if ($this->officeInfo->data['personsList'])
      $this->addContent('body', $this->officeInfo->data['personsList']);

    if ($this->officeInfo->data['rowsContent'])
      $this->addContent('body', $this->officeInfo->data['rowsContent']);

    if ($this->officeInfo->data['rowsMetersReadings'])
      $this->addContent('body', $this->officeInfo->data['rowsMetersReadings']);
  }

  public function createContent ()
	{
    $this->officeInfo = new \e10pro\ofre\libs\OfficeInfo($this->app());
    $this->officeInfo->setWorkOrder($this->recData['ndx']);
    $this->officeInfo->loadInfo();

		$this->createContentBody ();
	}
}