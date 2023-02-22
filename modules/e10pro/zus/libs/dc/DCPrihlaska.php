<?php

namespace e10pro\zus\libs\dc;


/**
 * class DCPrihlaska
 */
class DCPrihlaska extends \Shipard\Base\DocumentCard
{
  public function createContent ()
	{
    $this->addContentAttachments ($this->recData ['ndx']);
	}
}
