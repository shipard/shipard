<?php

namespace Shipard\UI\ng;

use E10\utils, E10\Utility, \e10\Response, e10\Application;



/**
 * class Router
 */
class Router extends Utility
{
	public function createLoginRequest ()
	{
		$fromPath = $this->app()->requestPath();

		header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST']. $this->app->urlRoot . "/ui/login".$fromPath);
		//header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST']. $this->app->urlRoot . "/user/login".$fromPath);
		return new Response ($this->app, "access disabled, please login...", 302);
	}

	protected function createObject ($objectDefinition)
	{
		$second = $this->app->requestPath(2);
		$object = NULL;

		switch ($objectDefinition['object'])
		{
			case 'viewer':
							if ($second === '')
								$object = $this->app->createObject('Shipard.UI.ng.Viewer');
							else
								$object = $this->app->createObject('Shipard.UI.ng.DocumentDetail');
							break;
			case 'dashboard2': $object = $this->app->createObject('Shipard.UI.ng.Dashboard'); break;
			case 'report': $object = $this->app->createObject('Shipard.UI.ng.Report'); break;
			case 'maps': $object = $this->app->createObject('Shipard.UI.ng.Maps'); break;
		}

		if ($object)
			$object->setDefinition ($objectDefinition);

		return $object;
	}

	public function run ()
	{
		$this->app()->ngg = 1;

		header ("Cache-control: no-store");

		$this->app->mobileMode = TRUE;

		$first = $this->app->requestPath(1);
		$second = $this->app->requestPath(2);

		if ($second === 'manifest.webmanifest')
		{
			$object = $this->app->createObject('Shipard.UI.ng.WebManifest');
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

		$object = NULL;
		if ($first === 'login')
		{
			$object = $this->app->createObject('Shipard.UI.ng.Login');
		}
		else
		{
			if (!$this->app->checkUserRights (NULL, 'user'))
				return $this->createLoginRequest ();

			$uiCfg = $this->app()->cfgItem('e10.ui.uis.'.$first, NULL);
			if ($uiCfg)
			{
				$object = $this->app->createObject('Shipard.UI.ng.AppPageUI');
				$object->uiCfg = $uiCfg;
			}

			if ($first === '' || $first === '!')
				$object = $this->app->createObject('Shipard.UI.ng.StartMenu');
			elseif ($first === 'widget')
				$object = $this->app->createObject('Shipard.UI.ng.Widget');
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
