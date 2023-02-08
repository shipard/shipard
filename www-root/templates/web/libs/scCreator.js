



function makeClicksMap()
{
  let map = [];
  const fsLinks = document.getElementsByClassName('sc-click-element-fs');
  for (let i = 0; i < fsLinks.length; i++)
  {
    const e = fsLinks[i];
    const id = e.id;
    const br = e.getBoundingClientRect();

    let clickInfo = {
      'id': id,
      'left': parseInt(br.left), 'right': parseInt(br.right),
      'top': parseInt(br.top), 'bottom': parseInt(br.bottom),
      'uri': e.getAttribute('data-link-url'),
      'pageNumber': parseInt(e.getAttribute('data-page-number')),
      'linkType': parseInt(e.getAttribute('data-link-type')),
    }
    map.push (clickInfo);
  }
  /*
  const puLinks = document.getElementsByClassName('sc-click-element-pu');
  for (let i = 0; i < puLinks.length; i++)
  {
    const e = puLinks[i];
    const id = e.id;
    const br = e.getBoundingClientRect();

    let clickInfo = {
      'id': id,
      'left': parseInt(br.left), 'right': parseInt(br.right),
      'top': parseInt(br.top), 'bottom': parseInt(br.bottom),
      'uri': e.getAttribute('data-link-url'),
      'pageNumber': parseInt(e.getAttribute('data-page-number')),
      'linkType': parseInt(e.getAttribute('data-link-type')),
    }

    map.push(clickInfo);
  }
  */

  return map;
}

function makePageInfo()
{
  let pageInfo = {};

  const pageInfoElement = document.getElementById('shp-sc-page-info');
  pageInfo['imageId'] = pageInfoElement.getAttribute('data-this-id');

  pageInfo['clickMap'] = makeClicksMap();

  document.getElementById('shp-sc-page-info-result').value = JSON.stringify(pageInfo);
  let dstTextArea = document.getElementById('shp-sc-page-info-result');
}

window.addEventListener("load", function () {
  makePageInfo();
});

