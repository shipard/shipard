<?php

namespace lib\tests;

use e10\utils;

/**
 * Class UsersRights
 * @package lib\tests
 */
class UsersRights extends \lib\tests\Test
{
	var $rm;
	var $data = [];

	protected function testTables ()
	{
		$tables = $this->app->model()->model ['tables'];
		foreach ($tables as $tableId => $tableDef)
		{
			if ($tableDef['options'] & \E10\DataModel::toSystemTable)
				continue;
			if (isset ($this->rm->accessTo['tables'][$tableId]))
				continue;
			$this->data[] = [
					'subject' => [
							['text' => 'table without permissions', 'class' => 'e10-me'],
							['prefix' => 'table', 'text' => $tableId, 'class' => 'break']
					]
			];
		}
	}

	protected function testViewers ()
	{
		$tables = $this->app->model()->model ['tables'];
		foreach ($tables as $tableId => $tableDef)
		{
			if (!isset ($this->rm->accessTo['tables'][$tableId]))
				continue;

			if (isset($tableDef['views']))
			{
				foreach ($tableDef['views'] as $viewer)
				{
					$viewerId = $viewer['id'];

					if (isset ($this->rm->accessTo['tables'][$tableId]['viewers'][$viewerId]))
						continue;
					$this->data[] = [
							'subject' => [
									['text' => 'viewer without permissions', 'class' => 'e10-me'],
									['prefix' => 'table', 'text' => $tableId, 'class' => 'break'],
									['prefix' => 'viewer', 'text' => $viewerId, 'class' => '']
							]
					];
				}
			}
		}
	}

	protected function testReports ()
	{
		$allReports = $this->app->cfgItem ('reports', []);

		foreach ($allReports as $report)
		{
			$reportId = $report['class'];
			if (isset ($this->rm->accessTo['reports'][$reportId]))
				continue;
			$this->data[] = [
					'subject' => [
							['text' => 'report without permissions', 'class' => 'e10-me'],
							['prefix' => 'group', 'text' => $report['group'], 'class' => 'break'],
							['prefix' => 'report', 'text' => $reportId, 'class' => '']
					]
			];
		}

		foreach ($this->rm->accessTo['reports'] as $reportId => $reportDef)
		{
			$report = utils::searchArray($allReports, 'class', $reportId);
			if ($report === NULL)
				$this->data[] = [
						'subject' => [
								['text' => 'unknown report', 'class' => 'e10-me'],
								['prefix' => 'report', 'text' => $reportId, 'class' => 'break']
						]
				];
		}
	}

	protected function testWidgets ()
	{
		$allWidgets = $this->app->cfgItem ('widgets', []);

		foreach ($allWidgets as $widget)
		{
			$widgetId = $widget['class'];
			if (isset ($this->rm->accessTo['widgets'][$widgetId]))
				continue;
			$this->data[] = [
					'subject' => [
							['text' => 'widget without permissions', 'class' => 'e10-me'],
							['prefix' => 'widget', 'text' => $widgetId, 'class' => 'break']
					]
			];
		}

		/* TODO: temporary disabled
		foreach ($this->rm->accessTo['widgets'] as $widgetId => $widgetDef)
		{
			$widget = utils::searchArray($allWidgets, 'class', $widgetId);
			if ($widget === NULL)
				$this->app->addError ('rights', 'unknown widget', $widgetId);
		}
		*/
	}


	protected function testDocsTypes ()
	{
		$docsTypes = $this->app->cfgItem ('e10.docs.types', FALSE);
		if ($docsTypes === FALSE)
			return;
		$allRoles = $this->app->cfgItem ('e10.persons.roles');

		foreach ($docsTypes as $docTypeId => $docType)
		{
			$found = 0;
			forEach ($allRoles as $roleId => $r)
			{
				if (!isset ($r['documents']) || !isset ($r['documents']['e10doc.core.heads']))
					continue;

				foreach ($r['documents']['e10doc.core.heads'] as $rights)
				{
					foreach ($rights as $columnId => $columnValue)
					{
						if ($columnId !== 'docType')
							continue;

						if (is_array($columnValue))
						{
							if (!in_array($docTypeId, $columnValue))
								continue;
						}
						else
							if ($docTypeId != $columnValue)
								continue;
						$found = 1;
						break;
					}
				}
			}
			if (!$found)
				$this->data[] = [
						'subject' => [
								['text' => 'docType without permissions', 'class' => 'e10-me'],
								['prefix' => 'docType', 'text' => $docTypeId, 'class' => 'break']
						]
				];
		}
	}

	public function test ()
	{
		$this->rm = new \lib\RightsManager($this->app);
		$this->rm->setAll();
		$this->rm->create();

		$this->testTables();
		$this->testViewers();
		$this->testReports();
		$this->testWidgets();
		//$this->testDocsTypes();

		if (count($this->data))
		{
			$h = ['#' => '#', 'subject' => 'Zpráva'];
			$this->addContent(['type' => 'table', 'table' => $this->data, 'header' => $h, 'title' => 'Problémy s integritou přístupových práv']);
		}

		$this->addMessages('Chyby během testování práv', $this->rm->messagess());
	}
}
