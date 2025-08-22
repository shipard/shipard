<?php


namespace e10pro\zus\libs\dc;
use \Shipard\Base\DocumentCard, \Shipard\Utils\Utils;


/**
 * class MsgCore
 */
class MsgCore extends DocumentCard
{
  public function createContentBody ()
	{
    // -- text
    if ($this->recData ['text'] && $this->recData ['text'] !== '')
    {
      $textRenderer = new \lib\core\texts\Renderer($this->app());
      $textRenderer->render($this->recData ['text']);
      $this->addContent('body', ['type' => 'text', 'subtype' => 'rawhtml', 'text' => $textRenderer->code, 'pane' => 'e10-pane e10-pane-table pageText']);
    }

  }

  public function createContent ()
	{
		$this->createContentBody ();
	}
}
