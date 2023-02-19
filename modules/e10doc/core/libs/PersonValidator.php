<?php

namespace e10doc\core\libs;
use \Shipard\Base\Utility;

/**
 * class PersonValidator
 */
class PersonValidator extends Utility
{
  var $maxCount = 5;
  var $debug = 0;

  public function batchCheck()
  {
    $maxOldDate = new \DateTime('1 year ago');

    $q[] = 'SELECT * FROM [e10_persons_persons] as persons ';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [docState] = %i', 4000);
    array_push($q, ' AND [company] = %i', 1);

    array_push($q, ' AND EXISTS (SELECT person FROM e10doc_core_heads WHERE persons.ndx = person AND dateAccounting > %d', $maxOldDate,
        ' AND docType IN %in', ['invno', 'invni', 'cash', 'cashreg'], ')');

    array_push($q, ' AND (');
    array_push($q, ' EXISTS (SELECT ndx FROM e10_persons_personsValidity WHERE persons.ndx = person AND [valid] = %i)', 0);
    array_push($q, ' OR NOT EXISTS (SELECT ndx FROM e10_persons_personsValidity WHERE persons.ndx = person)');
    array_push($q, ')');
		array_push($q, ' LIMIT 0, %i', $this->maxCount);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      if ($this->debug)
        echo "* ".$r['fullName']."\n";
      $pv = new \e10\persons\libs\register\Validator($this->app());
      $pv->setPersonNdx($r['ndx']);
      $pv->checkPerson();

      sleep(5);
		}
  }
}