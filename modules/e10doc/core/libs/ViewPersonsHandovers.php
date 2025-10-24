<?php

namespace e10doc\core\libs;


/**
 * class ViewPersonsHandovers
 */
class ViewPersonsHandovers extends \E10\Persons\ViewPersons
{
	var $docPerson = 0;

	public function init ()
	{
		$this->loadAddresses = TRUE;

		parent::init();
		if ($this->queryParam ('personNdx'))
			$this->docPerson = intval($this->queryParam ('personNdx'));
	}

  public function defaultQuery (&$q)
  {
		array_push ($q, ' AND [personType] = %i', 1); // -- person, not company/robot

		$fts = $this->fullTextSearch();
		if ($fts === '')
		{
			if ($this->docPerson)
			{
				array_push ($q, ' AND EXISTS (SELECT personHandover FROM e10doc_core_heads WHERE persons.ndx = e10doc_core_heads.personHandover AND person = %i)', $this->docPerson);
			}
		}
  }

	function decorateRow (&$item)
	{
		$item ['t2'] = [];

		if (isset($this->addresses [$item ['pk']]))
			$item ['t2'] = [$this->addresses [$item ['pk']][0]];

		if (isset ($this->properties [$item ['pk']]['ids']))
			$item ['i2'] = $this->properties [$item ['pk']]['ids'];

		if (isset ($this->properties [$item ['pk']]['contacts']))
			$item ['t2'] = array_merge ($item ['t2'], array_slice ($this->properties [$item ['pk']]['contacts'], 0, 2, TRUE));
	}
}
