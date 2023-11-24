class ShipardWidgetApplication extends ShipardWidget
{
  elmContent = null;

  init(e)
  {
    console.log("ShipardWidgetApplication::init");
    super.init(e);

    this.initContent();
  }

  initContent()
  {
  }

  doAction (actionId, e)
  {
    console.log("ACTION-APP: ", actionId);
    /*
    switch (actionId)
    {
      case 'set-param-value': return this.setParamValue(e);
      case 'newform': return this.actionNewForm(e);
      case 'edit': return this.actionEditForm(e);
    }
    */
    return super.doAction (actionId, e);
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
}

function initWidgetApplication(id)
{
  console.log("INIT_APP!!!!");

  let e = document.getElementById(id);
  e.shpWidget = new ShipardWidgetApplication();
  e.shpWidget.init(e);
  return 1;
}
