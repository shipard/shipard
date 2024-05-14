<?php

namespace mac\iot\dc;

use \Shipard\Utils\Json;


/**
 * class DCESign
 */
class DCESign extends \Shipard\Base\DocumentCard
{
	public function createContentBody ()
	{
		$e = new \mac\iot\libs\ESignImageEngine($this->app());
    $e->setESign($this->recData['ndx']);
    $e->doIt();

		if ($e->esignImgRecData)
		{
			$this->addContent('body',  ['pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'subtype' => 'code', 'text' => Json::lint($e->esignImgRecData)]);

			$imgCode = "<img src='{$e->esignImgRecData['imagePreviewURL']}'>";
			$imgPaneTitle = [
				['text' => 'Náhled obrázku', 'class' => 'h2 pb1', 'icon' => 'user/photo'],
				['text' => 'Otevřít', 'class' => 'h2 pb1 pl1', 'icon' => 'system/iconLink', 'url' => $e->esignImgRecData['imagePreviewURL']],
			];
			$this->addContent('body',  ['pane' => 'e10-pane-core e10-pane-table e10-bg-t3', 'paneTitle' => $imgPaneTitle, 'type' => 'text', 'subtype' => 'rawhtml', 'text' => $imgCode]);



			$htmlPaneTitle = [
				['text' => 'Náhled HTML', 'class' => 'h2 pb1', 'icon' => 'user/photo'],
				['text' => 'Otevřít', 'class' => 'h2 pb1 pl1', 'icon' => 'system/iconLink', 'url' => $e->htmlCodeURL],
			];
			$width = $e->displayInfo['width'].'px';
			$height = $e->displayInfo['height'].'px';
			$htmlPreviewCode = "<iframe sandbox='' frameborder='0' height='$height' width='$width' style='width:$width; height:$height;' src='".$e->htmlCodeURL."'></iframe>";
			$this->addContent('body',  [
				'type' => 'text', 'subtype' => 'rawhtml', 'text' => $htmlPreviewCode,
				'pane' => 'e10-pane-core e10-pane-table e10-bg-t5', 'paneTitle' => $htmlPaneTitle,
			]);
		}
	}

	function createContentBody_Thing(&$tabs)
	{
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
