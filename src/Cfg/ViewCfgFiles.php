<?php
// TODO: remove file?

namespace Shipard\Cfg;


class ViewCfgFiles extends \Shipard\Viewer\TableView
{
	public function createToolbar ()
	{
		return array ();
	} // createToolbar

	public function createDetails ()
	{
		return array ();
	}

	public function selectRows ()
	{
		$this->queryRows = array ();

		$cfgString = file_get_contents (__APP_DIR__ . '/config/curr/cfgfiles.data');
		$cfgFiles = unserialize ($cfgString);
		forEach ($cfgFiles as $c)
		{
			$fullFileName = $c ['path'] . $c ['fileName'];
			$writable = is_writable ($fullFileName);
			$relPath = getRelativePath (__APP_DIR__ , $fullFileName);
			if (substr($relPath, 0, 7) == 'config/')
				$this->queryRows [] = array ("ndx" => $fullFileName, "fileName" => $c['fileName'], "path" => $c['path'], 'relPath' => $relPath, 'writable' => $writable);
		}
		$this->ok = 1;
	} // selectRows

	public function renderRow ($item)
	{
		$listItem ['pk'] = str_replace ('/', '!', $item['path'] . $item['fileName']);
		$listItem ['t1'] = $item ['fileName'];
		$listItem ['t2'] = $item ['relPath']; //getRelativePath (__APP_DIR__ , $item['path'] . $item['fileName']);
		if (!$item ['writable'])
			$listItem ['i2'] = 'Uzamčeno';

		return $listItem;
	}
}
