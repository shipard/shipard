<?php

namespace lib\themes;


use \e10\utils;


/**
 * Class AppThemeCompiler
 * @package lib\themes
 */
class AppThemeCompiler extends \E10\Utility
{
	var $modulesPath;
	var $srcLessPath;
	var $themeRoot;

	function init ()
	{
		$this->modulesPath = '/var/www/e10-modules/';
		$this->themeRoot = __APP_DIR__.'/themes/';
	}

	function compileTheme ($recData)
	{
		$this->themeRoot .= $recData['sn'];
		if (!is_dir($this->themeRoot))
			mkdir ($this->themeRoot, 0755, TRUE);

		if (!is_link(__APP_DIR__.'/e10-modules/wwwlibs'))
			symlink (__APP_DIR__.'/e10-modules/wwwlibs', __APP_DIR__.'/themes/wwwlibs');

		if (!is_link(__APP_DIR__.'/e10-modules/fonts'))
			symlink (__APP_DIR__.'/e10-modules/.cfg/themes/fonts', __APP_DIR__.'/themes/fonts');

		array_map ('unlink', glob ($this->themeRoot.'/*.less'));
		$this->prepareLessDesktop();

		// theme files
		file_put_contents($this->themeRoot.'/'.'0010-variables-'.$recData['sn'].'.less', $recData['codeVariables']);
		if ($recData['codeDecorators'] !== '')
			file_put_contents($this->themeRoot.'/'.'8000-decorators-'.$recData['sn'].'.less', $recData['codeDecorators']);

		// generate style.less
		$style = '';
		forEach (glob ($this->themeRoot.'/*.less') as $lessFile)
		{
			$lessBaseName = basename ($lessFile);
			$style .= "@import '$lessBaseName';\n";
		}

		file_put_contents($this->themeRoot.'/'.'style.less', $style);

		$result = $this->compileLessFile($this->themeRoot.'/'.'style.less', $this->themeRoot.'/'.'style.css');
		return $result;
	}

	function prepareLessDesktop ()
	{
		$this->srcLessPath = $this->modulesPath.'e10/server/css/';
		forEach (glob ($this->srcLessPath.'/*.less') as $lessFile)
		{
			$lessBaseName = basename ($lessFile);
			symlink ($lessFile, $this->themeRoot.'/'.$lessBaseName);
		}

		// -- old glyphish icons
		symlink ($this->modulesPath.'e10/server/icons/glyphish/style.less', $this->themeRoot.'/'.'0990-e10-app-icons.less');
	}

	function compileLessFile ($lessFileName, $cssFileName)
	{
		$cmd = "/bin/lessc --no-color -x $lessFileName $cssFileName 2>&1";

		$output = '';
		$fp = popen($cmd, 'r');
		while(!feof($fp))
			$output .= fread($fp, 1024);
		fclose($fp);

		$output = preg_replace ("/(in \\/.+\\/)/i", 'in ', $output);
		return $output;
	}
}
