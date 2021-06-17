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
		,actionRecycle = 24
		,actionRegenerate = 25
		,actionSave = 26
		,actionSettings = 27
		,actionStop = 28
		,actionSupport = 29
		,actionUpload = 30
		,actionWizardDone = 31
		,actionWizardNext = 32
		,brandsGoogle = 33
		,dashboardDashboard = 34
		,dashboardModeRows = 35
		,dashboardModeTilesBig = 36
		,dashboardModeTilesSmall = 37
		,dashboardModeViewer = 38
		,detailAccounting = 39
		,detailAnalysis = 40
		,detailBalance = 41
		,detailCalculate = 42
		,detailDetail = 43
		,detailHistory = 44
		,detailInfo = 45
		,detailLinks = 46
		,detailMovement = 47
		,detailNotes = 48
		,detailOverview = 49
		,detailRecipients = 50
		,detailReport = 51
		,detailReservations = 52
		,detailRows = 53
		,detailSettings = 54
		,detailSources = 55
		,detailStock = 56
		,detailSubjects = 57
		,detailUsage = 58
		,docStateArchive = 59
		,docStateCancel = 60
		,docStateConcept = 61
		,docStateConfirmed = 62
		,docStateDelete = 63
		,docStateDone = 64
		,docStateEdit = 65
		,docStateHalfDone = 66
		,docStateNew = 67
		,docStateUnknown = 68
		,filterActive = 69
		,filterAll = 70
		,filterArchive = 71
		,filterDone = 72
		,filterOverview = 73
		,filterTrash = 74
		,formAccounting = 75
		,formAttachments = 76
		,formFilter = 77
		,formHeader = 78
		,formHistory = 79
		,formNote = 80
		,formNotes = 81
		,formRows = 82
		,formSettings = 83
		,formSorting = 84
		,iconAdmin = 85
		,iconAngleRight = 86
		,iconAppInfoMenu = 87
		,iconBalance = 88
		,iconBook = 89
		,iconBug = 90
		,iconCalendar = 91
		,iconCamera = 92
		,iconCheck = 93
		,iconCheckSquare = 94
		,iconCogs = 95
		,iconCutlery = 96
		,iconDatabase = 97
		,iconDelivery = 98
		,iconEmail = 99
		,iconFile = 100
		,iconFilePdf = 101
		,iconHamburgerMenu = 102
		,iconHelp = 103
		,iconHistory = 104
		,iconHome = 105
		,iconIdBadge = 106
		,iconImage = 107
		,iconImport = 108
		,iconInbox = 109
		,iconKeyboard = 110
		,iconLaboratory = 111
		,iconLink = 112
		,iconList = 113
		,iconLocalServer = 114
		,iconLocked = 115
		,iconMapMarker = 116
		,iconOrder = 117
		,iconOther = 118
		,iconOwner = 119
		,iconPaperPlane = 120
		,iconPencil = 121
		,iconPhone = 122
		,iconPhoto = 123
		,iconPinned = 124
		,iconPreview = 125
		,iconReaders = 126
		,iconReports = 127
		,iconSearch = 128
		,iconSettings = 129
		,iconSitemap = 130
		,iconSpinner = 131
		,iconStar = 132
		,iconStart = 133
		,iconTerminal = 134
		,iconUser = 135
		,iconVideo = 136
		,iconViewerEnd = 137
		,iconWarning = 138
		,iconWorkplace = 139
		,issueAdvertisementSPAM = 140
		,issueAlert = 141
		,issueBoardNote = 142
		,issueCall = 143
		,issueComment = 144
		,issueConcept = 145
		,issueDiscussion = 146
		,issueImportant = 147
		,issueMeeting = 148
		,issueNotImportant = 149
		,issueNote = 150
		,issueReceivedMail = 151
		,issueSelected = 152
		,issueSentMail = 153
		,issueStandart = 154
		,issueSystemControl = 155
		,issueTask = 156
		,issueToDo = 157
		,issueUnread = 158
		,leftSubmenuBulkMail = 159
		,personCompany = 160
		,personHuman = 161
		,personRobot = 162
		,rightSubmenuAccess = 163
		,rightSubmenuCalendar = 164
		,rightSubmenuCanteen = 165
		,rightSubmenuCompartments = 166
		,rightSubmenuDocuments = 167
		,rightSubmenuMeters = 168
		,rightSubmenuNews = 169
		,rightSubmenuNoticeBoard = 170
		,rightSubmenuReceivables = 171
		,rightSubmenuReservations = 172
		,rightSubmenuSupport = 173
		,rightSubmenuToDo = 174
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
