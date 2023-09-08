#!/usr/bin/env php
<?php

if (!defined ('__SHPD_ROOT_DIR__'))
{
	$parts = explode('/', __DIR__);
	array_pop($parts);
	define('__SHPD_ROOT_DIR__', '/'.implode('/', $parts).'/');
}

define ("__APP_DIR__", getcwd());



require_once __SHPD_ROOT_DIR__ . '/src/boot.php';

use Shipard\Utils\Utils;
use Shipard\Utils\Json;

use \E10\CLI\Application;
use \E10\DataModel;

function parseArgs($argv)
{
	// http://pwfisher.com/nucleus/index.php?itemid=45
		array_shift ($argv);
		$out = array();
		foreach ($argv as $arg){
				if (substr($arg,0,2) == '--'){
						$eqPos = strpos($arg,'=');
						if ($eqPos === false){
								$key = substr($arg,2);
								$out[$key] = isset($out[$key]) ? $out[$key] : true;
						} else {
								$key = substr($arg,2,$eqPos-2);
								$out[$key] = substr($arg,$eqPos+1);
						}
				} else if (substr($arg,0,1) == '-'){
						if (substr($arg,2,1) == '='){
								$key = substr($arg,1,1);
								$out[$key] = substr($arg,3);
						} else {
								$chars = str_split(substr($arg,1));
								foreach ($chars as $char){
										$key = $char;
										$out[$key] = isset($out[$key]) ? $out[$key] : true;
								}
						}
				} else {
						$out[] = $arg;
				}
		}
		return $out;
}

/** Remove spaces and comments from JavaScript code
 * @param string code with commands terminated by semicolon
 * @return string shrinked code
 * @link http://vrana.github.io/JsShrink/
 * @author Jakub Vrana, http://www.vrana.cz/
 * @copyright 2012 Jakub Vrana
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2 (one or other)
 */
