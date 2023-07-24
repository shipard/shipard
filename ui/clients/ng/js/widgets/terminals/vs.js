class ShipardWidgetVS extends ShipardWidget
{
  doWidgetResponse(data)
  {
    console.log("VS-WIDGET-RESPONSE");

    let dataElement = this.rootElm.querySelector ('div.e10-wr-data');
    dataElement.innerHTML = data.response.hcMain;
    console.log(dataElement);

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
