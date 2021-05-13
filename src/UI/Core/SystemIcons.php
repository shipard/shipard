<?php

namespace Shipard\UI\Core;
class SystemIcons
{
	var $iconsId;
	private ?array $data = NULL;

	const
		 actionAdd = 0
		,actionCalendar = 1
		,actionClose = 2
		,actionCopy = 3
		,actionDatabaseName = 4
		,actionHomePage = 5
		,actionLogout = 6
		,actionNotifications = 7
		,actionOpen = 8
		,actionPrint = 9
		,actionSave = 10
		,detailAccounting = 11
		,detailAnalysis = 12
		,detailBalance = 13
		,detailBalanceDueAnalysis = 14
		,detailBalanceItems = 15
		,detailBalanceRatesDifferences = 16
		,detailDetail = 17
		,detailHistory = 18
		,detailMovement = 19
		,detailNotes = 20
		,detailReport = 21
		,detailReportDifferences = 22
		,detailReportInput = 23
		,detailReportOutput = 24
		,detailReportPDP = 25
		,detailReportProblems = 26
		,detailReportSum = 27
		,detailReportSummaryReport = 28
		,detailReportTranscript = 29
		,detailRows = 30
		,detailStock = 31
		,detailUsage = 32
		,iconFile = 33
		,iconHistory = 34
		,iconOther = 35
		,iconOwner = 36
		,iconReports = 37
		,iconSettings = 38
		,iconStart = 39
		,iconToSolve = 40
		,iconUser = 41
	;


		public function systemIcon(int $i)
		{
			if (!$this->data)
			{
				$this->data = unserialize(file_get_contents(__SHPD_ROOT_DIR__ . 'ui/icons/'.$this->iconsId.'/system-icons-map.data'));
			}
	
			return $this->data[$i];
		}
		}
