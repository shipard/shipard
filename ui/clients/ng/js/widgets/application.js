class ShipardWidgetApplication extends ShipardWidget
{
	mainAppContent = null;
  elmAppTitle = null;

  init(e)
  {
    this.mainAppContent = document.getElementById('shp-main-app-content');
    this.elmAppTitle = document.getElementById('shp-app-hdr-title');

    console.log("ShipardWidgetApplication::init");
    super.init(e);

    this.initContent();
  }

  initContent()
  {
  }

  doAction (actionId, e)
  {
    switch (actionId)
    {
      case 'loadAppMenuItem': return this.loadAppMenuItem(e);
      case 'toggleContent': return this.fullScreenContentToggle(e);
    }

    return super.doAction (actionId, e);
  }

  fullScreenContentToggle(e)
  {
		if (this.rootElm.classList.contains('full-screen-content-on'))
      this.fullScreenContentOff();
    else
      this.fullScreenContentOn();

    return 0;
  }

  fullScreenContentOn()
  {
    this.rootElm.classList.remove('full-screen-content-off');
    this.rootElm.classList.add('full-screen-content-on');
  }

  fullScreenContentOff()
  {
    this.rootElm.classList.remove('full-screen-content-on');
    this.rootElm.classList.add('full-screen-content-off');
  }

  doWidgetResponse(data)
  {
    /*
    //console.log('doWidgetResponse: ');
    //console.log(data);
    //this.rootElm.innerHTML = data.response.hcMain;
    this.setInnerHTML(this.rootElm, data.response.hcMain);
    this.initContent();
    */
    super.doWidgetResponse(data);
  }

  loadAppMenuItem (e)
  {
		console.log('loadAppMenuItem222', e);


    const modalType = 'viewer';

    //console.log("OPEN-MODAL; ", modalType);

    var modalParams = {};
	  var modalAttrs = {
      'parent-widget-id': '',
      'parent-widget-type': 'unknown',
    };

    //this.elementPrefixedAttributes (e, 'data-action-param-', modalParams);

    switch (modalType)
    {
      case 'viewer':  console.log('Viewer!');
                      break;
    }

    let apiParams = {
      'cgType': 2,
      'requestType': 'appMenuItem',
			//'object-type': 'dataViewer',
      //'formOp': e.formOp,
    };

    this.elementPrefixedAttributes (e, 'data-action-param-', apiParams);

		if (apiParams['object-type'] === 'viewer')
			apiParams['object-type'] = 'dataViewer';

    console.log("API-CALL-MENU-ITEM", apiParams);

    var url = 'api/v2';

    this.fullScreenContentOn();

    shc.server.post (url, apiParams,
      function (data) {
        console.log("--api-call-MENU-ITEM-success--", data);
        this.doLoadAppMenuItemResponse(data);
      }.bind(this),
      function (data) {
        console.log("--api-call-MODAL-error--");
      }.bind(this)
    );

    return 0;
	}

	doLoadAppMenuItemResponse (data)
	{
		console.log("doLoadAppMenuItemResponse", data);

    this.setInnerHTML(this.mainAppContent, data.response.hcFull);

    if (this.elmAppTitle && data.response.hcTitle !== undefined)
    {
      this.elmAppTitle.innerHTML = data.response.hcTitle;
    }

    if (data.response.objectType === 'dataView')
      initWidgetTableViewer(data.response.objectId);
    else
    {
      console.log("init-other-widget");
      let e = document.getElementById(data.response.objectId);
      e.shpWidget = new ShipardWidget();
      e.shpWidget.init(e);
    }
	}
}

function initWidgetApplication(id)
{
  console.log("INIT_APP!!!!");

  let e = document.getElementById(id);
  e.shpWidget = new ShipardWidgetApplication();
  e.shpWidget.init(e);
  return 1;
}
