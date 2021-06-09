<?php

namespace Shipard\UI\Core;
class SystemIcons
{
	var $iconsId;
	private ?array $data = NULL;

	const
		 actionAdd = 0
		,actionAddWizard = 1
		,actionBack = 2
		,actionCalendar = 3
		,actionClose = 4
		,actionCopy = 5
		,actionDatabaseName = 6
		,actionDelete = 7
		,actionDownload = 8
		,actionExpandClose = 9
		,actionExpandOpen = 10
		,actionHomePage = 11
		,actionInputClear = 12
		,actionInputMinus = 13
		,actionInputPlus = 14
		,actionInputSearch = 15
		,actionLogIn = 16
		,actionLogout = 17
		,actionMoveDown = 18
		,actionMoveUp = 19
		,actionNotifications = 20
		,actionOpen = 21
		,actionPlay = 22
		,actionPrint = 23
		,actionRegenerate = 24
		,actionSave = 25
		,actionStop = 26
		,actionSupport = 27
		,actionUpload = 28
		,actionWizardDone = 29
		,actionWizardNext = 30
		,brandsGoogle = 31
		,dashboardDashboard = 32
		,dashboardModeRows = 33
		,dashboardModeTilesBig = 34
		,dashboardModeTilesSmall = 35
		,dashboardModeViewer = 36
		,detailAccounting = 37
		,detailAnalysis = 38
		,detailBalance = 39
		,detailCalculate = 40
		,detailDetail = 41
		,detailHistory = 42
		,detailInfo = 43
		,detailLinks = 44
		,detailMovement = 45
		,detailNotes = 46
		,detailOverview = 47
		,detailRecipients = 48
		,detailReport = 49
		,detailReservations = 50
		,detailRows = 51
		,detailSettings = 52
		,detailSources = 53
		,detailStock = 54
		,detailSubjects = 55
		,detailUsage = 56
		,docStateArchive = 57
		,docStateCancel = 58
		,docStateConcept = 59
		,docStateConfirmed = 60
		,docStateDelete = 61
		,docStateDone = 62
		,docStateEdit = 63
		,docStateHalfDone = 64
		,docStateNew = 65
		,docStateUnknown = 66
		,filterActive = 67
		,filterAll = 68
		,filterArchive = 69
		,filterDone = 70
		,filterOverview = 71
		,filterTrash = 72
		,formAccounting = 73
		,formAttachments = 74
		,formFilter = 75
		,formHeader = 76
		,formHistory = 77
		,formNote = 78
		,formNotes = 79
		,formRows = 80
		,formSettings = 81
		,formSorting = 82
		,iconAdmin = 83
		,iconAppInfoMenu = 84
		,iconBalance = 85
		,iconBook = 86
		,iconBug = 87
		,iconCalendar = 88
		,iconCamera = 89
		,iconCheck = 90
		,iconCogs = 91
		,iconDatabase = 92
		,iconEmail = 93
		,iconFile = 94
		,iconFilePdf = 95
		,iconHamburgerMenu = 96
		,iconHelp = 97
		,iconHistory = 98
		,iconImage = 99
		,iconImport = 100
		,iconInbox = 101
		,iconKeyboard = 102
		,iconLaboratory = 103
		,iconLocalServer = 104
		,iconLocked = 105
		,iconMapMarker = 106
		,iconOrder = 107
		,iconOther = 108
		,iconOwner = 109
		,iconPhone = 110
		,iconPhoto = 111
		,iconPinned = 112
		,iconPreview = 113
		,iconReaders = 114
		,iconReports = 115
		,iconSearch = 116
		,iconSettings = 117
		,iconSitemap = 118
		,iconSpinner = 119
		,iconStart = 120
		,iconTerminal = 121
		,iconUser = 122
		,iconVideo = 123
		,iconViewerEnd = 124
		,iconWarning = 125
		,iconWorkplace = 126
		,issueAdvertisementSPAM = 127
		,issueAlert = 128
		,issueBoardNote = 129
		,issueCall = 130
		,issueComment = 131
		,issueConcept = 132
		,issueDiscussion = 133
		,issueMeeting = 134
		,issueNote = 135
		,issueReceivedMail = 136
		,issueSelected = 137
		,issueSentMail = 138
		,issueStandart = 139
		,issueSystemControl = 140
		,issueTask = 141
		,issueToDo = 142
		,issueUnread = 143
		,leftSubmenuBulkMail = 144
		,personCompany = 145
		,personHuman = 146
		,personRobot = 147
		,rightSubmenuAccess = 148
		,rightSubmenuCalendar = 149
		,rightSubmenuCanteen = 150
		,rightSubmenuCompartments = 151
		,rightSubmenuDocuments = 152
		,rightSubmenuMeters = 153
		,rightSubmenuNews = 154
		,rightSubmenuNoticeBoard = 155
		,rightSubmenuReceivables = 156
		,rightSubmenuReservations = 157
		,rightSubmenuSupport = 158
		,rightSubmenuToDo = 159
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
