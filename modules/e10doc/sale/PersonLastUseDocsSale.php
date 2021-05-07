<?php


namespace e10doc\sale;

use \e10doc\core\PersonLastUseDocs;


/**
 * Class PersonLastUseDocsSale
 * @package e10doc\sale
 */
class PersonLastUseDocsSale extends PersonLastUseDocs
{
	protected function init()
	{
		$this->lastUseTypeId = 'e10doc-docs-sale';
		$this->docsTypes = ['invno', 'cashreg'];
	}

	protected function doIt ()
	{
		$this->doItDocs(1);
	}
}
