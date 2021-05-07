<?php

namespace swdev\translation;
use \e10\DbTable;


/**
 * Class TableDictsTrData
 * @package swdev\translation
 */
class TableDictsTrData extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('swdev.translation.dictsTrData', 'swdev_translation_dictsTrData', 'Data slovníků');
	}
}
