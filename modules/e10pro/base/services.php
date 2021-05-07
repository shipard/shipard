<?php

namespace E10Pro\Base;


class ModuleServices extends \E10\CLI\ModuleServices
{
	public function checkCoreOptions ()
	{
		if (isset($this->initConfig ['owner']))
		{
			$o ['ownerFullName'] = $this->initConfig ['owner']['recData']['fullName'];
			$o ['ownerShortName'] = $this->initConfig ['owner']['recData']['fullName'];

			if (isset ($this->initConfig ['owner']['xf']['contacts'][0]))
				$o ['ownerEmail'] = $this->initConfig ['owner']['xf']['contacts'][0]['value'];

			$o ['ownerPerson'] = 2;
		}
		else
		{
			$o ['ownerFullName'] = 'Test s.r.o.';
			$o ['ownerShortName'] = 'Test';
		}

		file_put_contents (__APP_DIR__ . '/config/appOptions.core.json', json_encode ($o));
	}

	public function onCreateDataSource ()
	{
		$this->checkCoreOptions();
		return TRUE;
	}
}
