<?php

namespace e10doc\reporting\libs\dc;
use \Shipard\Base\DocumentCard;
use \Shipard\Utils\Json;


/**
 * class CalcReportsResults
 */
class CalcReportsResults extends DocumentCard
{
  public function createContentBody ()
	{
    $resData = Json::decode($this->recData['resData']);
    $resContent = Json::decode($this->recData['resContent']);


    foreach ($resContent as $cc)
    {
      $cc['pane'] = 'e10-pane e10-pane-table';
      $this->addContent('body', $cc);
    }

    //$this->addContent('body', ['type' => 'text', 'text' => $this->recData['resData']]);
    //$this->addContent('body', ['type' => 'text', 'text' => $this->recData['resContent']]);

  }

  public function createContent ()
	{
    $this->flatInfo = new \e10pro\condo\libs\FlatInfo($this->app());
    $this->flatInfo->setWorkOrder($this->recData['ndx']);
    $this->flatInfo->loadInfo();

		$this->createContentBody ();
	}
}