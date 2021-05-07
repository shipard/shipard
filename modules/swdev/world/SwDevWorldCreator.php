<?php


namespace swdev\world;

use e10\Utility, \e10\utils;


/**
 * Class SwDevWorldCreator
 * @package swdev\world
 */
class SwDevWorldCreator extends Utility
{
	var $devServerCfg = NULL;
	var $preferredLanguages = ['cs', 'de', 'en', 'es', 'et', 'fi', 'fr', 'hr', 'it', 'ja', 'nl', 'pt', 'ru', 'sk', 'zh'];

	protected function doMledozeCountries ()
	{
		echo "=== countries #1 ===\n";

		$data = utils::loadCfgFile(__APP_DIR__.'/e10-modules/swdev/world/data/mledoze_countries_countries.json');

		foreach ($data as $country)
		{
			$apiData = ['object-class-id' => 'swdev.world.UploaderWorld', 'operation' => 'upload', 'type' => 'countryMledoze', 'data' => $country];
			$result = $this->app->runApiCall($this->devServerCfg['devServerUrl'] . '/api', $this->devServerCfg['devServerApiKey'], $apiData);
			if (!$result || !isset($result['success']) || $result['success'] !== 1)
			{
				$this->app->err("ERROR!!!");
			}
		}
	}

	function doLanguages()
	{
		echo "=== languages ===\n";

		$data = utils::loadCfgFile(__APP_DIR__.'/e10-modules/swdev/world/data/language-codes-full_json.json');
		foreach ($data as $language)
		{
			$item = [
				'alpha2' => ($language['alpha2']) ? $language['alpha2'] : '',
				'alpha3b' => ($language['alpha3-b']) ? $language['alpha3-b'] : '',
				'alpha3t' => ($language['alpha3-t']) ? $language['alpha3-t'] : '',
				'name' => $language['English'],
			];

			$apiData = ['object-class-id' => 'swdev.world.UploaderWorld', 'operation' => 'upload', 'type' => 'language', 'data' => $item];
			$result = $this->app->runApiCall($this->devServerCfg['devServerUrl'] . '/api', $this->devServerCfg['devServerApiKey'], $apiData);
			if (!$result || !isset($result['success']) || $result['success'] !== 1)
			{
				$this->app->err("ERROR!!!");
			}
		}
	}

	function doCurrencies()
	{
		echo "=== currencies ===\n";

		$data = utils::loadCfgFile(__APP_DIR__.'/e10-modules/swdev/world/data/Common-Currency.json');
		foreach ($data as $currency)
		{
			$item = [
				'id' => isset($currency['code']) ? $currency['code'] : '',
				'name' => isset($currency['name']) ? $currency['name'] : '',
				'namePlural' => isset($currency['name_plural']) ? $currency['name_plural'] : '',
				'symbol' => isset($currency['symbol']) ? $currency['symbol'] : '',
				'symbolNative' => isset($currency['symbol_native']) ? $currency['symbol_native'] : '',
				'decimals' => isset($currency['decimal_digits']) ? $currency['decimal_digits'] : 2,
			];


			$apiData = ['object-class-id' => 'swdev.world.UploaderWorld', 'operation' => 'upload', 'type' => 'currency', 'data' => $item];
			$result = $this->app->runApiCall($this->devServerCfg['devServerUrl'] . '/api', $this->devServerCfg['devServerApiKey'], $apiData);
			if (!$result || !isset($result['success']) || $result['success'] !== 1)
			{
				$this->app->err("ERROR!!!");
			}
		}
	}

	function translateCurrencies()
	{
		echo "=== translate currencies ===\n";
		foreach ($this->preferredLanguages as $lid)
		{
			$data = utils::loadCfgFile(__APP_DIR__.'/e10-modules/swdev/world/data/currency-list/data/'.$lid.'/currency.json');

			$apiData = [
				'object-class-id' => 'swdev.world.UploaderWorld', 'operation' => 'upload',
				'type' => 'translateCurrencies', 'language' => $lid, 'data' => $data
			];
			$result = $this->app->runApiCall($this->devServerCfg['devServerUrl'] . '/api', $this->devServerCfg['devServerApiKey'], $apiData);
			if (!$result || !isset($result['success']) || $result['success'] !== 1)
			{
				$this->app->err("ERROR!!!");
			}
		}
	}

	function translateLanguages()
	{
		echo "=== translate languages ===\n";
		foreach ($this->preferredLanguages as $lid)
		{
			$data = utils::loadCfgFile(__APP_DIR__.'/e10-modules/swdev/world/data/language-list/data/'.$lid.'/language.json');

			$apiData = [
				'object-class-id' => 'swdev.world.UploaderWorld', 'operation' => 'upload',
				'type' => 'translateLanguages', 'language' => $lid, 'data' => $data
			];
			$result = $this->app->runApiCall($this->devServerCfg['devServerUrl'] . '/api', $this->devServerCfg['devServerApiKey'], $apiData);
			if (!$result || !isset($result['success']) || $result['success'] !== 1)
			{
				$this->app->err("ERROR!!!");
			}
		}
	}

	public function upload ()
	{
		$this->doLanguages();
		$this->doCurrencies();
		$this->doMledozeCountries();
		$this->translateCurrencies();
		$this->translateLanguages();
	}
}
