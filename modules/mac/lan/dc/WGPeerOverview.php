<?php

namespace mac\lan\dc;


/**
 * class WGPeerOverview
 */
class WGPeerOverview extends \Shipard\Base\DocumentCard
{
  public function addCfgPeer()
  {
    $wge = new \mac\lan\libs3\WireguardEngine($this->app());
    $wge->setPeer($this->recData['ndx']);
    $cfgText = $wge->createCfgPeer();
    $this->addContent('body', ['type' => 'text', 'subtype' => 'auto', 'text' => $cfgText]);

		$qrCodeUrl = $wge->createPeerQRCode();
		$imgCode = "<img src='$qrCodeUrl'>";
    $this->addContent('body', ['type' => 'line', 'line' => ['code' => $imgCode]]);
  }

	public function createContentBody ()
	{
    $this->addCfgPeer();
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
