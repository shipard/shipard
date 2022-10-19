<?php


namespace lib\web;

use \e10\Utility, \e10\json, \e10\uiutils, \Shipard\Utils\Utils;


/**
 * Class WebTemplateCustomSkin
 * @package lib\web
 */
class WebTemplateLook extends Utility
{
	var $templateLookRecData = NULL;
	var $urlRoot;
	var $templateRoot;
	var $options = NULL;

	var $lookParams = NULL;
	var $destPath = '';

	var $customSkinId = '';
	var $templateStyleVerCheckSum = '';
	var $templateStyleVerId = '0.0';
	var $skinVariables = '';
	var $needGenerate = FALSE;

	public function setTemplateLook ($templateLookRecData)
	{
		$this->templateLookRecData = $templateLookRecData;
	}

	function init ()
	{
		$name = $this->templateLookRecData['templateId'];

		$parts = explode ('.', $name);

		if (count ($parts) == 1)
		{
			$this->urlRoot = 'templates/' . $name;
			$this->templateRoot = __APP_DIR__ . '/templates/' . $name . '/';
		}
		else
		{
			$this->urlRoot = __SHPD_ROOT_DIR__.__SHPD_TEMPLATE_SUBDIR__. implode ('/', $parts);
			$this->templateRoot = __SHPD_ROOT_DIR__ . __SHPD_TEMPLATE_SUBDIR__ . implode ('/', $parts) . '/';
		}

		$fullOptionsName = $this->templateRoot . 'template.json';
		if (is_file($fullOptionsName))
		{
			$optionsStr = file_get_contents($fullOptionsName);
			$this->templateStyleVerCheckSum = md5($optionsStr);
			$this->options = json_decode($optionsStr, TRUE);

			if (isset($this->options['version']))
				$this->templateStyleVerId = $this->options['version'];
		}
		else
		{
			error_log("file `$fullOptionsName` not found [template: `$name`]");
			Utils::debugBacktrace();
		}

		$this->lookParams = json::decode($this->templateLookRecData['lookParams']);

		$this->prepareLook();
	}

	function prepareLook ()
	{
		$this->destPath = __APP_DIR__.'/templates/'.$this->templateLookRecData['lookId'];
		if (is_dir($this->destPath) === FALSE)
			Utils::mkDir ($this->destPath, 0775);

		$this->skinVariables = 	"@charset \"UTF-8\";\n\n";
		$this->skinVariables .= '// based on template version: '.$this->options['version'].' / '.$this->templateStyleVerCheckSum."\n\n";

		foreach ($this->options['lookParams']['columns'] as $oneCol)
		{
			if (!isset ($oneCol['cssType']))
				continue;
			if (uiutils::subColumnEnabled ($oneCol, $this->lookParams) === FALSE)
				continue;
			if ($oneCol['cssType'] === 'color')
			{
				$value = (isset($this->lookParams[$oneCol['id']])) ? $this->lookParams[$oneCol['id']] : '';
				if ($value === '')
					continue;
				$this->skinVariables .= '$'.$oneCol['id'].': '.$value.";\n";
			}
		}

		$oldSkinVariablesFileName = $this->destPath.'/'.$this->templateLookRecData['lookId'].'-skinVariables.scss';
		$oldSkinVariables = file_get_contents($oldSkinVariablesFileName);
		$oldSkinVariablesCheckSum = md5($oldSkinVariables);

		$newSkinVariablesCheckSum = md5($this->skinVariables);

		if ($oldSkinVariablesCheckSum !== $newSkinVariablesCheckSum)
			$this->needGenerate = TRUE;
	}

	function generateLook ()
	{
		$ver = [];

		foreach ($this->options['styles'] as $styleId)
		{
			$dstFileName = $this->destPath.'/'.$this->templateLookRecData['lookId'].'-'.$styleId.'.scss';
			$dstCssFileName = $this->destPath.'/'.$this->templateLookRecData['lookId'].'-'.$styleId.'.css';

			$styleContent = $this->skinVariables."\n\n";

			if ($this->templateLookRecData['lookStyleVars'] !== '')
				$styleContent .= $this->templateLookRecData['lookStyleVars']."\n\n";

			$styleContent .= "@import \"../../www-root/templates/web/".$this->options['id']."/sass/{$styleId}.scss\";\n";

			$styleContent .= $this->templateLookRecData['lookStyleExt'];

			$srcCheckSum = '';

			if (is_file($dstFileName))
			{
				$srcCheckSum = md5_file($dstFileName);
			}
			$dstCheckSum = md5($styleContent);

			if ($srcCheckSum === $dstCheckSum)
				continue;

			file_put_contents($dstFileName, $styleContent);

			$cmd = "export LC_ALL=en_US.UTF.8 && cd {$this->destPath} && sass $dstFileName $dstCssFileName --style compressed --no-source-map 2>&1";

			$output = '';
			$fp = popen($cmd, 'r');
			while(!feof($fp))
				$output .= fread($fp, 1024);
			fclose($fp);
			$output = preg_replace ("/(in \\/.+\\/)/i", 'in ', $output);

			$ver[$styleId] = md5_file($dstCssFileName);
		}

		$dstVerFileName = $this->destPath.'/'.$this->templateLookRecData['lookId'].'-versions.json';
		file_put_contents($dstVerFileName, json_encode($ver));

		$dstSkinVariablesFileName = $this->destPath.'/'.$this->templateLookRecData['lookId'].'-skinVariables.scss';
		file_put_contents($dstSkinVariablesFileName, $this->skinVariables);

		if (Utils::superuser())
		{
			// -- repair permissions
			exec('chgrp -R '.Utils::wwwGroup().' '.$this->destPath);
			// -- delete sass cache
			if (is_dir(__APP_DIR__.'/.sass-cache'))
				exec ('rm -rf '.__APP_DIR__.'/.sass-cache');
		}
	}

	public function check($templateLookRecData, $changedOnly = FALSE)
	{
		$this->setTemplateLook($templateLookRecData);
		$this->init();

		if ($changedOnly && !$this->needGenerate)
			return;

		$this->generateLook();
	}
}
