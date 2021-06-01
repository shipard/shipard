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
		,actionInputClear = 10
		,actionInputMinus = 11
		,actionInputPlus = 12
		,actionInputSearch = 13
		,actionLogout = 14
		,actionMoveDown = 15
		,actionMoveUp = 16
		,actionNotifications = 17
		,actionOpen = 18
		,actionPrint = 19
		,actionRegenerate = 20
		,actionSave = 21
		,actionSupport = 22
		,dashboardModeRows = 23
		,dashboardModeTilesBig = 24
		,dashboardModeTilesSmall = 25
		,dashboardModeViewer = 26
		,detailAccounting = 27
		,detailAnalysis = 28
		,detailBalance = 29
		,detailCalculate = 30
		,detailDetail = 31
		,detailHistory = 32
		,detailInfo = 33
		,detailLinks = 34
		,detailMovement = 35
		,detailNotes = 36
		,detailOverview = 37
		,detailRecipients = 38
		,detailReport = 39
		,detailReservations = 40
		,detailRows = 41
		,detailSettings = 42
		,detailStock = 43
		,detailUsage = 44
		,docStateArchive = 45
		,docStateCancel = 46
		,docStateConcept = 47
		,docStateConfirmed = 48
		,docStateDelete = 49
		,docStateDone = 50
		,docStateEdit = 51
		,docStateHalfDone = 52
		,docStateNew = 53
		,docStateUnknown = 54
		,filterActive = 55
		,filterAll = 56
		,filterArchive = 57
		,filterDone = 58
		,filterOverview = 59
		,filterTrash = 60
		,formAccounting = 61
		,formAttachments = 62
		,formFilter = 63
		,formHeader = 64
		,formHistory = 65
		,formNote = 66
		,formNotes = 67
		,formRows = 68
		,formSettings = 69
		,formSorting = 70
		,iconAdmin = 71
		,iconAppInfoMenu = 72
		,iconBalance = 73
		,iconDatabase = 74
		,iconFile = 75
		,iconFilePdf = 76
		,iconHelp = 77
		,iconHistory = 78
		,iconImage = 79
		,iconImport = 80
		,iconLaboratory = 81
		,iconLocalServer = 82
		,iconLocked = 83
		,iconOther = 84
		,iconOwner = 85
		,iconPinned = 86
		,iconPreview = 87
		,iconReports = 88
		,iconSearch = 89
		,iconSettings = 90
		,iconSpinner = 91
		,iconStart = 92
		,iconTerminal = 93
		,iconUser = 94
		,iconVideo = 95
		,iconViewerEnd = 96
		,iconWorkplace = 97
		,issueAdvertisementSPAM = 98
		,issueAlert = 99
		,issueBoardNote = 100
		,issueCall = 101
		,issueComment = 102
		,issueDiscussion = 103
		,issueMeeting = 104
		,issueNote = 105
		,issueReceivedMail = 106
		,issueSentMail = 107
		,issueSystemControl = 108
		,issueTask = 109
		,issueToDo = 110
		,leftSubmenuBulkMail = 111
		,personCompany = 112
		,personHuman = 113
		,personRobot = 114
		,rightSubmenuAccess = 115
		,rightSubmenuCanteen = 116
		,rightSubmenuCompartments = 117
		,rightSubmenuDocuments = 118
		,rightSubmenuMeters = 119
		,rightSubmenuNews = 120
		,rightSubmenuReceivables = 121
		,rightSubmenuReservations = 122
		,rightSubmenuSupport = 123
		,rightSubmenuToDo = 124
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
