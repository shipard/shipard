<?php

namespace Shipard\UI\ng;
use \Shipard\Utils\Json;


/**
 * class WebManifest
 */
class WebManifest extends \Shipard\UI\ng\AppPageBlank
{
	public function run()
	{
	}

	public function createPageCode()
	{
		$themeStatusColor = '#212529';
		$dsIcon = $this->app->dsIcon();

		$first = $this->app->requestPath(1);
		$uiCfg = $this->app()->cfgItem('e10.ui.uis.'.$first, NULL);

		$startUrl = '/';
		$scope = '/';

		if ($uiCfg && $uiCfg['pwaStartUrlBegin'] !== '')
		{
			$startUrl .= $uiCfg['pwaStartUrlBegin'];
		}

		$wm = [
			'name' => $this->uiRouter->uiCfg['fn'],
			'short_name' => $this->app->cfgItem ('options.core.ownerShortName', 'TEST'),
			'start_url' => $startUrl,
			'display' => 'standalone',
			'background_color' => $themeStatusColor,
			'theme_color' => $themeStatusColor,
			'scope' => $scope,
			'icons' => [],
		];

		if (substr($dsIcon['iconUrl'], -4, 4) === '.svg')
		{
			$wm['icons'][] = ['src' => $dsIcon['serverUrl'].'imgs/-i192/'.$dsIcon['fileName'], 'sizes' => '192x192', 'type' => 'image/png'];
			$wm['icons'][] = ['src' => $dsIcon['serverUrl'].'imgs/-i512/'.$dsIcon['fileName'], 'sizes' => '512x512', 'type' => 'image/png'];
			$wm['icons'][] = ['src' => $dsIcon['serverUrl'].'imgs/-i1024/'.$dsIcon['fileName'], 'sizes' => '1024x1024', 'type' => 'image/png'];
			$wm['icons'][] = ['src' => $dsIcon['serverUrl'].'imgs/-i1980/'.$dsIcon['fileName'], 'sizes' => '1980x1980', 'type' => 'image/png'];
			$wm['icons'][] = ['src' => $dsIcon['serverUrl'].$dsIcon['fileName'], 'type' => 'image/svg+xml'];
		}
		else
		{
			$wm['icons'][] = ['src' => $dsIcon['serverUrl'].'imgs/-i192/'.$dsIcon['fileName'], 'sizes' => '192x192'];
			$wm['icons'][] = ['src' => $dsIcon['serverUrl'].'imgs/-i512/'.$dsIcon['fileName'], 'sizes' => '512x512'];
		}

		$code = Json::lint($wm);

		return $code;
	}
}
