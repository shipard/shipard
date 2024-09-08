<?php

namespace e10pro\vendms\libs;
use \Shipard\Base\Utility;


/**
 * class ObjectBoxQuantity
 */
class ObjectBoxQuantity extends Utility
{
  var $requestParams = NULL;
  var $result = ['success' => 0];

  public function setQuantity()
  {
    $currentQuantity = 0;

    $q = [];
    array_push($q, 'SELECT SUM(quantity) AS boxQuantity');
    array_push($q, ' FROM [e10pro_vendms_vendmsJournal]');
    array_push($q, ' WHERE [box] = %i', $this->requestParams['boxNdx']);
    $qrec = $this->db()->query($q)->fetch();
    if ($qrec)
      $currentQuantity = $qrec['boxQuantity'];

    $newQuantity = intval($this->requestParams['quantity']);

    $quantity = $newQuantity - $currentQuantity;

		$moveType = 1;
		if ($quantity < 0)
			$moveType = 2;

    // -- journal
    $journalItem = [
      'created' => new \DateTime(),
      'vm' => 1,
      'item' => $this->requestParams['itemNdx'],
      'box' => $this->requestParams['boxNdx'],
      'moveType' => $moveType,
      'quantity' => $quantity,
    ];
    $this->db()->query('INSERT INTO [e10pro_vendms_vendmsJournal] ', $journalItem);

    // -- done
    $this->result['success'] = 1;
  }

  public function run()
  {
    $this->setQuantity();
  }
}
