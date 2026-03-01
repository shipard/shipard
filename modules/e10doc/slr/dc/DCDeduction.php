<?php

namespace e10doc\slr\dc;


/**
 * class DCDeduction
 */
class DCDeduction extends \Shipard\Base\DocumentCard
{
	public function createContentBody ()
	{
    $this->addAttachments();
	}

  public function addAttachments ()
	{
		$this->addContentAttachments ($this->recData ['ndx']);
	}


	public function createContent ()
	{
		$this->createContentBody ();
	}
}
