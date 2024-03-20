
//const g_isMobile = window.matchMedia("(any-pointer:coarse)").matches;


class ShipardClient {
	server = new ShipardServer();
	mqtt = new ShipardMqtt();
	iot = new ShipardClientIoT();

	appVersion = '2.0.1';
	CLICK_EVENT = 'click';
	g_formId = 1;
	openModals = [];
	progressCount = 0;
	viewerScroll = 0;
	disableKeyDown = 0;

	userInfo = null;

	numPad = null;

	mainAppContent = null;

	counter = 1;

	on(eventType, selector, callback) {
		document.addEventListener(eventType, function (event) {
			var ce = event.target.closest(selector);
			if (ce) {

				callback.call(ce, ce);
			}
		});
	}

	onClick(selector, callback) {this.on('click', selector, callback)};



	/**
	 * simple tabs
	 */
	simpleTabsEvent(e)
	{
		console.log('tabs...');
		let tabsId = e.getAttribute('data-tabs');
		let tabsElement = document.getElementById(tabsId+'-tabs');
		let oldActiveTabElement = tabsElement.querySelector('.active');
		oldActiveTabElement.classList.remove('active');

		let oldActiveContentId = oldActiveTabElement.getAttribute('data-tab-id');
		let oldActiveContentElement = document.getElementById(oldActiveContentId);
		oldActiveContentElement.classList.add('d-none');

		e.classList.add('active');
		let newActiveContentId = e.getAttribute('data-tab-id');
		let newActiveContentElement = document.getElementById(newActiveContentId);
		newActiveContentElement.classList.remove('d-none');
	}


	/**
	 * upload files & camera support
	 */
	searchParentAttr (e, attr) {
		var p = e;
		while (p.length) {
			var attrValue = p.attr(attr);
			if (p.attr(attr))
				return p.attr(attr);

			p = p.parent();
			if (!p.length)
				break;
		}
		return null;
	};

	searchObjectAttr (e, attr) {
		var p = e;
		while (p.length) {
			if (p.attr(attr))
				return p;

			p = p.parent();
			if (!p.length)
				break;
		}

		return null;
	};

  widgetAction(e)
  {
    let actionId = e.getAttribute('data-action');
    this.doAction(actionId, e);
  }

  doAction (actionId, e)
  {
		switch (actionId)
    {
      case 'setColorMode': return this.setColorMode(e);
			case 'setUserContext': return this.setUserContext(e);
			case 'workplaceLogin': return this.workplaceLogin(e);
			case 'inline-action': return this.inlineAction(e);
    }

		console.log("APP-ACTION", actionId);

    return 0;
  }

	inlineAction (e)
  {
    if (e.getAttribute('data-object-class-id') === null)
		  return;

	  var requestParams = {};
	  requestParams['object-class-id'] = e.getAttribute('data-object-class-id');
	  requestParams['action-type'] = e.getAttribute('data-action-type');
	  this.elementPrefixedAttributes (e, 'data-action-param-', requestParams);
	  if (e.getAttribute('data-pk') !== null)
		  requestParams['pk'] = e.getAttribute('data-pk');

		shc.server.api(requestParams, function(data) {
			/*
			if (data.reloadNotifications === 1)
				e10NCReset();

			if (e.parent().hasClass('btn-group'))
			{
				e.parent().find('>button.active').removeClass('active');
				e.addClass('active');
			}
			*/
	  }.bind(this));
  }

	workplaceLogin(e)
	{
		console.log('workplaceLogin', e.getAttribute('data-login'));

		this.getNumber (
			{
				title: 'Zadejte přístupový kód',
				srcElement: e,
				userLogin: e.getAttribute('data-login'),
				success: function() {this.workplaceLoginDoIt()}.bind(this)
			}
		);

		return 0;
	}

	workplaceLoginDoIt(e)
	{
		console.log(this.numPad.options.userLogin);

		console.log("__DO_IT__", this.numPad.options.srcElement.getAttribute('data-login'), this.numPad.gnValue);

		document.getElementById('e10-login-user').value =  this.numPad.options.userLogin;//this.numPad.options.srcElement.getAttribute('data-login');
		document.getElementById('e10-login-pin').value = this.numPad.gnValue;
		document.forms['e10-mui-login-form'].submit();
	}

  getNumber (options) {
    const template = document.createElement('div');
    template.id = 'widget_123';
    template.classList.add('fullScreenModal');
    document.body.appendChild(template);

    var abc = new ShipardTouchNumPad();
    abc.options = options;
    abc.init(template);

    this.numPad = abc;
  }

	setColorMode(event)
	{
		let colorMode = event.target.value;
		localStorage.setItem('shpAppThemeVariant', colorMode);
		this.doColorMode(colorMode);
		return 0;
	}

	setUserContext(e)
	{

		let userContextId = e.getAttribute('data-user-context');
		console.log("User context: ", userContextId);

		let apiParams = {
			'userContextId': userContextId,

		};
		this.apiCall('setUserContext', apiParams);

		return 0;
	}

