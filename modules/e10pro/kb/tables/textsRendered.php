<?php

namespace e10pro\kb;
use \e10\DbTable;


/**
 * Class TableTextsRendered
 * @package e10pro\kb
 */
class TableTextsRendered extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('e10pro.kb.textsRendered', 'e10pro_kb_textsRendered', 'Hotové texty wiki stránek');
	}
}
