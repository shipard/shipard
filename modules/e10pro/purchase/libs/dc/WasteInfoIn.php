<?php


namespace e10pro\purchase\libs\dc;
use \Shipard\Base\DocumentCard, \Shipard\Utils\Utils;


/**
 * class WasteInfoIn
 */
class WasteInfoIn extends DocumentCard
{
  public function createContentBody ()
	{
    $this->addContent('body', ['type' => 'text', 'subtype' => 'rawhtml', 'text' => 'NAZDAR!!!', 'pane' => 'e10-pane e10-pane-table pageText']);
  }

  public function createContent ()
	{
		$this->createContentBody ();
	}
}
