class ShipardWidgetBoard extends ShipardWidget
{
  elmContent = null;

  init(e)
  {
    console.log("ShipardWidgetBoard::init");
    super.init(e);

    this.initContent();
  }

  initContent()
  {
    this.elmContent = this.rootElm.querySelector('.shp-wb-content');

    /*
    var mc = new Hammer(this.elmContent);
    mc.get('swipe').set({ direction: Hammer.DIRECTION_HORIZONTAL, threshold: 250 });
    mc.get('pan').set({ direction: Hammer.DIRECTION_HORIZONTAL, threshold: 250 });
    mc.on("panleft panright", function(ev) {this.doSwipe(ev)}.bind(this));
    */
  }

  doSwipe(dir)
  {
    var swipeDir = 0;
    if (dir.type === 'panleft')
      swipeDir = 1;
    else if (dir.type === 'panright')
      swipeDir = 2;

    if (!swipeDir)
      return;

    let apiParams = {'cgType': 2, 'swipe': swipeDir};
    this.apiCall('reloadContent', apiParams);
  }

  doAction (actionId, e)
  {
    console.log("ACTION-BOARD: ", actionId);
    switch (actionId)
    {
      case 'set-param-value': return this.setParamValue(e);
      case 'newform': return this.actionNewForm(e);
      case 'edit': return this.actionEditForm(e);
    }

    return super.doAction (actionId, e);
  }

  doWidgetResponse(data)
  {
    //console.log('doWidgetResponse: ');
    //console.log(data);
    //this.rootElm.innerHTML = data.response.hcMain;
    this.setInnerHTML(this.rootElm, data.response.hcMain);
    this.initContent();

    super.doWidgetResponse(data);
  }

  setParamValue(e)
  {
    var inputElement = e.parentElement.parentElement.querySelector('input');
    if (!inputElement)
      inputElement = e.parentElement.parentElement.parentElement.querySelector('input');

    //console.log("setParamValue1: ", inputElement, e.getAttribute('data-value'));
    if (inputElement)
      inputElement.value = e.getAttribute('data-value');
    //console.log("setParamValue2: ", inputElement, e.getAttribute('data-value'));

    let apiParams = {'cgType': 2};
    this.apiCall('reloadContent', apiParams);
  }

  actionNewForm(e)
  {
	  var formParams = {};
	  var formAttrs = {
      'parent-widget-id': this.rootElm.getAttribute('id'),
      'parent-widget-type': 'board',
    };
    this.elementPrefixedAttributes (this.rootElm, 'data-form-param-', formParams);
    this.elementPrefixedAttributes (e, 'data-action-param-', formParams);
    this.openModalForm('new', formParams, formAttrs);
  }

  actionEditForm(e)
  {
	  var formParams = {};
	  var formAttrs = {
      'parent-widget-id': this.rootElm.getAttribute('id'),
      'parent-widget-type': 'board',
    };
    this.elementPrefixedAttributes (this.rootElm, 'data-form-param-', formParams);
    this.elementPrefixedAttributes (e, 'data-action-param-', formParams);
    this.openModalForm('edit', formParams, formAttrs);
  }

  refreshData(e)
  {
    let apiParams = {'cgType': 2};
    this.apiCall('reloadContent', apiParams);
  }
}

function initWidgetBoard(id)
{
  console.log("INIT_BOARD_2!!!!");

  let e = document.getElementById(id);
  e.shpWidget = new ShipardWidgetBoard();
  e.shpWidget.init(e);
  return 1;
}
