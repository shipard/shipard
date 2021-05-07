<?php
// TODO: remove file?


namespace Shipard\Cfg;


class ViewDetailCfgFiles extends TableViewDetail
{
	public function createHeaderCode ()
	{
		$item = $this->item;
		$info = '';
		if (!$this->item ['writable'])
			$info .= 'Soubor je uzamčen';
		return $this->defaultHedearCode ("e10-server-config", basename($item ['fileName']), $info);
	}

	public function createDetailContent ()
	{
		$this->addContent(array ('type' => 'text', 'subtype' => 'code', 'text' => $this->item['text']));
	}

	public function createToolbar ()
	{
		$toolbar = array ();

		if ($this->item ['writable'])
			$toolbar [] = array ('type' => 'action', 'action' => 'editform', 'text' => 'Opravit!');
		return $toolbar;
	}
}
