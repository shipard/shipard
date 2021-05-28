<?php

namespace Shipard\UI\Core;
class SystemIcons
{
	var $iconsId;
	private ?array $data = NULL;

	const
		 actionAdd = 0
		,actionAddWizard = 1
		,actionCalendar = 2
		,actionClose = 3
		,actionCopy = 4
		,actionDatabaseName = 5
		,actionDelete = 6
		,actionExpandClose = 7
		,actionExpandOpen = 8
		,actionHomePage = 9
		,actionLogout = 10
		,actionMoveDown = 11
		,actionMoveUp = 12
		,actionNotifications = 13
		,actionOpen = 14
		,actionPrint = 15
		,actionRegenerate = 16
		,actionSave = 17
		,actionSupport = 18
		,dashboardModeRows = 19
		,dashboardModeTilesBig = 20
		,dashboardModeTilesSmall = 21
		,dashboardModeViewer = 22
		,detailAccounting = 23
		,detailAnalysis = 24
		,detailBalance = 25
		,detailDetail = 26
		,detailHistory = 27
		,detailInfo = 28
		,detailLinks = 29
		,detailMovement = 30
		,detailNotes = 31
		,detailOverview = 32
		,detailReport = 33
		,detailRows = 34
		,detailSettings = 35
		,detailStock = 36
		,detailUsage = 37
		,docStateArchive = 38
		,docStateCancel = 39
		,docStateConcept = 40
		,docStateConfirmed = 41
		,docStateDelete = 42
		,docStateDone = 43
		,docStateEdit = 44
		,docStateHalfDone = 45
		,docStateNew = 46
		,docStateUnknown = 47
		,filterActive = 48
		,filterAll = 49
		,filterArchive = 50
		,filterDone = 51
		,filterOverview = 52
		,filterTrash = 53
		,formAccounting = 54
		,formAttachments = 55
		,formFilter = 56
		,formHeader = 57
		,formHistory = 58
		,formNote = 59
		,formNotes = 60
		,formRows = 61
		,formSettings = 62
		,formSorting = 63
		,iconAppInfoMenu = 64
		,iconBalance = 65
		,iconFile = 66
		,iconFilePdf = 67
		,iconHelp = 68
		,iconHistory = 69
		,iconImage = 70
		,iconLocked = 71
		,iconOther = 72
		,iconOwner = 73
		,iconPinned = 74
		,iconPreview = 75
		,iconReports = 76
		,iconSearch = 77
		,iconSettings = 78
		,iconStart = 79
		,iconTerminal = 80
		,iconUser = 81
		,iconVideo = 82
		,iconViewerEnd = 83
		,issueAdvertisementSPAM = 84
		,issueAlert = 85
		,issueBoardNote = 86
		,issueCall = 87
		,issueComment = 88
		,issueDiscussion = 89
		,issueMeeting = 90
		,issueNote = 91
		,issueReceivedMail = 92
		,issueSentMail = 93
		,issueSystemControl = 94
		,issueTask = 95
		,issueToDo = 96
		,leftSubmenuBulkMail = 97
		,personCompany = 98
		,personHuman = 99
		,personRobot = 100
		,rightSubmenuAccess = 101
		,rightSubmenuCanteen = 102
		,rightSubmenuCompartments = 103
		,rightSubmenuDocuments = 104
		,rightSubmenuMeters = 105
		,rightSubmenuNews = 106
		,rightSubmenuReceivables = 107
		,rightSubmenuReservations = 108
		,rightSubmenuSupport = 109
		,rightSubmenuToDo = 110
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
