<?php

namespace e10doc\slr\dc;

use \Shipard\Utils\Json;


/**
 * class DCImport
 */
class DCImport extends \Shipard\Base\DocumentCard
{
	protected function addErrors()
	{
		$e = new \e10doc\slr\libs\ImportEngine($this->app());
		//$e->init();
		$e->setImportNdx($this->recData['ndx']);
		$e->run();

		if ($e->messages() !== FALSE)
		{
			$code = $e->errorsHtml();

			$this->addContent('body',  [
				'pane' => 'e10-pane e10-pane-table', 'type' => 'line',
				'paneTitle' => ['text' => 'Chyby při zpracování', 'class' => 'h2 e10-error title', 'icon' => 'system/iconWarning'],
				'line' => ['code' => $code],
			]);
		}
	}


	public function createContentBody ()
	{
		$this->addErrors();
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
