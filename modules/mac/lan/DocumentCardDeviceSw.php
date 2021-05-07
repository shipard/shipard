<?php

namespace mac\lan;

use e10\utils;


/**
 * Class DocumentCardDeviceSw
 * @package mac\lan
 */
class DocumentCardDeviceSw extends \mac\lan\DocumentCardDevice
{
	public function createContentBody ()
	{
		$licenseTypes = $this->app()->cfgItem('mac.lan.sw.applications.licenses');
		// -- applications
		if (count($this->applications))
		{
			$title = [['icon' => 'icon-gift', 'text' => 'Aplikace']];
			$table = [];

			foreach ($this->applications as $appNdx => $app)
			{
				$info = [];
				$info[] = ['text' => $app['name'], 'class' => ''];

				$licenseType = $licenseTypes[$app['license']]['name'];
				$appInfo = ['name' => $info, 'licenseType' => $licenseType];

				if ($app['license'] === 3)
				{ // commercial
					if (isset($this->appLicenses[$appNdx]))
					{
						$li = ['text' => $this->appLicenses[$appNdx]['id'], 'icon' => 'icon-certificate'];
						$appInfo['license'] = $li;
						$appInfo['_options'] = ['class' => 'e10-row-plus'];
					} else
					{
						$li = ['text' => 'chybí', 'icon' => 'icon-exclamation-triangle'];
						$appInfo['_options'] = ['class' => 'e10-warning1'];
						$appInfo['license'] = $li;
					}
				}

				$table[] = $appInfo;
			}

			$h = ['#' => '#', 'name' => 'Název', 'licenseType' => 'Typ licence', 'license' => 'Licence'];
			$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
					'title' => $title,
					'header' => $h, 'table' => $table]);
		}

		// -- known packages
		if (count($this->knownPackages))
		{
			$title = [['icon' => 'icon-file-archive-o', 'text' => 'Balíčky nezařazené v aplikaci']];
			$table = [];

			foreach ($this->knownPackages as $app)
			{
				$info = [];
				$info[] = ['text' => $app['name'], 'class' => ''];
				$table[] = ['name' => $info];
			}

			$h = ['#' => '#', 'name' => 'Název'];
			$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
					'title' => $title,
					'header' => $h, 'table' => $table]);
		}

		// -- unknown packages
		if (count($this->unknownPackages))
		{
			$title = [['icon' => 'icon-exclamation-triangle', 'text' => 'Neznámé balíčky']];
			$table = [];

			foreach ($this->unknownPackages as $pkg)
			{
				$info = [];
				$n = $pkg['name'];
				$info[] = ['text' => $n, 'class' => ''];
				$info[] = [
						'docAction' => 'new', 'table' => 'mac.lan.swInstallPackages', 'icon' => 'icon-plus-circle',
						'text' => 'Balíček', 'type' => 'button', 'actionClass' => 'btn btn-xs btn-success',
						'class' => 'pull-right',
						'addParams' => '__fullName=' . urlencode($n) . '&__pkgNames=' . urlencode($n),
					//'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
				];
				$table[] = ['name' => $info];
			}

			$h = ['#' => '#', 'name' => 'Název'];
			$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
					'title' => $title,
					'header' => $h, 'table' => $table]);
		}
	}

	public function createContent ()
	{
		$this->loadSwData();

		$this->createContentHeader ();
		$this->createContentBody ();
	}
}
