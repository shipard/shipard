<?php

namespace e10pro\bcards\libs\dc;

use \Shipard\Utils\Json;


/**
 * class DCBCardWebTemplate
 */
class DCBCardWebTemplate extends \Shipard\Base\DocumentCard
{
	var $testBCardNdx = 0;

	public function createContentBody ()
	{
		//$this->addContent('body',  ['pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'subtype' => 'code', 'text' => Json::lint($this->recData)]);

		$e = new \e10pro\bcards\libs\BCardEngine($this->app());
    $e->setBCard($this->testBCardNdx);

		$previewUrl = $e->url();
		$previewUrl .= '?template='.$this->recData['ndx'];
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
	}

	public function createContent ()
	{
		$this->testBCardNdx = 1;
		$this->createContentBody ();
	}
}
