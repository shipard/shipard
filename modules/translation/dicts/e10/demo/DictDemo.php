<?php

namespace translation\dicts\e10\demo;
use \e10\Application, \e10\utils;

class DictDemo
{
	 static$path = __APP_DIR__.'/e10-modules/translation/dicts/e10/demo';
	 static$baseFileName = 'DictDemo';
	 static$data = NULL;

		const
			 diSelectLoginUser = 0
	;


	static function init()
	{
		if (self::$data)
			return;

		$langId = Application::$userLanguageCode;
		$fn = self::$path.'/'.self::$baseFileName.'.'.$langId.'.data';
		$strData = file_get_contents($fn);
		self::$data = unserialize($strData);
	}

	static function text($id)
	{
		self::init();
		return self::$data[$id];
	}

	static function es($id)
	{
		self::init();
		return utils::es(self::$data[$id]);
	}
}
