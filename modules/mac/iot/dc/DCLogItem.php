<?php

namespace mac\iot\dc;
use \Shipard\Utils\Json;


/**
 * class DCLogItem
 */
class DCLogItem extends \Shipard\Base\DocumentCard
{
	public function createContentBody ()
	{
    if (($this->recData['requestData'][0] ?? '') === '{')
    { // JSON?
      $data = Json::decode($this->recData['requestData']);
      if ($data)
      {
        $this->addContent('body',  [
          'pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'subtype' => 'code',
          'text' => Json::lint($data),
          'paneTitle' => ['text' => 'Data požadavku: JSON, '.strlen($this->recData['requestData']).' bajtů',
          'class' => 'h3 subtitle',
          ]
        ]);

        return;
      }
    }

    $this->addContent('body',  [
      'pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'subtype' => 'code',
      //'text' => Json::lint($e->esignImgRecData)
      'text' => $this->recData['requestData'] ?? '',
    ]);
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
