class
ShipardServer{httpServerRoot='';init(){}beginUrl(){return this.httpServerRoot;}httpHeaders(){var
headers={};headers['content-type']='application/json';return headers;}get(url,f,errorFunction,isFullUrl){var
fullUrl=this.httpServerRoot+url;if(isFullUrl)fullUrl=url;var
options={method:"GET",url:fullUrl,headers:this.httpHeaders(),};fetch(fullUrl,options).then((response)=>response.json()).then((data)=>{f(data);}).catch((error)=>{console.error("Error:",error);});}post(url,data,f,errorFunction){var
fullUrl=this.httpServerRoot+url;var
options={method:'POST',url:fullUrl,body:JSON.stringify(data),dataType:'json',headers:this.httpHeaders(),error:(errorFunction!='undefined')?errorFunction:function(data){console.log("========================ERROR: "+fullUrl);}};fetch(fullUrl,options).then((response)=>response.json()).then((data)=>{console.log("Success:",data);f(data);}).catch((error)=>{console.error("Error:",error);});}api(data,f,errorFunction){var
fullUrl=this.beginUrl()+'/api';var
options={method:'POST',url:fullUrl,body:JSON.stringify(data),dataType:'json',headers:this.httpHeaders(),error:(errorFunction!='undefined')?errorFunction:function(data){console.log("========================ERROR: "+fullUrl);}};fetch(fullUrl,options).then((response)=>response.json()).then((data)=>{console.log("Success:",data);f(data);}).catch((error)=>{console.error("Error:",error);});}postForm(url,data,f){var
fullUrl=this.httpServerRoot+url;var
options={type:'POST',url:fullUrl,success:f,data:data,headers:this.httpHeaders(),error:function(data){console.log("========================ERROR: "+fullUrl);}};}setHttpServerRoot(httpServerRoot){this.httpServerRoot=httpServerRoot;}}class
ShipardMqtt{init(){if(typeof
Paho=='undefined')return;for(var
i
in
webSocketServers)this.startClient(i);}startClient(serverIndex){var
ws=webSocketServers[serverIndex];if(ws.fqdn===null||ws.fqdn==='')return;let
portNumber=parseInt(ws.port);if(portNumber===0)return;ws.retryTimer=0;ws.mqttClient=new
Paho.MQTT.Client(ws.fqdn,portNumber,deviceId+'-'+Math.random().toString(36));ws.mqttClient.onConnectionLost=function(xxxx){console.log(xxxx),setTimeout(()=>shc.mqtt.setState(serverIndex,'cnlst'),200);webSocketServers[serverIndex].retryTimer=setTimeout(()=>shc.mqtt.startClient(serverIndex,1),3000);};ws.mqttClient.onMessageArrived=(message)=>shc.mqtt.onMessage(serverIndex,message);ws.mqttClient.connect({onSuccess:()=>{shc.mqtt.setState(serverIndex,'open');shc.mqtt.subscribeAll(serverIndex);},onFailure:()=>{shc.mqtt.setState(serverIndex,'error');webSocketServers[serverIndex].retryTimer=setTimeout(()=>shc.mqtt.startClient(serverIndex,1),3000);},useSSL:true});}subscribeAll(serverIndex){var
ws=webSocketServers[serverIndex];for(const
oneTopic
in
uiData['iotTopicsMap']){ws.mqttClient.subscribe(oneTopic);}for(const
oneTopic
in
uiData['iotTopicsMap']){if(uiData['iotTopicsMap'][oneTopic]['type']==='device'||uiData['iotTopicsMap'][oneTopic]['type']==='scene'){let
message=new
Paho.MQTT.Message('{"state": ""}');message.destinationName=oneTopic+'/get';ws.mqttClient.send(message);}}}onMessage(serverIndex,data){shc.mqtt.setElementValue(serverIndex,data);return;}setElementValue(serverIndex,data){let
payload=null;if(data.payloadString[0]==='{'||data.payloadString[0]==='[')payload=JSON.parse(data.payloadString);else
payload={value:data.payloadString};if(uiData['iotTopicsMap']===undefined){console.log("Missing uiData topics map");return;}let
topicInfo=uiData['iotTopicsMap'][data.destinationName];if(topicInfo===undefined){console.log("Missing topic info in uiData");return;}for(let
i=0;i<topicInfo['elids'].length;i++){let
elid=topicInfo['elids'][i];let
mqttItem=document.getElementById(elid);if(!mqttItem){console.log("NOT EXIST",elid);continue;}let
family=mqttItem.getAttribute('data-shp-family');if(family==='iot-sensor'){let
valueElement=mqttItem.querySelector('span.value');valueElement.textContent=payload.value;}else
if(family==='iot-light'){let
switchElement=mqttItem.getElementsByClassName('shp-iot-primary-switch');if(switchElement.length>0){let
propertyId=switchElement[0].getAttribute('data-shp-iot-state-id');if(propertyId===null)propertyId='state';if(payload[propertyId]!==undefined){if(switchElement[0].disabled)switchElement[0].disabled=false;switchElement[0].checked=payload[propertyId]==='ON';}}if(payload['brightness']!==undefined){let
brElement=mqttItem.getElementsByClassName('shp-iot-br-range');if(brElement.length>0){if(brElement[0].disabled)brElement[0].disabled=false;brElement[0].value=payload['brightness'].toString();}}if(payload['color_temp']!==undefined){let
ctElement=mqttItem.getElementsByClassName('shp-iot-ct-range');if(ctElement.length>0){if(ctElement[0].disabled)ctElement[0].disabled=false;ctElement[0].value=payload['color_temp'].toString();}}setTimeout(function(){shc.mqtt.checkGroups()},100);}else
if(family==='iot-setup-scene'){if(payload['scene']!==undefined){let
scElement=mqttItem.querySelectorAll("[data-shp-scene-id='"+payload['scene']+"']");if(scElement.length>0)scElement[0].checked=true;}}}}checkGroups(){for(const
groupId
in
uiData['iotElementsGroups']){this.checkGroup(groupId);}}checkGroup(groupId){let
groupMainElement=document.getElementById(groupId);if(!groupMainElement){console.log('Invalid element for group: ',groupId);return;}let
stateOnOff=0;for(const
groupElementNdx
in
uiData['iotElementsGroups'][groupId]){let
sid=uiData['iotElementsGroups'][groupId][groupElementNdx];let
topicId=uiData['iotSubjects'][sid]['topic'];let
elid=uiData['iotTopicsMap'][topicId]['elids'][0];let
mqttItem=document.getElementById(elid);if(!mqttItem){console.log('element not exist: ',elid);continue;}let
switchElement=mqttItem.getElementsByClassName('shp-iot-primary-switch');if(switchElement.length>0){if(switchElement[0].checked)stateOnOff=1;}}let
switchElement=groupMainElement.getElementsByClassName('shp-iot-group-switch');if(switchElement.length>0){if(switchElement[0].disabled)switchElement[0].disabled=false;if(stateOnOff&&!switchElement[0].checked)switchElement[0].checked=true;else
if(!stateOnOff&&switchElement[0].checked)switchElement[0].checked=false;}}setState(serverIndex,socketState){var
ws=webSocketServers[serverIndex];}publish(serverIndex,topic,payload){var
ws=webSocketServers[serverIndex];let
message=new
Paho.MQTT.Message(payload);message.destinationName=topic;ws.mqttClient.send(message);}}class
ShipardCamsPictsLoader{camerasTimer=null;init(){this.reloadImages();}reloadImages(){if(this.camerasTimer){clearTimeout(this.camerasTimer);}for(let
serverNdx
in
uiData['iotCamServers']){const
urlPath=uiData['iotCamServers'][serverNdx]['camUrl']+"campicts";shc.server.get(urlPath,function(data){this.setPictures(serverNdx,data);}.bind(this),function(data){console.log("--load-error--");}.bind(this),1);}this.camerasTimer=setTimeout(function(){this.reloadImages()}.bind(this),3000);}setPictures(serverNdx,data){const
server=uiData['iotCamServers'][serverNdx];for(let
camNdx
in
data){if(data[camNdx]['image']===false){continue;}let
camId='CMP'+camNdx;if(uiData['iotCamPictures'][camId]===undefined)continue;let
pictUrl='';let
ids=uiData['iotCamPictures'][camId]['elms'];for(var
key
in
ids){let
camPictElement=document.getElementById(ids[key]);let
pictStyle=camPictElement.getAttribute('data-pict-style');if(pictStyle==='full')pictUrl=server['camUrl']+'imgs/'+camNdx+'/'+data[camNdx]['image'];else
pictUrl=server['camUrl']+'imgs/-w960/-q70/'+camNdx+'/'+data[camNdx]['image'];let
imgElement=camPictElement.querySelector('img');imgElement.src=pictUrl;}}}}class
ShipardWidget{rootElm=null;rootId='';numPad=null;init(rootElm){this.rootElm=rootElm;this.rootId=this.rootElm.getAttribute('id');console.log("hello from ShipardWidget",this.rootId);this.on(this,'click','.shp-widget-action',function(e,ownerWidget){ownerWidget.widgetAction(e)});}widgetAction(e){let
actionId=e.getAttribute('data-action');this.doAction(actionId,e);}doAction(actionId,e){switch(actionId){case'inline-action':return this.inlineAction(e);}return 0;}inlineAction(e){if(e.getAttribute('data-object-class-id')===null)return;var
requestParams={};requestParams['object-class-id']=e.getAttribute('data-object-class-id');requestParams['action-type']=e.getAttribute('data-action-type');this.elementPrefixedAttributes(e,'data-action-param-',requestParams);if(e.getAttribute('data-pk')!==null)requestParams['pk']=e.getAttribute('data-pk');console.log("__INLINE_ACTION",requestParams);}on(ownerWidget,eventType,selector,callback){this.rootElm.addEventListener(eventType,function(event){if(event.target.matches(selector)){callback.call(event.target,event.target,ownerWidget);}});}onClick(ownerWidget,selector,callback){this.on(ownerWidget,'click',selector,callback)};nf(n,c){var
c=isNaN(c=Math.abs(c))?2:c,d='.',t=' ',s=n<0?"-":"",i=parseInt(n=Math.abs(+n||0).toFixed(c))+"",j=(j=i.length)>3?j%3:0;return s+(j?i.substr(0,j)+t:"")+i.substr(j).replace(/(\d{3})(?=\d)/g,"$1"+t)+(c?d+Math.abs(n-i).toFixed(c).slice(2):"");}parseFloat(n){var
str=n.replace(',','.');return parseFloat(str);}round(value,decimals){return Number(Math.round(value+'e'+decimals)+'e-'+decimals);}escapeHtml(str){var
div=document.createElement('div');div.appendChild(document.createTextNode(str));return div.innerHTML;};elmHide(e){e.classList.add('d-none');}elmShow(e){e.classList.remove('d-none');}getNumber(options){const
template=document.createElement('div');template.id='widget_123';template.classList.add('fullScreenModal');document.body.appendChild(template);var
abc=new
ShipardTouchNumPad();abc.options=options;abc.init(template);this.numPad=abc;}elementPrefixedAttributes(iel,prefix,data){for(var
i=0,attrs=iel.attributes,l=attrs.length;i<l;i++){var
attrName=attrs.item(i).nodeName;if(attrName.substring(0,prefix.length)!==prefix)continue;var
attrNameShort=attrName.substring(prefix.length);var
val=attrs.item(i).nodeValue;data[attrNameShort]=val;}}}class
ShipardTouchNumPad
extends
ShipardWidget{elmDisplay=null;gnId='gn1234';gnValue='';options=null;init(rootElm){super.init(rootElm);var
c="<div class='shp-numpad-container' id='"+this.gnId+"'>";c+="<table class='shp-numpad-keyboard'>";c+="<tr>";c+="<td class='c shp-widget-action' data-action='pressCancel'>✕</td><td class='m' colspan='3'>";if(this.options.title)c+="<div class='title'>"+this.escapeHtml(this.options.title)+"</div>";if(this.options.subtitle)c+="<div class='e10-small'>"+this.escapeHtml(this.options.subtitle)+"</div>";c+="</td>";c+="</tr>";c+="<tr>";c+="<td class='d shp-widget-action' colspan='3'></td><td class='b shp-widget-action' data-action='pressBackspace'>←</td>";c+="</tr>";c+="<tr>";c+="<td class='n shp-widget-action' data-action='pressKey'>7</td><td class='n shp-widget-action' data-action='pressKey'>8</td><td class='n shp-widget-action' data-action='pressKey'>9</td><td class='ok shp-widget-action' data-action='pressOK' rowspan='4'>✔︎</td>";c+="</tr>";c+="<tr>";c+="<td class='n shp-widget-action' data-action='pressKey'>4</td><td class='n shp-widget-action' data-action='pressKey'>5</td><td class='n shp-widget-action' data-action='pressKey'>6</td>";c+="</tr>";c+="<tr>";c+="<td class='n shp-widget-action'  data-action='pressKey'>1</td><td class='n shp-widget-action' data-action='pressKey'>2</td><td class='n shp-widget-action' data-action='pressKey'>3</td>";c+="</tr>";c+="<tr>";c+="<td class='n shp-widget-action' colspan='2' data-action='pressKey'>0</td><td class='n shp-widget-action' data-action='pressKey'>,</td>";c+="</tr>";c+="</table>";c+="</div>";this.rootElm.innerHTML=c;this.elmDisplay=this.rootElm.querySelector('td.d');}doAction(actionId,e){switch(actionId){case'pressKey':return this.kbdPressKey(e);case'pressBackspace':return this.kbdPressBackspace(e);case'pressCancel':return this.kbdPressCancel(e);case'pressOK':return this.kbdPressOK(e);}return super.doAction(actionId,e);}kbdPressKey(e){const
c=e.innerText;this.gnValue+=c;this.elmDisplay.innerText=this.gnValue;return 0;}kbdPressBackspace(e){if(!this.gnValue.length)return;this.gnValue=this.gnValue.slice(0,-1);this.elmDisplay.innerText=this.gnValue;return 0;}kbdPressCancel(e){this.rootElm.remove();}kbdPressOK(e){this.options.success(null);}}class
ShipardWidgetDocumentCore
extends
ShipardWidget{docRowsTableElm=null;displayAllElm=null;displayValueElm=null;elmIntro=null;elmContainerSell=null;elmContainerPay=null;elmContainerSave=null;doc=null;mode='';init(rootElm){super.init(rootElm);this.docRowsTableElm=this.rootElm.querySelector('div.rows>table.rows');this.elmIntro=this.rootElm.querySelector('div.cash-box-rows-content>div.docTermIntro');this.displayAllElm=this.rootElm.querySelector('div.display');this.displayValueElm=this.displayAllElm.querySelector('div.total');this.elmContainerSell=this.rootElm.querySelector('div.cash-box-container-sell');this.elmContainerPay=this.rootElm.querySelector('div.cash-box-container-pay');this.elmContainerSave=this.rootElm.querySelector('div.cash-box-container-save');}}class
WidgetCashBox
extends
ShipardWidgetDocumentCore{init(rootElm){super.init(rootElm);console.log("hello, cashBox",this.rootId);this.documentInit();}doAction(actionId,e){console.log("ACTION: ",actionId);switch(actionId){case'addRow':return this.newRow(e);case'quantity-plus':return this.documentQuantityRow(e,1);case'quantity-minus':return this.documentQuantityRow(e,-1);case'remove-row':return this.documentRemoveRow(e);case'terminal-sell':return this.setMode('sell');case'terminal-pay':return this.doPay(e);case'terminal-save':return this.save();case'change-payment-method':return this.changePaymentMethod(e);}return super.doAction(actionId,e);}itemFromElement(e){var
item={pk:e.getAttribute('data-pk'),price:parseFloat(e.getAttribute('data-price')),quantity:(e.getAttribute('data-quantity'))?parseFloat(e.getAttribute('data-quantity')):1,name:e.getAttribute('data-name'),askq:e.getAttribute('data-askq'),askp:e.getAttribute('data-askp'),unit:e.getAttribute('data-unit'),unitName:e.getAttribute('data-unit-name')};return item;}newRow(e){var
askq=parseInt(e.getAttribute('data-askq'));var
askp=parseInt(e.getAttribute('data-askp'));if(!askq&&!askp){this.addDocumentRow(this.itemFromElement(e));return 1;}if(askp){this.getNumber({title:'Zadejte cenu '+'('+e.getAttribute('data-unit-name')+')',subtitle:e.getAttribute('data-name'),srcElement:e,askType:'p',success:function(){this.addDocumentRow()}.bind(this)});return 1;}if(askq){this.getNumber({title:'Zadejte množství '+'('+e.getAttribute('data-unit-name')+')',subtitle:e.getAttribute('data-name'),srcElement:e,askType:'q',success:function(){this.addDocumentRow()}.bind(this)});}return 1;}addDocumentRow(item){var
quantity=1;if(!item){item=this.itemFromElement(this.numPad.options.srcElement);if(this.numPad.options.askType==='p'){var
price=e10.parseFloat(this.numPad.gnValue);if(!price)price=null;if(price!==null)item.price=price;}else
if(!this.numPad.options.askType||this.numPad.options.askType==='q'){quantity=this.parseFloat(this.numPad.gnValue);if(!quantity)quantity=1;}this.numPad.rootElm.remove();this.numPad=null;}else
if(item.quantity)quantity=item.quantity;var
priceStr=this.nf(item.price,2);var
totalPrice=this.round(quantity*item.price,2);var
totalPriceStr=this.nf(totalPrice,2);var
row='<tr'+' data-pk="'+item.pk+'"'+' data-quantity="'+quantity+'"'+' data-price="'+item.price+'"'+' data-totalprice="'+totalPrice+'"'+'>';row+='<td class="shp-widget-action" data-action="remove-row">×</td>';row+='<td class="item">'+'<span class="t">'+this.escapeHtml(item.name)+'</span>'+'<br>'+'<span class="e10-small i e10-terminal-action" data-action="row-price-item-change">'+quantity+' '+item.unitName+' á '+priceStr+' = <b>'+totalPriceStr+'</b>'+'</span>'+'</td>';row+='<td class="q number">'+quantity+'</td>';row+='<td class="shp-widget-action" data-action="quantity-plus">+</td>';row+='<td class="shp-widget-action" data-action="quantity-minus">-</td>';row+='</tr>';this.docRowsTableElm.innerHTML=row+this.docRowsTableElm.innerHTML;let
re=this.docRowsTableElm.rows[0];re.setAttribute('data-unit',item.unit);re.setAttribute('data-unit-name',item.unitName);re.setAttribute('data-name',item.name);this.documentRecalc();}documentInit(clearUI){if(this.doc!==null)delete
this.doc;this.doc={rec:{docType:"cashreg",currency:"czk",paymentMethod:1,taxCalc:parseInt(this.rootElm.getAttribute('data-taxcalc')),automaticRound:1,roundMethod:parseInt(this.rootElm.getAttribute('data-roundmethod')),cashBox:parseInt(this.rootElm.getAttribute('data-cashbox')),warehouse:parseInt(this.rootElm.getAttribute('data-warehouse')),docState:4000,docStateMain:2,toPay:0.0},rows:[]};if(clearUI===true){while(this.docRowsTableElm.rows.length)this.docRowsTableElm.rows[0].remove();this.documentRecalc();}};documentRecalc(){var
rowsCount=0;var
totalPrice=0.0;this.doc.rows.length=0;for(let
i=0;i<this.docRowsTableElm.rows.length;i++){var
r=this.docRowsTableElm.rows[i];var
rowTotalPrice=parseFloat(r.getAttribute('data-totalprice'));totalPrice+=rowTotalPrice;rowsCount++;var
documentRow={item:parseInt(r.getAttribute('data-pk')),text:r.getAttribute('data-name'),quantity:parseFloat(r.getAttribute('data-quantity')),unit:r.getAttribute('data-unit'),priceItem:parseFloat(r.getAttribute('data-price'))};this.doc.rows.push(documentRow);}const
totalPriceStr=this.nf(totalPrice,2);let
toPay=(this.doc.rec.roundMethod==1)?this.round(totalPrice,0):totalPrice;this.doc.toPay=toPay;let
displayToPay=this.elmContainerPay.querySelector('div.paymentAmount>span.money-to-pay');displayToPay.innerText=this.nf(toPay,2);this.displayValueElm.innerText=totalPriceStr;if(rowsCount){this.elmHide(this.elmIntro);this.elmShow(this.docRowsTableElm);}else{this.elmHide(this.docRowsTableElm);this.elmShow(this.elmIntro);}}documentQuantityRow(e,how){var
row=e.parentElement;var
quantity=parseFloat(row.getAttribute('data-quantity'));if(how===-1&&quantity<=1.0)return;quantity+=how;var
price=parseFloat(row.getAttribute('data-price'));var
totalPrice=quantity*price;var
quantityStr=quantity;row.setAttribute('data-quantity',quantity);row.setAttribute('data-totalprice',totalPrice);row.querySelector('td.q').innerText=quantityStr;var
unitName=row.getAttribute('data-unit-name');var
rowInfo=quantityStr+' '+unitName+' á '+this.nf(price,2)+' = <b>'+this.nf(totalPrice,2)+'</b>';row.querySelector('td.item>span.i').innerHTML=rowInfo;this.documentRecalc();return 0;}documentRemoveRow(e){var
row=e.parentElement;row.remove();this.documentRecalc();return 0;}doPay(e){var
paymentMethod=e.getAttribute('data-pay-method');console.log('payment method: ',paymentMethod);this.changePaymentMethod(e);this.setMode('pay');}changePaymentMethod(e){let
paymentMethod=parseInt(e.getAttribute('data-pay-method'));this.doc.rec.paymentMethod=parseInt(paymentMethod);if(this.doc.rec.paymentMethod==2)this.doc.rec.roundMethod=0;else
this.doc.rec.roundMethod=parseInt(this.rootElm.getAttribute('data-roundmethod'));this.documentRecalc();const
radioButtons=this.elmContainerPay.querySelectorAll('input[type="radio"]');console.log('set-pay-method: ',paymentMethod,e);for(const
radioButton
of
radioButtons){console.log(radioButton);if(parseInt(radioButton.value)==paymentMethod)radioButton.checked=true;else
radioButton.checked=false;}return 0;}save(){console.log("__SAVE__");this.setMode('save');var
printAfterConfirm='1';var
url='/api/objects/insert/e10doc.core.heads?printAfterConfirm='+printAfterConfirm;shc.server.post(url,this.doc,function(data){console.log("--save-success--");this.documentInit(true);this.setMode('sell');}.bind(this),function(data){console.log("--save-error--");}.bind(this));return 0;}setMode(mode){if(mode==='pay'){console.log("do-pay",this.elmContainerSell);this.elmHide(this.elmContainerSell);this.elmHide(this.elmContainerSave);this.elmShow(this.elmContainerPay);}else
if(mode==='sell'){this.elmHide(this.elmContainerSave);this.elmHide(this.elmContainerPay);this.elmShow(this.elmContainerSell);}else
if(mode==='save'){this.elmHide(this.elmContainerSell);this.elmHide(this.elmContainerPay);this.elmShow(this.elmContainerSave);}return 0;}}function
initWidgetCashBox(id){let
e=document.getElementById(id);e.shpWidget=new
WidgetCashBox();e.shpWidget.init(e);}class
ShipardClient{server=new
ShipardServer();mqtt=new
ShipardMqtt();iot=new
ShipardClientIoT();appVersion='2.0.1';CLICK_EVENT='click';g_formId=1;openModals=[];progressCount=0;viewerScroll=0;disableKeyDown=0;userInfo=null;numPad=null;on(eventType,selector,callback){document.addEventListener(eventType,function(event){if(event.target.matches(selector)){callback.call(event.target,event.target);}});}onClick(selector,callback){this.on('click',selector,callback)};simpleTabsEvent(e){let
tabsId=e.getAttribute('data-tabs');let
tabsElement=document.getElementById(tabsId+'-tabs');let
oldActiveTabElement=tabsElement.querySelector('a.active');oldActiveTabElement.classList.remove('active');let
oldActiveContentId=oldActiveTabElement.getAttribute('data-tab-id');let
oldActiveContentElement=document.getElementById(oldActiveContentId);oldActiveContentElement.classList.add('d-none');e.classList.add('active');let
newActiveContentId=e.getAttribute('data-tab-id');let
newActiveContentElement=document.getElementById(newActiveContentId);newActiveContentElement.classList.remove('d-none');}searchParentAttr(e,attr){var
p=e;while(p.length){var
attrValue=p.attr(attr);if(p.attr(attr))return p.attr(attr);p=p.parent();if(!p.length)break;}return null;};searchObjectAttr(e,attr){var
p=e;while(p.length){if(p.attr(attr))return p;p=p.parent();if(!p.length)break;}return null;};widgetAction(e){let
actionId=e.getAttribute('data-action');this.doAction(actionId,e);}doAction(actionId,e){switch(actionId){case'setColorMode':return this.setColorMode(e);case'workplaceLogin':return this.workplaceLogin(e);case'inline-action':return this.inlineAction(e);}return 0;}inlineAction(e){if(e.getAttribute('data-object-class-id')===null)return;var
requestParams={};requestParams['object-class-id']=e.getAttribute('data-object-class-id');requestParams['action-type']=e.getAttribute('data-action-type');this.elementPrefixedAttributes(e,'data-action-param-',requestParams);if(e.getAttribute('data-pk')!==null)requestParams['pk']=e.getAttribute('data-pk');shc.server.api(requestParams,function(data){}.bind(this));}workplaceLogin(e){console.log('workplaceLogin',e.getAttribute('data-login'));this.getNumber({title:'Zadejte přístupový kód',srcElement:e,userLogin:e.getAttribute('data-login'),success:function(){this.workplaceLoginDoIt()}.bind(this)});return 0;}workplaceLoginDoIt(e){console.log(this.numPad.options.userLogin);console.log("__DO_IT__",this.numPad.options.srcElement.getAttribute('data-login'),this.numPad.gnValue);document.getElementById('e10-login-user').value=this.numPad.options.userLogin;document.getElementById('e10-login-pin').value=this.numPad.gnValue;document.forms['e10-mui-login-form'].submit();}getNumber(options){const
template=document.createElement('div');template.id='widget_123';template.classList.add('fullScreenModal');document.body.appendChild(template);var
abc=new
ShipardTouchNumPad();abc.options=options;abc.init(template);this.numPad=abc;}setColorMode(e){let
colorMode=e.getAttribute('data-app-color-mode');localStorage.setItem('shpAppColorMode',colorMode);this.doColorMode(colorMode);return 0;}initColorMode(firstCall){if(firstCall){window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change',function(){this.initColorMode()}.bind(this));}let
colorMode=localStorage.getItem('shpAppColorMode');if(!colorMode||colorMode==='auto'){const
isSystemDarkMode=window.matchMedia('(prefers-color-scheme: dark)').matches;if(isSystemDarkMode)colorMode='dark';else
colorMode='light';}this.doColorMode(colorMode);}doColorMode(colorMode){if(colorMode==='light'){document.body.removeAttribute('data-bs-theme');}else
if(colorMode==='dark'){document.body.setAttribute('data-bs-theme','dark');}else
if(colorMode==='auto'){this.initColorMode();}var
uiColorMode=colorMode;let
savedColorMode=localStorage.getItem('shpAppColorMode');if(!savedColorMode||savedColorMode==='auto')uiColorMode='auto';let
colorModeElements=document.querySelectorAll('[data-action="setColorMode"]');for(let
idx=0;idx<colorModeElements.length;idx++){if(colorModeElements[idx].getAttribute('data-app-color-mode')===uiColorMode)colorModeElements[idx].classList.add('active');else
colorModeElements[idx].classList.remove('active');}}elementPrefixedAttributes(iel,prefix,data){for(var
i=0,attrs=iel.attributes,l=attrs.length;i<l;i++){var
attrName=attrs.item(i).nodeName;if(attrName.substring(0,prefix.length)!==prefix)continue;var
attrNameShort=attrName.substring(prefix.length);var
val=attrs.item(i).nodeValue;data[attrNameShort]=val;}}init(){this.server.setHttpServerRoot(httpApiRootPath);this.initColorMode(true);this.onClick('a.shp-simple-tabs-item',function(){shc.simpleTabsEvent(this);});this.onClick('.shp-app-action',function(e){this.widgetAction(e);}.bind(this));}}class
ShipardClientIoT{camPictLoader=null;init(){if(uiData['iotCamServers']!==undefined){this.camPictLoader=new
ShipardCamsPictsLoader();this.camPictLoader.init();}shc.on('change','input.mac-shp-triggger',function(){shc.iot.mainTrigger(this);});}mainTrigger(element){let
payload={};if(element.classList.contains('shp-iot-scene-switch')){let
attrSetupSID=element.getAttribute('data-shp-iot-setup');if(attrSetupSID===undefined){console.log("unknown iot setup");return;}let
attrSetupSceneId=element.getAttribute('data-shp-scene-id');if(attrSetupSceneId===undefined){console.log("unknown iot setup scene id");return;}payload['scene']=attrSetupSceneId;let
setTopic=uiData['iotSubjects'][attrSetupSID]['topic']+'/set';shc.mqtt.publish(uiData['iotSubjects'][attrSetupSID]['wss'],setTopic,JSON.stringify(payload));return;}if(element.classList.contains('shp-iot-primary-switch')||element.classList.contains('shp-iot-group-switch')){let
propertyId=element.getAttribute('data-shp-iot-state-id');if(propertyId===null)propertyId='state';payload[propertyId]=element.checked?'ON':'OFF';}else
if(element.classList.contains('shp-iot-br-range'))payload['brightness']=element.value;else
if(element.classList.contains('shp-iot-ct-range'))payload['color_temp']=element.value;if(Object.keys(payload).length===0&&payload.constructor===Object)return;let
attrDeviceSID=element.getAttribute('data-shp-iot-device');if(attrDeviceSID===undefined){console.log("unknown iot device");return;}let
deviceSIDs=attrDeviceSID.split(',');for(const
deviceSID
of
deviceSIDs){if(uiData['iotSubjects']===undefined||uiData['iotSubjects'][deviceSID]===undefined){console.log("Invalid device SID: ",deviceSID);continue;}let
setTopic=uiData['iotSubjects'][deviceSID]['topic']+'/set';shc.mqtt.publish(uiData['iotSubjects'][deviceSID]['wss'],setTopic,JSON.stringify(payload));}}initIoT(){shc.mqtt.init();shc.iot.init();}}var
shc=new
ShipardClient();document.addEventListener('DOMContentLoaded',()=>shc.init());