<?php

namespace e10doc\core\libs;


/**
 * class ViewPersonsDrivers
 */
class ViewPersonsDrivers extends \E10\Persons\ViewPersons
{
  var $transportNdx = 0;
  var $driversGroups = [];

	public function init ()
	{
    $this->transportNdx = $this->queryParam ('transport');

    if ($this->transportNdx)
    {
      $rows = $this->db()->query(
        'SELECT * FROM e10_base_doclinks WHERE ',
        ' dstTableId = %s', 'e10.persons.groups',
        ' AND srcTableId = %s', 'e10doc.base.transports',
        ' AND linkId = %s', 'e10-transports-drivers-pg',
        ' AND srcRecId = %i', $this->transportNdx
      );

      foreach ($rows as $r)
      {
        $this->driversGroups[] = $r['dstRecId'];
      }
    }

		$this->setMainGroup ('e10pro-zus-groups-teachers');
		parent::init();
	}

  public function defaultQuery (&$q)
  {
    if (count($this->driversGroups))
      array_push ($q, " AND EXISTS (SELECT ndx FROM e10_persons_personsgroups WHERE persons.ndx = e10_persons_personsgroups.person AND [group] IN %in)", $this->driversGroups);
  }

  public function createToolbar()
  {
    return [];
  }
}

