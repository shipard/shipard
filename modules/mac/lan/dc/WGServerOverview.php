<?php

namespace mac\lan\dc;


/**
 * class WGServerOverview
 */
class WGServerOverview extends \Shipard\Base\DocumentCard
{
  public function addCfgLinux()
  {
    $wge = new \mac\lan\libs3\WireguardEngine($this->app());
    $wge->setServer($this->recData['ndx']);
    $cfgText = $wge->createCfgServerLinux();

    $this->addContent('body', ['type' => 'text', 'subtype' => 'auto', 'text' => $cfgText]);

		$postUpScript = $wge->createCfgServerLinuxPostUp();
    $this->addContent('body', ['type' => 'text', 'subtype' => 'auto', 'text' => $postUpScript]);

		$preDownScript = $wge->createCfgServerLinuxPreDown();
    $this->addContent('body', ['type' => 'text', 'subtype' => 'auto', 'text' => $preDownScript]);
  }

	public function createContentBody ()
	{
		// -- linux config
    $this->addCfgLinux();
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
