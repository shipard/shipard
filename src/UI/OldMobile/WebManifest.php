<?php

namespace Shipard\UI\OldMobile;

use E10\json;
use E10\utils;


/**
 * Class WebManifest
 * @package ui\mobile
 */
class WebManifest extends \Shipard\UI\OldMobile\PageObject
{
	public function run()
	{
	}

	public function createPageCode()
	{
		$mobileuiTheme = $this->app->cfgItem ('options.appearanceApp.mobileuiTheme', 'md-teal');
		if ($mobileuiTheme === '')
			$mobileuiTheme = 'md-teal';
		$themeStatusColor = self::$themeStatusColor[$mobileuiTheme];
		$dsIcon = $this->app->dsIcon();

		$wm = [
			'name' => $this->app->cfgItem ('options.core.ownerShortName', 'TEST'),
			'short_name' => $this->app->cfgItem ('options.core.ownerShortName', 'TEST'),
			'start_url' => $this->app->urlRoot.'/mapp/',
			'display' => 'standalone',
			'background_color' => $themeStatusColor,
			'theme_color' => $themeStatusColor,
			'scope' => '/',
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

		$code = json::lint($wm);

		return $code;
	}
}