	initColorMode(firstCall)
	{
		//console.log("=== initColorMode ===", firstCall);
		if (firstCall)
		{
			window.matchMedia('(prefers-color-scheme: dark)')
				.addEventListener('change', function() {this.initColorMode()}.bind(this));
		}

		var colorMode = localStorage.getItem('shpAppThemeVariant');
		const themeVariant = uiThemesVariants[colorMode];
		//console.log("loadedColorMode: ", colorMode);
		if (!themeVariant || !colorMode || colorMode === 'auto')
		{
			const isSystemDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
			if (isSystemDarkMode)
				colorMode = 'systemDefaultDark';
			else
				colorMode = 'systemDefaultLight';
		}

		this.doColorMode(colorMode);
	}

	doColorMode(colorMode)
	{
		if (colorMode === 'auto')
		{
			this.initColorMode();
			return;
		}

		const themeVariant = uiThemesVariants[colorMode];
		if (!themeVariant)
		{
			this.initColorMode();
			return;
		}

		//console.log("themeVariant: ", themeVariant);
		var linkElement = document.getElementById('themeVariant');
		if (!linkElement)
		{
			linkElement = document.createElement('link');
			linkElement.href = httpDSRootPath + themeVariant.file+'?v='+themeVariant.integrity.sha384;
			linkElement.type = 'text/css';
			linkElement.rel = 'stylesheet';
			linkElement.id = 'themeVariant';
			document.getElementsByTagName('head')[0].appendChild(linkElement);
		}
		else
		{
			linkElement.href = httpDSRootPath + themeVariant.file+'?v='+themeVariant.integrity.sha384;
		}

		document.body.setAttribute('data-shp-theme-variant', colorMode);
		document.body.setAttribute('data-shp-dark-mode', themeVariant.dm);
	}

	setThemeVariantInput()
	{
		var inputElement = document.getElementById('input-shp-theme-variant');
		if (!inputElement)
			return;

		let themeVariant = localStorage.getItem('shpAppThemeVariant');
		if (!themeVariant)
			themeVariant = 'auto';

		inputElement.value = themeVariant;
	}

  elementPrefixedAttributes (iel, prefix, data)
  {
    for (var i = 0, attrs = iel.attributes, l = attrs.length; i < l; i++)
    {
      var attrName = attrs.item(i).nodeName;
      if (attrName.substring(0, prefix.length) !== prefix)
        continue;
      var attrNameShort = attrName.substring(prefix.length);
      var val = attrs.item(i).nodeValue;
      data[attrNameShort] = val;
    }
  }

	initUI()
	{
		this.setThemeVariantInput();

		if (!this.mainAppContent)
			return 0;

		if ('mainUiObjectId' in this.mainAppContent.dataset)
			return this.initUIObject(this.mainAppContent.dataset.mainUiObjectId);
	}

	initUIObject(id)
	{
		let objectElement = document.getElementById(id);
		if (!objectElement)
		{
			console.error('element not exist: #', id);
			return 0;
		}

		const objectElementType = objectElement.getAttribute('data-object-type');
		if (!objectElementType)
		{
			console.error('`data-object-type` attr not found in #', id);
			return 0;
		}

		if (objectElementType === 'data-viewer')
			return initWidgetTableViewer(id);
		if (objectElementType === 'data-widget-board')
			return initWidgetBoard(id);

		console.log(objectElementType);

		return 0;
	}

	loadUI()
	{
		console.log("client__load_ui");
	}

	init ()
	{
		this.mainAppContent = document.getElementById('shp-main-app-content');
		this.server.setHttpServerRoot(httpApiRootPath);

		this.initColorMode(true);

		this.onClick ('.shp-simple-tabs-item', function () {shc.simpleTabsEvent(this);});
		this.onClick ('.shp-app-action', function (e) {this.widgetAction(e);}.bind(this));

		this.initUI();

		if ('serviceWorker' in navigator && e10ServiceWorkerURL !== undefined) {
			navigator.serviceWorker.register(e10ServiceWorkerURL)
				.then(function(reg){
				}).catch(function(err) {
				console.log("Service worker registration error: ", err)
			});
		}

		initWidgetApplication('shp-app-window');
	}

	applyUIData (responseUIData)
	{
		this.mqtt.applyUIData(responseUIData);
		this.iot.applyUIData(responseUIData);
	}

	apiCall(apiActionId, outsideApiParams)
  {
    var apiParams = {};
    apiParams['requestType'] = 'appCommand';
    apiParams['actionId'] = apiActionId;
    if (outsideApiParams !== undefined)
      apiParams = {...apiParams, ...outsideApiParams};

    console.log("CLIENT-API-CALL", apiParams);

    var url = 'api/v2';

    shc.server.post (url, apiParams,
      function (data) {
        console.log("--app-api-call-success--");
        this.doAppAPIResponse(data);
      }.bind(this),
      function (data) {
        console.log("--api-app-call-error--");
      }.bind(this)
    );
  }

	doAppAPIResponse(data)
	{
		window.location.reload(true);
	}
}
