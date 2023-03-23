<?php

namespace mac\iot\libs\dc;
use Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * class CamCfg
 */
class CamCfg extends \Shipard\Base\DocumentCard
{
	public function createContentBody ()
	{
    $ccc = new \mac\iot\libs\CamsCfgCreator($this->app());
    $ccc->create(0, 0, FALSE, $this->recData['ndx']);

    $this->addContent('body', ['pane' => 'e10-pane padd5', 'type' => 'text', 'subtype' => 'code', 'text' => Json::lint($ccc->cfgs[$this->recData['ndx']])]);
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
