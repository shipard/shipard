<?php

namespace services\persons\libs;
use \Shipard\Base\Utility;

/** 
 * class CoreObject
 */
class CoreObject extends Utility
{
  var ?\services\persons\libs\Log $log = NULL;

  CONST prtCZAresInit = 1, prtCZRZPInit = 2, prtCZAresCore = 3, prtCZAresRZP = 4, prtCZRZP = 5, prtCZVAT = 6;
  CONST idtVATPrimary = 0, idtVATSecondary = 1, idtOIDPrimary = 2, idtTAXPrimary = 3;

  public function __construct ($app)
	{
		parent::__construct($app);
    $this->log = new \services\persons\libs\Log($this->app());
	}

  static function addressText(array $address)
	{
		$parts = [];
		if ($address['specification'] !== '')
			$parts[] = $address['specification'];
		if ($address['street'] !== '')
			$parts[] = $address['street'];
		if ($address['city'] !== '')
			$parts[] = $address['city'];
		if ($address['zipcode'] !== '')
			$parts[] = $address['zipcode'];

		return implode(', ', $parts);
	}
}