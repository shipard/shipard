<?php

namespace e10pro\bcards\libs\dc;

use \Shipard\Utils\Json;


/**
 * class DCBCard
 */
class DCBCard extends \Shipard\Base\DocumentCard
{
	public function createContentBody ()
	{
		//$this->addContent('body',  ['pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'subtype' => 'code', 'text' => Json::lint($this->recData)]);

		$e = new \e10pro\bcards\libs\BCardEngine($this->app());
    $e->setBCard($this->recData['ndx']);

		$previewUrl = $e->url();
		$htmlPaneTitle = [
			['text' => 'Náhled HTML', 'class' => 'h2 pb1', 'icon' => 'user/photo'],
			['text' => 'Otevřít', 'class' => 'h2 pb1 pl1', 'icon' => 'system/iconLink', 'url' => $previewUrl],
		];
		$width = '600'.'px';
		$height = '820'.'px';
		$htmlPreviewCode = "<iframe sandbox='' frameborder='0' height='$height' width='$width' style='width:$width; height:$height;' src='".$previewUrl."'></iframe>";
		$this->addContent('body',  [
			'type' => 'text', 'subtype' => 'rawhtml', 'text' => $htmlPreviewCode,
			'pane' => 'e10-pane-core e10-pane-table e10-bg-t5', 'paneTitle' => $htmlPaneTitle,
		]);

		$e->createData();


		$vcardTitle = [
			['text' => 'VCARD', 'class' => 'h2 subtitle'],
		];
		$this->addContent('body',  ['pane' => 'e10-pane e10-pane-table', 'paneTitle' => $vcardTitle, 'type' => 'text', 'subtype' => 'code', 'text' => $e->bcardData['vcard']]);

		$qrVcardTitle = [
			['text' => 'QR CODE: VCARD', 'class' => 'h2'],
			['text' => 'Download', 'class' => 'btn btn-default pull-right', 'download' => 'vcard.svg', 'url' => $e->bcardData['vcardQRCodeURL']],
			['text' => ' ', 'class' => 'block bb1 mb1'],
		];
		$code = "<img style='max-width: 200px; padding: 8px;' src='{$e->bcardData['vcardQRCodeURL']}'>";
		$this->addContent('body',  ['pane' => 'e10-pane e10-pane-table', 'paneTitle' => $qrVcardTitle, 'type' => 'text', 'subtype' => 'rawhtml', 'text' => $code]);


		$this->addContent('body',  ['pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'subtype' => 'code', 'text' => Json::lint($e->bcardData)]);
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
