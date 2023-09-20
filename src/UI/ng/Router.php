<?php

namespace Shipard\UI\ng;

use E10\utils, E10\Utility, \e10\Response, e10\Application;



/**
 * class Router
 */
class Router extends Utility
{
	var $uiCfg = NULL;
	var $uiId = '';
	var $uiRoot = '';
	var $urlPath = [];
	var ?\Shipard\UI\ng\TemplateUI $uiTemplate = NULL;

	public function setUIId($uiId)
	{
		if (!$uiId)
		{ // ui/...
			for ($ii = 2; $ii < count($this->app->requestPath); $ii++)
				$this->urlPath[] = $this->app->requestPath[$ii];

			$first = $this->app->requestPath(1);
			$this->uiId = $first;
			$this->uiRoot = $this->app()->urlRoot.'/ui/'.$first.'/';
		}
		else
		{ // domain
			for ($ii = 0; $ii < count($this->app->requestPath); $ii++)
				$this->urlPath[] = $this->app->requestPath[$ii];

			$this->uiId = $uiId;
			$this->uiRoot = '/';
		}
	}

	public function createLoginRequest ()
	{
		$fromPath = $this->app()->requestPath();
		header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST']. $this->uiRoot.'user/login'.$fromPath);
		return new Response ($this->app, "access disabled, please login...", 302);
	}

	protected function createObject ($objectDefinition)
	{
		$second = $this->urlPath[0];
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
		$this->uiTemplate = new \Shipard\UI\ng\TemplateUI ($this->app());
		$this->uiTemplate->data['uiRoot'] = $this->uiRoot;
		$this->uiTemplate->uiRoot = $this->uiRoot;

		header ('Cache-control: no-store');

		$this->app->mobileMode = TRUE;

		$first = $this->urlPath[0];

		$this->uiCfg = $this->app()->cfgItem('e10.ui.uis.'.$this->uiId, NULL);
		if (!$this->uiCfg)
		{
			return new Response ($this->app, "invalid url 1/".$this->uiId, 404);
		}

		if ($first === 'a')
		{
			$this->checkCodeActivation($this->urlPath[1] ?? '');
			die();
		}
		if ($first === 'manifest.webmanifest')
		{
			$object = $this->app->createObject('Shipard.UI.ng.WebManifest');
			$object->uiRouter = $this;
			$object->uiCfg = $this->uiCfg;
			return new Response ($this->app, $object->createPageCode(), 200);
		}
		elseif ($first === 'sw.js')
		{
			$dsMode = $this->app->cfgItem ('dsMode', Application::dsmTesting);
			header ('Content-type: text/javascript', TRUE);

			if (0 && $dsMode !== Application::dsmDevel)
				header ('X-Accel-Redirect: ' . $this->app->urlRoot.'/e10-modules/.cfg/mobile/e10swm.js');
			else
				header ('X-Accel-Redirect: ' . $this->app->urlRoot.'/www-root/.ui/ng/js/e10-service-worker.js');
			die();
		}
		elseif ($first === 'imgs')
		{
			$resizer = new \Shipard\Base\ImageResizer ($this->app());
			$resizer->run ();
			die();
		}

		$object = NULL;
		if ($first === 'user')
			$object = $this->app->createObject('Shipard.UI.ng.Login');
		elseif ($first === 'auth')
		{
			$object = $this->app->createObject('Shipard.UI.ng.Auth');
		}
		else
		{
			if (!$this->checkUserLogin())
				return $this->createLoginRequest ();

			if ($first === 'api')
				return $this->routeApiV2();

			if ($this->uiCfg)
			{
				$object = $this->app->createObject('Shipard.UI.ng.AppPageUI');
				$object->uiCfg = $this->uiCfg;
			}
		}
		if ($object)
		{
			$object->uiRouter = $this;
			$object->uiTemplate = $this->uiTemplate;
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

	function routeApiV2()
	{
		if (!isset($this->urlPath[1]) || $this->urlPath[1] !== 'v2')
		{
			return $this->app()->routeApiRun();
		}

		$requestParamsStr = $this->app()->postData();
		if ($requestParamsStr === '')
		{
			return new Response ($this->app(), "blank request", 404);
		}

		$requestParams = json_decode($requestParamsStr, TRUE);
		if (!$requestParams)
		{
			return new Response ($this->app(), "invalid request data", 404);
		}

		$o = new \Shipard\Api\v2\Router($this->app());
		$o->uiRouter = $this;
		$o->setRequestParams($requestParams);
		return $o->run();
	}

	protected function checkUserLogin()
	{
		$a = new \e10\users\libs\Authenticator($this->app());
		return $a->checkSession();
	}

	public function urlPart($idx)
	{
		return $this->urlPath[$idx] ?? '';
	}

	public function pwaIcon()
	{
		if (isset($this->uiCfg['icons']['pwa']))
			return $this->uiCfg['icons']['pwa'];

		$dsIcon = $this->app->dsIcon();
		return $dsIcon['iconUrl'];
	}

	protected function checkCodeActivation($shortId)
	{
		$redirTo = $this->uiRoot.'/user/activate/'.$shortId;
		$redirTo = str_replace('//', '/', $redirTo);
		header ('Location: ' . $redirTo);
	}
}
