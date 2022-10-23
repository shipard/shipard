<?php

namespace Shipard\UI\OldMobile;

use E10\utils, E10\Utility, \e10\Response, e10\Application;



/**
 * Class Router
 * @package mobileui
 */
class Router extends Utility
{
	public function createLoginRequest ()
	{
		header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST']. $this->app->urlRoot . "/mapp/login");
		return new Response ($this->app, "access disabled, please login...", 302);
	} // createLoginRequest

	protected function createObject ($objectDefinition)
	{
		$second = $this->app->requestPath(2);
		$object = NULL;

		switch ($objectDefinition['object'])
		{
			case 'viewer':
							if ($second === '')
								$object = $this->app->createObject('Shipard.UI.OldMobile.Viewer');
							else
								$object = $this->app->createObject('Shipard.UI.OldMobile.DocumentDetail');
							break;
			case 'dashboard': $object = $this->app->createObject('Shipard.UI.OldMobile.Dashboard'); break;
			case 'report': $object = $this->app->createObject('Shipard.UI.OldMobile.Report'); break;
			case 'maps': $object = $this->app->createObject('Shipard.UI.OldMobile.Maps'); break;
		}

		if ($object)
			$object->setDefinition ($objectDefinition);

		return $object;
	}

	protected function uiItem ($itemId)
	{
		$parts = explode ('.', $itemId);
		$path = '';
		if (count($parts) === 2)
			$path = 'mobileui.' . $parts[0] . '.items.' . $parts[1];
		else
		if (count($parts) === 3)
			$path = 'mobileui.' . $parts[0] . '.groups.'.$parts[1].'.items.' . $parts[2];

		$def = utils::cfgItem ($this->app->appSkeleton, $path, NULL);
		if ($def)
		{
			$def['itemId'] = $itemId;
		}
		return $def;
	}

	public function run ()
	{
		header ("Cache-control: no-store");

		$this->app->mobileMode = TRUE;

		$first = $this->app->requestPath(1);

		if ($first === 'manifest.webmanifest')
		{
			$object = $this->app->createObject('Shipard.UI.OldMobile.WebManifest');
			return new Response ($this->app, $object->createPageCode(), 200);
		}
		elseif ($first === 'sw.js')
		{
			$dsMode = $this->app->cfgItem ('dsMode', Application::dsmTesting);
			header ('Content-type: text/javascript', TRUE);

			if ($dsMode !== Application::dsmDevel)
				header ('X-Accel-Redirect: ' . $this->app->urlRoot.'/e10-modules/.cfg/mobile/e10swm.js');
			else
				header ('X-Accel-Redirect: ' . $this->app->urlRoot.'/e10-client/lib/js/e10-service-worker.js');
			die();
		}

		if ($first === 'login')
		{
			$object = $this->app->createObject('Shipard.UI.OldMobile.Login');
		}
		else
		{
			if (!$this->app->checkUserRights (NULL, 'user'))
				return $this->createLoginRequest ();

			touch (__APP_DIR__.'/tmp/api/access/'.$this->app->user->data ['id'].'_'.$_SERVER ['REMOTE_ADDR'].'_'.$this->app->deviceId);

			if ($first === '' || $first === '!')
				$object = $this->app->createObject('Shipard.UI.OldMobile.StartMenu');
			else
			if ($first === 'widget')
				$object = $this->app->createObject('Shipard.UI.OldMobile.Widget');
			else
			if ($first === 'comboviewer')
				$object = $this->app->createObject('Shipard.UI.OldMobile.ComboViewer');
			else
			if ($first === 'camera')
				$object = $this->app->createObject('Shipard.UI.OldMobile.Camera');
			else
			{
				$objectDefinition = $this->uiItem($first);
				$object = $this->createObject($objectDefinition);
			}
		}
		if ($object)
		{
			$object->run ();

			if (!$object->appMode)
				return new Response ($this->app, $object->createPageCode(), 200);

			$r = new Response ($this->app, '');
			$r->setMimeType('application/json');

			$data = ['htmlCode' => $object->createPageCode(), 'pageInfo' => $object->pageInfo];
			$r->add ('objectType', 'test');
			$r->add ('object', $data);

			return $r;
		}

		return new Response ($this->app, "invalid url", 404);
	}
}
