<?php

namespace lib\persons;

/**
 * Class PersonInfo
 * @package lib\persons
 */
class PersonInfo extends \lib\core\DocumentInfo
{
	public function createInfo ($personNdx, $card, $options = [])
	{
		$this->personNdx = $personNdx;
		$this->card = $card;
	}
}

