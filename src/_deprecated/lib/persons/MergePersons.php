<?php

namespace lib\persons;
use E10\Utility;


/**
 * Class MergePersons
 * @package lib\persons
 */
class MergePersons extends Utility
{
	var $mergeTargetNdx = 0;
	var $mergedNdxs = [];
	var $deleteMergedItems = 0;
	var $tablePersons;

	public function setMergeParams ($mergeTargetNdx, $mergedNdxs, $deleteMergedItems)
	{
		$this->mergeTargetNdx = $mergeTargetNdx;
		$this->mergedNdxs = $mergedNdxs;
		$this->deleteMergedItems = $deleteMergedItems;

		$this->tablePersons = $this->app->table ('e10.persons.persons');
	}

	public function merge ()
	{
		$this->doit ();
	}

	protected function doit ()
	{
		// -- all referenced columns
		foreach ($this->app->model()->tables() as $tableDef)
		{
			foreach ($tableDef['cols'] as $columnId => $columnDef)
			{
				if (!isset($columnDef['reference']) || $columnDef['reference'] !== 'e10.persons.persons')
					continue;

				$sql = 'UPDATE ['.$tableDef['sql'].'] SET ['.$columnDef['sql'].'] = %i WHERE ['.$columnDef['sql'].'] IN %in';
				$this->db()->query ($sql, $this->mergeTargetNdx, $this->mergedNdxs);
			}
		}

		// -- docLinks
		$sql = 'UPDATE [e10_base_doclinks] SET [srcRecId] = %i WHERE [srcTableId] = %s  AND [srcRecId] IN %in';
		$this->db()->query ($sql, $this->mergeTargetNdx, 'e10.persons.persons', $this->mergedNdxs);

		$sql = 'UPDATE [e10_base_doclinks] SET [dstRecId] = %i WHERE [dstTableId] = %s AND [dstRecId] IN %in';
		$this->db()->query ($sql, $this->mergeTargetNdx, 'e10.persons.persons', $this->mergedNdxs);

		if ($this->deleteMergedItems)
			$this->deleteMergedItems ();
	}

	protected function deleteMergedItems ()
	{
		forEach ($this->mergedNdxs as $deletedNdx)
		{
			$this->db()->query ('UPDATE [e10_persons_persons] SET [docState] = 9800, [docStateMain] = 4 WHERE [ndx] = %i', $deletedNdx);
			$this->tablePersons->docsLog ($deletedNdx);
		}
	}
}
