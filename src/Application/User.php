<?php

namespace Shipard\Application;
use Shipard\Utils\Utils;

class User
{
	var $data = [];
	/** @var \Shipard\Base\Application */
	var $app;

	public function setData (array $data) {$this->data = $data;}
	public function setGroups (array $groups) {$this->data['groups']= $groups;}

	public function data ($key = '')
	{
		if ($key !== '')
		{
			if (isset ($this->data [$key]))
				return $this->data [$key];

			if ($key === 'picture')
			{
				$this->data ['picture'] = Utils::userImage($this->app, $this->data['id'] ?? 0, $this->data);
				return $this->data ['picture'];
			}

			return FALSE;
		}

		return $this->data;
	}

	public function isAuthenticated () {return isset ($this->data ['id']);}
}

