<?php

namespace e10\persons;

use e10\Response, e10\Utility;


/**
 * Class HostingOperations
 * @package e10\persons
 */
class HostingOperations extends Utility
{
	var $object = [];

	public function init ()
	{
	}

	function changeLogin ()
	{
		$srcLogin = hex2bin($this->app()->requestPath(3));
		$dstLogin = hex2bin($this->app()->requestPath(4));

		// -- test dst login
		$personRec = $this->db()->query('SELECT * FROM [e10_persons_persons] WHERE [login] = %s', $dstLogin,
			' AND [accountState] = 1 AND [docState] IN %in', [1000, 4000, 8000])->fetch();
		if ($personRec)
		{
			$this->object['msg'] = "New login exist";
			return;
		}

		// -- test src login
		$personRec = $this->db()->query('SELECT * FROM [e10_persons_persons] WHERE [login] = %s', $srcLogin,
			' AND [accountState] = 1 AND [docState] IN %in', [1000, 4000, 8000])->fetch();
		if (!$personRec)
		{
			$this->object['msg'] = "Src login not found";
			return;
		}

		// -- update
		$loginHash = md5(strtolower(trim($dstLogin)));

		$update = ['login' => $dstLogin, 'loginHash' => $loginHash];
		$this->db()->query('UPDATE [e10_persons_persons] SET ', $update, ' WHERE ndx = %i', $personRec['ndx']);

		$this->object['status'] = 1;
	}

	public function run ()
	{
		$this->object['status'] = 0;

		$remoteAddress = $_SERVER ['REMOTE_ADDR'];
		if ($remoteAddress === '95.168.210.18' || $remoteAddress === '10.23.19.111')
		{
			$operation = $this->app()->requestPath(2);
			if ($operation === 'change-login')
				$this->changeLogin();
		}
		else
		{
			$this->object['msg'] = "Access Denied";
		}

		$response = new Response ($this->app);
		$response->add ('objectType', 'hosting-op');
		$response->add ('object', $this->object);
		return $response;
	}
}
