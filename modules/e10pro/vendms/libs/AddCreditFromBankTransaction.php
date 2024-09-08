<?php
namespace e10pro\vendms\libs;
use \Shipard\Utils\Utils, \Shipard\Base\Utility;

/**
 * class AddCreditFromBankTransaction
 */
class AddCreditFromBankTransaction extends Utility
{
	var $transaction;
	var $tableHeads;

	public function init ()
	{
	}

	public function setTransaction ($transaction)
	{
		$this->transaction = $transaction;
	}

	public function run ()
	{
    if ($this->transaction['type'] != 1 || $this->transaction['amount'] <= 0.0)
      return;

    $symbol1 = trim($this->transaction['symbol1'] ?? '');


    // -- check person
    $q = [];
    array_push ($q,'SELECT props.recid AS propRecId,');
    array_push ($q,' persons.fullName AS personName');
		array_push ($q,' FROM [e10_base_properties] AS props');
    array_push ($q,' LEFT JOIN [e10_persons_persons] AS persons ON props.recid = persons.ndx');
    array_push ($q,' WHERE 1');
		array_push ($q,' AND [tableid] = %s', 'e10.persons.persons');
		array_push ($q,' AND [group] = %s', 'paysyms', ' AND property = %s', 'paysym1');
    array_push ($q,' AND [valueString] = %s', $symbol1);

    $personNdx = 0;
    $person = $this->db()->query($q)->fetch();
    if ($person)
      $personNdx = $person['propRecId'];

    /** @var \Shipard\Table\DbTable */
    $tableCredits = $this->app()->table('e10pro.vendms.credits');

    $creditItem = [
      'created' => new \DateTime(),
      'person' => $personNdx,
      'amount' => $this->transaction['amount'],
      'moveType' => 0,

      'bankTransId' => $this->transaction['bankTransId'],
      'bankTransNdx' => $this->transaction['ndx'],

      'docState' => 4000, 'docStateMain' => 2,
    ];
    $newCreditNdx = $tableCredits->dbInsertRec($creditItem);
    $tableCredits->docsLog($newCreditNdx);
	}
}
