<?php

namespace mac\access\dc;

use e10\utils, Shipard\Utils\Json;


/**
 * Class LogItem
 */
class LogItem extends \Shipard\Base\DocumentCard
{
	public function createContentBody ()
	{
		// -- core info
		$info = [];

    //$info[] = ['p1' => 'Test', 't1' => 'ABCDE'];


		$info[0]['_options']['cellClasses']['p1'] = 'width30';
		$h = ['p1' => ' ', 't1' => ''];

		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		]);

    if ($this->recData['msg'] && strlen($this->recData['msg']) !== 0)
    {
      $this->addContent ('body', [
        'pane' => 'e10-pane e10-pane-table', 'type' => 'text',
        'text' => $this->recData['msg']
      ]);
    }

    $this->addContent ('body', [
      'pane' => 'e10-pane e10-pane-table', 'type' => 'text',
      'text' => Json::lint($this->recData),
    ]);
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
