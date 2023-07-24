class ShipardTableViewer extends ShipardWidget
{



  doWidgetResponse(data)
  {
    console.log("ShipardTableViewer::doWidgetResponse");

    super.doWidgetResponse(data);
  }

  init(e)
  {
    console.log("ShipardTableViewer::init");
    super.init(e);
    this.rootElm.style.display = 'grid';
  }
}

function initWidgetTableViewer(id)
{
  let e = document.getElementById(id);
  e.shpWidget = new ShipardTableViewer();
  e.shpWidget.init(e);
  return 1;
}
