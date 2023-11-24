class ShipardWidgetVS extends ShipardWidgetBoard
{
  doWidgetResponse(data)
  {
    //console.log("VS-WIDGET-RESPONSE");
    super.doWidgetResponse(data);
  }
}

function initWidgetVS(id)
{
  let e = document.getElementById(id);
  e.shpWidget = new ShipardWidgetVS();
  e.shpWidget.init(e);

  console.log("initWidgetVS", id);
}
