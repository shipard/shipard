<?php

namespace services\subjects;

use \E10\DbTable;


/**
 * Class TableSubjectsBranches
 * @package services\subjects
 */
class TableSubjectsCounters extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.subjects.subjectsCounters', 'services_subjects_subjectsCounters', 'Statistiky subjektů');
	}
}
