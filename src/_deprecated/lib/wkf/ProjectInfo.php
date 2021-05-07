<?php

namespace lib\wkf;


/**
 * Class ProjectInfo
 * @package lib\wkf
 */
class ProjectInfo extends \lib\core\DocumentInfo
{
	public function createInfo ($projectNdx, $card, $options = [])
	{
		$this->projectNdx = $projectNdx;
		$this->card = $card;
	}
}

