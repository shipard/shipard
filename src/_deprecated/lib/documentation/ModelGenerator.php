<?php

namespace lib\Documentation;


use E10\DataModel;
use \E10\Utility, \E10\utils, \E10\TemplateCore;


class ModelGenerator extends Utility
{
	/** @var \E10\DataModel */
	var $model;

	function MinifyHTML($str) {
		$protected_parts = array('<pre>,</pre>','<textarea>,</textarea>', '<,>');
		$extracted_values = array();
		$i = 0;
		foreach ($protected_parts as $part) {
			$finished = false;
			$search_offset = $first_offset = 0;
			$end_offset = 1;
			$startend = explode(',', $part);
			if (count($startend) === 1) $startend[1] = $startend[0];
			$len0 = strlen($startend[0]); $len1 = strlen($startend[1]);
			while ($finished === false) {
				$first_offset = strpos($str, $startend[0], $search_offset);

				if ($first_offset === false) $finished = true;
				else {
					$search_offset = strpos($str, $startend[1], $first_offset + $len0);
					$extracted_values[$i] = substr($str, $first_offset + $len0, $search_offset - $first_offset - $len0);
					$str = substr($str, 0, $first_offset + $len0).'$$#'.$i.'$$'.substr($str, $search_offset);
					$search_offset += $len1 + strlen((string)$i) + 5 - strlen($extracted_values[$i]);
					++$i;
				}
			}
		}
		$str = preg_replace("/\s/", " ", $str);
		$str = preg_replace("/\s{2,}/", " ", $str);
		$replace = array('> <'=>'><', ' >'=>'>','< '=>'<','</ '=>'</');
		$str = str_replace(array_keys($replace), array_values($replace), $str);

		for ($d = 0; $d < $i; ++$d)
			$str = str_replace('$$#'.$d.'$$', $extracted_values[$d], $str);

		return $str;
	}

	public function generateTables ()
	{
		foreach ($this->model->model ['tables'] as $tableId => $tableDef)
		{
			$this->generateTable($tableId, $tableDef);
		}
	}

	public function generateTable ($tableId, $tableDef)
	{
		$table = $this->app->table($tableId);
		if (!$table)
			return;
		$tableDefDecorated = $this->decoratedTableDef ($table, $tableId, $tableDef);
		$this->saveFile('chunks', 'table', $tableId, $tableDefDecorated, 'json');

		$c = $this->render('table', $tableDefDecorated);
		$this->saveFile('chunks', 'table', $tableId, $c, 'html');
	}

	public function decoratedTableDef ($table, $tableId, $tableDef)
	{
		$t = $tableDef;
		$t['id'] = $tableId;
		$t['columns'] = [];
		foreach ($tableDef['cols'] as $colId => $colDef)
		{
			$newCol = $colDef;
			$newCol['id'] = $colId;
			$this->decoratedTableDef_columnType ($table, $colId, $newCol);
			$t['columns'][] = $newCol;
		}

		return $t;
	}

	public function decoratedTableDef_columnType ($table, $columnId, &$colDef)
	{
		switch ($colDef['type'])
		{
			case  DataModel::ctString:
							$colDef['typeName'] = 'string [' . $colDef['len'] . ']';
							break;
			case  DataModel::ctInt:
							$colDef['typeName'] = 'int';
							break;
			case  DataModel::ctShort:
							$colDef['typeName'] = 'short';
							break;
			case  DataModel::ctDate:
							$colDef['typeName'] = 'date';
							break;
			case  DataModel::ctMoney:
							$colDef['typeName'] = 'money';
							break;
			case  DataModel::ctTimeStamp:
							$colDef['typeName'] = 'timestamp';
							break;
			case  DataModel::ctMemo:
							$colDef['typeName'] = 'memo';
							break;
			case  DataModel::ctEnumInt:
							$colDef['typeName'] = 'enum/int';
							if (isset ($colDef['len']) && $colDef['len'] == 4)
								$colDef['typeName'] .= '32';
							else
								$colDef['typeName'] .= '8';
							break;
			case  DataModel::ctEnumString:
							$colDef['typeName'] = 'enum/string ['.$colDef['len'] . ']';
							break;
			case  DataModel::ctLogical:
							$colDef['typeName'] = 'boolean';
							break;
			case  DataModel::ctNumber:
							$colDef['typeName'] = 'number.' . (isset ($colDef['dec']) ? $colDef['dec'] : '0');
							break;
			case  DataModel::ctLong:
							$colDef['typeName'] = 'long/int64';
							break;
			case  DataModel::ctCode:
							$colDef['typeName'] = 'memo/code';
							break;
			case  DataModel::ctTime:
							$colDef['typeName'] = 'time/string [5]';
							break;
		}

		if (isset ($colDef['reference']))
		{
			$refTableDef = $this->model->table($colDef['reference']);
			if ($refTableDef)
			{
				//echo json_encode($refTableDef, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\n";

				$colDef['columnReference'] = ['text' => $refTableDef['name'], 'icon' => 'icon-chevron-right', 'url-disabled' => 'table.'.$colDef['reference']];
			}
		}

		if ($colDef['type'] === DataModel::ctEnumInt || $colDef['type'] === DataModel::ctEnumString)
		{
			if ($table)
			{
				$enum = $table->columnInfoEnum($columnId);
				if ($enum)
				{
					//echo json_encode($refTableDef, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\n";
					forEach ($enum as $enumId => $enumText)
					{
						$colDef['columnEnum'][] = ['id' => $enumId, 'text' => $enumText];
						if (is_array($enumText))
							echo $table->tableId()." -- $columnId --:".json_encode($enumText)."\n";
					}
				}
			}
		}
	}

	public function template ($type, $data)
	{
		$t = new TemplateCore($this->app);
		$t->loadTemplate('lib.documentation.templates', $type.'.mustache');
		$t->data[$type] = $data;

		return $t;
	}

	public function render ($type, $data)
	{
		$t = $this->template($type, $data);
		return $t->renderTemplate();
	}

	public function saveFile ($subFolder, $entityType, $name, $data, $format)
	{
		$coreDir = __APP_DIR__.'/includes/documentation/model/'.$subFolder;
		if (is_dir($coreDir) === FALSE)
			mkdir ($coreDir, 0755, TRUE);


		$fileName = $coreDir.'/'.$entityType.'.'.$name.'.'.$format;
		if ($format === 'html')
			file_put_contents($fileName, $this->MinifyHTML($data));
		else
		if ($format === 'json')
			file_put_contents($fileName, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\n");
	}

	public function run ()
	{
		$this->model = $this->app->model ();
		$this->generateTables();
	}
}
