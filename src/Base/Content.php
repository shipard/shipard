<?php

namespace Shipard\Base;


class Content extends Utility
{
	var $content = [];

	public function addContent ($part, $contentPart = NULL)
	{
		if (is_array($part) && $contentPart === NULL)
		{
			$this->content['body'][] = $part;
			return;
		}

		if ($contentPart === FALSE)
			return;

		$this->content[$part][] = $contentPart;
	}

	function create (){}
}
