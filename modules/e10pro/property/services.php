<?php

namespace E10Pro\Property;


class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$s [] = array ('version' => 61, 'sql' => "update e10pro_property_property set docState = 4000, docStateMain = 2 where docState = 0");

		$ownerNdx = intval($this->app->cfgItem ('options.core.ownerPerson', 0));
		$s [] = array ('version' => 0, 'sql' => "update e10pro_property_property as p set p.owner = $ownerNdx where p.foreign = 0 AND p.owner = 0");

		$this->doSqlScripts ($s);

		$dbcNdx = $this->checkDocKinds_DbCounter();
		if ($dbcNdx)
		{
			$activites = $this->app->cfgItem ('e10.docs.types.cmnbkp.activities');
			foreach ($activites as $activityId => $a)
			{
				if (substr($activityId, 0, 3) !== 'prp')
					continue;
				$this->checkDocKinds_DocKind($activityId, $a['name']);
			}
		}
	}

	function checkDocKinds_DbCounter()
	{
		$wrongDbCounter = $this->app->db()->query ('SELECT * FROM [e10doc_base_docnumbers] WHERE fullName = %s', 'Majetek',
			' AND [activitiesGroup] = %s', '', ' AND docState = %i', 4000)->fetch();

		if (!$wrongDbCounter)
		{
			$goodDbCounter = $this->app->db()->query ('SELECT * FROM [e10doc_base_docnumbers] WHERE fullName = %s', 'Majetek',
				' AND [activitiesGroup] = %s', 'prp', ' AND docState = %i', 4000)->fetch();
			if ($goodDbCounter)
				return $goodDbCounter['ndx'];
			return 0;
		}

		$update = ['activitiesGroup' => 'prp', 'useDocKinds' => 2];
		$this->app->db()->query ('UPDATE [e10doc_base_docnumbers] SET ', $update, ' WHERE ndx = %i', $wrongDbCounter['ndx']);
		return $wrongDbCounter['ndx'];
	}

	function checkDocKinds_DocKind($activity, $name)
	{
		$exist = $this->app->db()->query ('SELECT * FROM [e10doc_base_dockinds] WHERE [docType] = %s', 'cmnbkp',
			' AND [activity] = %s', $activity)->fetch();
		if ($exist)
			return;

		$newItem = [
			'fullName' => $name, 'shortName' => $name,
			'docType' => 'cmnbkp', 'activity' => $activity,
			'docState' => 4000, 'docStateMain' => 2
		];
		$this->app->db()->query ('INSERT INTO [e10doc_base_dockinds] ', $newItem);
	}
}
