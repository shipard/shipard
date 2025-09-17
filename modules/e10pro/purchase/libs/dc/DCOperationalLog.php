<?php


namespace e10pro\purchase\libs\dc;
use \Shipard\Base\DocumentCard, \Shipard\Utils\Utils;


/**
 * class DCOperationalLog
 */
class DCOperationalLog extends DocumentCard
{
  public function createContentBody ()
	{
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
