<?php

namespace imports\erps\pohoda;

class ModuleServices extends \E10\CLI\ModuleServices
{
	public function importXMLDocs()
	{
    $fileName = $this->app()->arg('file');
    if (!$fileName)
    {
      echo "### ERROR: missing argument `file`\n";
      return;
    }

		$ie = new \imports\erps\pohoda\libs\ImportPohodaDocs($this->app());
    $ie->importFiles($fileName);
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'import-xml-docs': return $this->importXMLDocs();
		}

		parent::onCliAction($actionId);
	}
}
