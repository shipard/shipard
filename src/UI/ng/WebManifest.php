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
		$pwaIcon = $this->uiRouter->pwaIcon();

		$uiCfg = $this->uiCfg;

		$startUrl = '/';
		$scope = '/';

		if ($uiCfg && $uiCfg['pwaStartUrlBegin'] !== '')
		{
			$startUrl .= $uiCfg['pwaStartUrlBegin'];
		}
		else
		{
			$a = new \e10\users\libs\Authenticator($this->app());
			$sessionInfo = $a->sessionInfo();

			if ($sessionInfo && isset($sessionInfo['apiKey']) && $sessionInfo['apiKey'])
			{
				$apiKeyInfo = $this->db()->query('SELECT * FROM [e10_users_apiKeys] WHERE [ndx] = %i', $sessionInfo['apiKey'], ' AND [docState] = %i', 4000)->fetch();
				if ($apiKeyInfo)
					$startUrl .= 'auth/robot/'.$apiKeyInfo['key'];
			}
		}

		$wm = [
			'name' => $this->uiRouter->uiCfg['pwaTitle'],
			'short_name' => $this->app->cfgItem ('options.core.ownerShortName', 'TEST'),
			'description' => $this->uiRouter->uiCfg['fn'],
			'start_url' => $startUrl,
			'id' => $startUrl,
			'display' => 'standalone',
			'background_color' => $themeStatusColor,
			'theme_color' => $themeStatusColor,
			'scope' => $scope,
			'icons' => [],
		];

		if (substr($pwaIcon, -4, 4) === '.svg')
		{
			if (str_starts_with($pwaIcon, 'att/'))
			{
				$wm['icons'][] = ['src' => 'imgs/-i192/'.$pwaIcon, 'sizes' => '192x192', 'type' => 'image/png'];
				$wm['icons'][] = ['src' => 'imgs/-i512/'.$pwaIcon, 'sizes' => '512x512', 'type' => 'image/png'];
				$wm['icons'][] = ['src' => 'imgs/-i1024/'.$pwaIcon, 'sizes' => '1024x1024', 'type' => 'image/png'];
				$wm['icons'][] = ['src' => 'imgs/-i1980/'.$pwaIcon, 'sizes' => '1980x1980', 'type' => 'image/png'];
				$wm['icons'][] = ['src' => $pwaIcon, 'type' => 'image/svg+xml'];
			}
			else
				$wm['icons'][] = ['src' => $pwaIcon, 'type' => 'image/svg+xml'];
		}
		else
		{
			if (str_starts_with($pwaIcon, 'att/'))
			{
				$wm['icons'][] = ['src' => 'imgs/-i192/'.$pwaIcon, 'sizes' => '192x192'];
				$wm['icons'][] = ['src' => 'imgs/-i512/'.$pwaIcon, 'sizes' => '512x512'];
			}
			else
				$wm['icons'][] = ['src' => $pwaIcon];
		}

		$code = Json::lint($wm);

		return $code;
	}
}