function jsShrink($input) {
	return preg_replace_callback('(
        (?:
            (^|[-+\([{}=,:;!%^&*|?~]|/(?![/*])|return|throw) # context before regexp
            (?:\s|//[^\n]*+\n|/\*(?:[^*]|\*(?!/))*+\*/)* # optional space
            (/(?![/*])(?:\\\\[^\n]|[^[\n/\\\\]|\[(?:\\\\[^\n]|[^]])++)+/) # regexp
            |(^
                |\'(?:\\\\.|[^\n\'\\\\])*\'
                |"(?:\\\\.|[^\n"\\\\])*"
                |([0-9A-Za-z_$]+)
                |([-+]+)
                |.
            )
        )(?:\s|//[^\n]*+\n|/\*(?:[^*]|\*(?!/))*+\*/)* # optional space
    )sx', 'jsShrinkCallback', "$input\n");
}

function jsShrinkCallback($match) {
	static $last = '';
	$match += array_fill(1, 5, null); // avoid E_NOTICE
	list(, $context, $regexp, $result, $word, $operator) = $match;
	if ($word != '') {
		$result = ($last == 'word' ? "\n" : ($last == 'return' ? " " : "")) . $result;
		$last = ($word == 'return' || $word == 'throw' || $word == 'async' || $word == 'break' ? 'return' : 'word');
	} elseif ($operator) {
		$result = ($last == $operator[0] ? "\n" : "") . $result;
		$last = $operator[0];
	} else {
		if ($regexp) {
			$result = $context . ($context == '/' ? "\n" : "") . $regexp;
		}
		$last = '';
	}
	return $result;
}

function html2rgb ($color)
{
	if ($color[0] == '#')
		$color = substr($color, 1);

	if (strlen($color) == 6)
		list ($r, $g, $b) = array ($color[0].$color[1], $color[2].$color[3], $color[4].$color[5]);
	elseif (strlen($color) == 3)
		list ($r, $g, $b) = array ($color[0].$color[0], $color[1].$color[1], $color[2].$color[2]);
	else
		return false;

	$r = hexdec($r); $g = hexdec($g); $b = hexdec($b);

	return array($r, $g, $b);
}


function copy_r ($path, $dest, $useSymlinks = false)
{
	if (is_dir ($path))
	{
		@mkdir ($dest);
		$objects = scandir($path);
		if (sizeof ($objects) > 0)
		{
			foreach( $objects as $file )
			{
				if( $file == "." || $file == ".." )
						continue;
				if (is_dir ($path.DIRECTORY_SEPARATOR.$file))
				{
					copy_r ($path.DIRECTORY_SEPARATOR.$file, $dest.DIRECTORY_SEPARATOR.$file, $useSymlinks);
				}
				else
				{
					if ($useSymlinks)
						symlink ($path.DIRECTORY_SEPARATOR.$file, $dest.DIRECTORY_SEPARATOR.$file);
					else
						copy ($path.DIRECTORY_SEPARATOR.$file, $dest.DIRECTORY_SEPARATOR.$file);
				}
			}
		}
		return true;
	}
	elseif (is_file ($path))
	{
		if ($useSymlinks)
			return symlink ($path, $dest);
		return copy ($path, $dest);
	}
	else
	{
		return false;
	}
}


class ShpdUIApp
{
	var $arguments;

	var $allTemplates = [];
	var $allLooks = [];

	public function __construct ()
	{
	}

	public function arg ($name)
	{
		if (isset ($this->arguments [$name]))
			return $this->arguments [$name];

		return FALSE;
	}

	public function command ($idx = 0)
	{
		if (isset ($this->arguments [$idx]))
			return $this->arguments [$idx];

		return "";
	}

	public function err ($msg)
	{
		echo $msg . "\r\n";
		return FALSE;
	}

	public function loadCfgFile ($fileName)
	{
		if (is_file ($fileName))
		{
			$cfgString = file_get_contents ($fileName);
			if (!$cfgString)
				return $this->err ("read file failed: $fileName");
			$cfg = json_decode ($cfgString, true);
			if (!$cfg)
				return $this->err ("parsing file failed: $fileName");
			return $cfg;
		}
		return $this->err ("file not found: $fileName");
	}

	public function msg ($msg)
	{
		echo '* ' . $msg . "\r\n";
	}

	public function createAppThemes ($forceUIId = '')
	{
		$uiDefs = $this->loadCfgFile('ui/ui.json');
		if (!$uiDefs)
			return $this->msg("ERROR: file `ui/ui.json` not found...");

		$destFolder = 'www-root/.ui/';
		if (!is_dir($destFolder))
			mkdir ($destFolder);


		forEach ($uiDefs['clients'] as $uiTypeId => $uiDef)
		{
			if ($forceUIId !== '' && $forceUIId !== $uiTypeId)
				continue;
			echo "* {$uiTypeId}\n";

			$destFolder = 'www-root/.ui/'.$uiTypeId.'/';
			if (!is_dir($destFolder))
				mkdir ($destFolder);
			$destFolder .= 'themes/';
			if (!is_dir($destFolder))
				mkdir ($destFolder);

			// -- themes
			echo "  - themes:\n";
			$themes = $this->loadCfgFile('ui/clients/'.$uiTypeId.'/themes.json');
			$themeFullList = [];
			$themeShortList = [];
			foreach ($themes as $themeId => $themeDef)
			{
				echo "    > {$themeId}\n";
				$themePath = 'ui/clients/' . $uiTypeId . '/themes/' . $themeId;

				$themeDestFolder = getcwd().'/'.$destFolder . $themeId.'/';
				if (!is_dir($themeDestFolder))
					mkdir ($themeDestFolder);

				$theme = $this->loadCfgFile('ui/clients/'.$uiTypeId.'/themes/'. $themeId . '/theme.json');
				$themeShortList[$themeId] = $theme['name'];
				$themeFullList[$themeId] = $theme;

				//echo ("      - cd $themePath && lessc -x style.less > {$destCssFileName}\n");
				if ($uiTypeId === 'ng')
				{
					//$cmd = "export LC_ALL=en_US.UTF.8 && cd {$this->destPath} && sass $dstFileName $dstCssFileName --style compressed --no-source-map 2>&1";
					$destCssFileName = $themeDestFolder.'style.css';
					passthru ("cd $themePath && sass theme.scss --style compressed > $destCssFileName");
				}
				else
				{
					$destCssFileName = $themeDestFolder.'style.css';
					passthru ("cd $themePath && lessc -x style.less > $destCssFileName");
				}

				$sha384 = hash_file('sha384', $destCssFileName);
				$md5 = md5_file($destCssFileName);

				$themeFullList[$themeId]['integrity'] = ['sha384' => $sha384, 'md5' => $md5];
			}

			file_put_contents ($destFolder.'/themesList.json', utils::json_lint (json_encode ($themeShortList)));
			file_put_contents ($destFolder.'/themes.json', utils::json_lint (json_encode ($themeFullList)));
		}

		return TRUE;
	}


	public function jsApp ()
	{
		$uiDefs = $this->loadCfgFile('ui/ui.json');
		if (!$uiDefs)
			return $this->msg("ERROR: file `ui/ui.json` not found...");

		$destFolder = 'www-root/.ui/';
		if (!is_dir($destFolder))
			mkdir ($destFolder);
		$info = [];
		forEach ($uiDefs['clients'] as $uiTypeId => $uiDef)
		{
			echo "* {$uiTypeId}\n";

			$destFolder = 'www-root/.ui/'.$uiTypeId.'/';
			if (!is_dir($destFolder))
				mkdir ($destFolder);
			$destFolder .= 'js/';
			if (!is_dir($destFolder))
				mkdir ($destFolder);

			$srcDir = 'ui/clients/' . $uiTypeId . '/js/';

			$this->createJavascript(getcwd().'/'.$srcDir, getcwd().'/'.$destFolder, $info, $uiTypeId);
		}

		file_put_contents('ui/clients/'.'files.json', Json::lint($info));
		file_put_contents('ui/clients/'.'files.data', serialize($info));

		return TRUE;
	}

	public function createJavascript ($srcDir, $dstDir, &$info, $infoKey)
	{
		$pkg = utils::loadCfgFile($srcDir.'package.json');
		if (!$pkg)
			return $this->err("Package file '".$srcDir.'package.json'."' not found.");

		$filesData = [];

		$finalFileString = '';
		forEach ($pkg['srcFiles'] as $file)
		{
			$oneFileStr = file_get_contents($srcDir.$file['fileName']);
			if (isset ($file['minify']) && !$file['minify'])
				$finalFileString .= $oneFileStr;
			else
			{
				$shrinkedScript = jsShrink($oneFileStr);
				$finalFileString .= $shrinkedScript;
			}
		}

		$finalFileName = $dstDir.'client.js';
		file_put_contents($finalFileName, $finalFileString);

		$sha384_ascii = hash_file('SHA384', $finalFileName);
		$sha384_base64 = base64_encode(hash_file('SHA384', $finalFileName, true));
		$info[$infoKey] = [
			'client.js' => [
				'sha384' => ''.$sha384_ascii,
				'integrity' => 'sha384-'.$sha384_base64,
				'ver' => md5_file($finalFileName),
			]
		];
	}

	public function jsWeb ()
	{
		$uiDefs = $this->loadCfgFile('ui/ui.json');
		if (!$uiDefs)
			return $this->msg("ERROR: file `ui/ui.json` not found...");

		$destFolder = 'www-root/.web-apps/';
		if (!is_dir($destFolder))
			mkdir ($destFolder);
		$info = [];
		forEach ($uiDefs['web-apps'] as $uiTypeId => $uiDef)
		{
			echo "* {$uiTypeId}\n";

			$destFolder = 'www-root/.web-apps/'.$uiTypeId.'/';
			if (!is_dir($destFolder))
				mkdir ($destFolder);
			$destFolder .= 'js/';
			if (!is_dir($destFolder))
				mkdir ($destFolder);

			$srcDir = 'ui/web-apps/' . $uiTypeId . '/js/';

			$this->createJavascript(getcwd().'/'.$srcDir, getcwd().'/'.$destFolder, $info, $uiTypeId);
		}

		file_put_contents('ui/web-apps/'.'files.json', Json::lint($info));
		file_put_contents('ui/web-apps/'.'files.data', serialize($info));

		return TRUE;
	}

	public function ngApp ()
	{
		$this->createAppThemes ('ng');
	}

	public function webTemplate ()
	{
		$templateCfg = utils::loadCfgFile('template.json');
		if (!$templateCfg)
			return $this->err ("file 'template.json' not found");

		if (isset($templateCfg['disabled']))
			return $this->err ("template is disabled...");

		if (!isset($templateCfg['ndx']))
			return $this->err ("field 'ndx' not found");
		if (!isset($templateCfg['id']))
			return $this->err ("field 'id' not found");
		if (!isset($templateCfg['name']))
			return $this->err ("field 'name' not found");

		$looks = [];
		$scanMask = 'looks/*.json';
		forEach (glob($scanMask) as $lookFile)
		{
			echo '.';
			$lookCfg = utils::loadCfgFile($lookFile);
			if (!$lookCfg)
			{
				echo "\n";
				return $this->err("SYNTAX ERROR: $lookFile");
			}

			$cssVariables = ":root {\n";
			$sassVariables = "@charset \"UTF-8\";\n\n";
			foreach ($lookCfg['lookParams'] as $key => $value)
			{
				$sassVariables .= '$'.$key.': '.$value."; \n";
				$cssVariables .= "\t".'--'.$key.': '.$value.";\n";
			}
			$cssVariables .= "}\n\n";

			$bn = substr (basename($lookFile), 0, -5);
			$ver = [];
			foreach ($templateCfg['styles'] as $styleId)
			{
				$sassContent = $sassVariables;
				$sassContent .= "\n@import \"../sass/$styleId.scss\";\n";
				$sassContent .= $cssVariables;

				$sassFileName = 'styles/' . $bn . '.tmp.scss';
				file_put_contents($sassFileName, $sassContent);

				$cssFileName = 'styles/'.$bn.'-'.$styleId.'.css';
				$cmd = "sass {$sassFileName} $cssFileName";

				$cmd .= ' --style compressed';
				//$cmd .= ' --sourcemap=none';

				passthru($cmd);

				$ver[$styleId] = md5_file($cssFileName);
			}

			$dstVerFileName = 'styles/'.$bn.'-versions.json';
			file_put_contents($dstVerFileName, json_encode($ver));

			$looks [$lookCfg['ndx']] = ['id' => $bn, 'name' => $lookCfg['name']];

			$themeColor = '#00508a';
			if (isset($lookCfg['lookParams']['e10w-brand-primary-bg']))
				$themeColor = $lookCfg['lookParams']['e10w-brand-primary-bg'];

			$this->allLooks[$lookCfg['ndx']] = ['id' => $bn, 'name' => $lookCfg['name'], 'themeColor' => $themeColor, 'template' => $templateCfg['id']];
		}

		file_put_contents('looks.json', Json::lint($looks));
		$this->allTemplates[$templateCfg['ndx']] = ['id' => $templateCfg['id'], 'name' => $templateCfg['name']];
		if (isset($templateCfg['checkModule']))
			$this->allTemplates[$templateCfg['ndx']]['checkModule'] = $templateCfg['checkModule'];

		echo ' ok'."\n";
		return TRUE;
	}


	public function webTemplates ()
	{
		$scanMask = '*';
		forEach (glob ($scanMask, GLOB_ONLYDIR) as $templateDir)
		{
			if (!is_file($templateDir . '/template.json'))
				continue;

			echo '# '.$templateDir.': ';

			$oldDir = getcwd();
			chdir($templateDir);
			$this->webTemplate();
			chdir($oldDir);
		}

		file_put_contents('templates.json', Json::lint($this->allTemplates));
		file_put_contents('looks.json', Json::lint($this->allLooks));

		return TRUE;
	}


	public function run ($argv)
	{
		$this->arguments = parseArgs($argv);

		if (count ($this->arguments) == 0)
			return $this->help ();

		switch ($this->command ())
		{
			case	'app-themes':			return $this->createAppThemes ();
			case	'app-js':					return $this->jsApp();
			case	'app-ng':					return $this->ngApp();
			case	'web-templates':	return $this->webTemplates ();
			case	'web-js':					return $this->jsWeb();
		}

		echo ("unknown command...\n");

		return FALSE;
	}

	function help ()
	{
		echo
			"usage: shpd-ui command arguments\r\n\r\n" .
			"commands:\r\n" .
			"   app-themes: build app-themes\r\n" .
			"   app-js: build javascript files\r\n" .
			"   app-ng: build css + javascript for ng ui\r\n" .
			"   web-templates: build web templates\r\n" .
			"\r\n";

		return true;
	}
}


$app = new ShpdUIApp ();
$app->run ($argv);
