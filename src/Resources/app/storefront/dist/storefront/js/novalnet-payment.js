"use strict";(self.webpackChunk=self.webpackChunk||[]).push([["novalnet-payment"],{2887:(e,t,n)=>{var l=n(6285),r=n(5659),o=n(7606),a=n(8254),c=n(1110),u=n(207),m=n(3206);class d extends l.Z{init(){this._createScript((function(){let e=document.querySelectorAll('input[name="paymentMethodId"]'),t=document.querySelector("input[name=paymentMethodId]:checked"),n=document.querySelector("#novalnetId"),l=document.querySelectorAll('#confirmOrderForm button[type="submit"]'),d=document.querySelector('#novalnetchangePaymentForm button[type="submit"]'),s=document.querySelectorAll('form[action$="/account/payment"]'),i=document.querySelector('form[action$="/account/payment"]'),y=this,p=new NovalnetPaymentForm,f="novalnetpayNameCookie",g=new a.Z,v=o.Z.getItem(f),h=!0,S=m.Z.getDataAttribute(y.el,"data-lineitems",!1);if(p.addSkeleton("#novalnetPaymentIframe"),null!=n){if(null==t||null==t){document.querySelector("#paymentMethod"+n.value).checked=!0,t=document.querySelector("input[name=paymentMethodId]:checked");const e=u.Z.serialize(t.closest("form")),l=t.closest("form").getAttribute("action");g.post(l,e,(e=>{window.PluginManager.initializePlugins()}))}n.value===t.value&&(h=!1,null!=l&&l.forEach((function(e){e.disabled=!0})));let a={iframe:"#novalnetPaymentIframe",initForm:{orderInformation:{lineItems:S},showButton:!1,uncheckPayments:h}};0==o.Z.getItem(f)||null==o.Z.getItem(f)||h||n.value!==t.value||(a.initForm.checkPayment=v),1==s.length&&(a.initForm.styleText={forceStyling:{text:".payment-type-container > .payment-type > .payment-form{display: none !important;}"}}),p.initiate(a);var b=!1;p.validationResponse((e=>{b=!0,p.initiate(a),null!=l&&l.forEach((function(e){e.disabled=!1}))})),e.forEach((function(e){b&&!0===e.checked&&p.uncheckPayment()})),p.selectedPayment((function(e){if(o.Z.setItem(f,e.payment_details.type),null!=document.getElementById("confirmOrderForm")&&("GOOGLEPAY"==e.payment_details.type||"APPLEPAY"==e.payment_details.type?document.getElementById("confirmOrderForm").style.display="none":document.getElementById("confirmOrderForm").style.display="block"),0==s.length&&null==d){if(n.value!==t.value){r.Z.create(),document.querySelector("#paymentMethod"+n.value).checked=!0;const e=u.Z.serialize(t.closest("form")),l=t.closest("form").getAttribute("action");g.post(l,e,(e=>{r.Z.remove(),window.PluginManager.initializePlugins(),document.querySelector("#novalnetId").closest("form").submit()}))}}else document.querySelector("#paymentMethod"+n.value).checked=!0})),1!=s.length&&null==d||document.querySelectorAll('input[name="paymentMethodId"]').forEach((e=>e.addEventListener("click",(t=>{e.value!=n.value&&p.uncheckPayment()})))),p.walletResponse({onProcessCompletion:function(e){return"SUCCESS"==e.result.status?(null!=d&&null!=d?(null!=document.getElementById("parentOrderNumber")&&(e.parentOrderNumber=document.getElementById("parentOrderNumber").value,e.aboId=document.getElementById("aboId").value,e.paymentMethodId=n.value),g.post(document.getElementById("storeCustomerDataUrl").value,JSON.stringify(e),(e=>{const t=JSON.parse(e);if(1==t.success&&null!=t.redirect_url)window.location.replace(t.redirect_url);else{if(1!=t.success)return y._displayErrorMsgSubs(t.message),{status:"FAILURE",statusText:"failure"};document.querySelector("#novalnetchangePaymentForm").submit()}}))):(document.querySelector("#novalnet-paymentdata").value=JSON.stringify(e),setTimeout((function(){document.querySelector("#confirmOrderForm").submit()}),500)),{status:"SUCCESS",statusText:"successfull"}):{status:"FAILURE",statusText:"failure"}}}),null!=l&&null!=l&&l.forEach((function(e){e.addEventListener("click",(e=>{if(n.value===t.value){let t=document.querySelector("#tos"),n=document.querySelector("#revocation");if(null!=t&&!t.checked&&null!=n&&!n.checked)return!1;if(null!=document.getElementById("confirmFormSubmit")){document.getElementById("confirmFormSubmit").disabled=!0;new c.Z(document.getElementById("confirmFormSubmit")).create()}else{let e=document.querySelector('#confirmOrderForm button[type="submit"]');e.disabled=!0;new c.Z(e).create()}e.preventDefault(),e.stopImmediatePropagation(),p.getPayment((function(e){"100"==e.result.statusCode||"SUCCESS"==e.result.status?(document.querySelector("#novalnet-paymentdata").value=JSON.stringify(e),document.querySelector("#confirmOrderForm").submit()):(document.querySelector("#novalnet-paymentdata").value="",y._displayErrorMsg(e.result.message),y._showSubmitForm())}))}}))})),null!=d&&null!=d&&d.addEventListener("click",(e=>{document.querySelector('input[name="paymentMethodId"]:checked').value==n.value&&(e.preventDefault(),e.stopImmediatePropagation(),p.getPayment((function(e){if("100"!=e.result.statusCode&&"SUCCESS"!=e.result.status)return y._displayErrorMsgSubs(e.result.message),!1;{const t=new c.Z(d);t.create(),null!=document.getElementById("parentOrderNumber")&&(e.parentOrderNumber=document.getElementById("parentOrderNumber").value,e.aboId=document.getElementById("aboId").value,e.paymentMethodId=n.value),g.post(document.getElementById("storeCustomerDataUrl").value,JSON.stringify(e),(e=>{const n=JSON.parse(e);if(1==n.success&&null!=n.redirect_url)window.location.replace(n.redirect_url);else{if(1!=n.success)return y._displayErrorMsgSubs(n.message),t.remove(),!1;document.querySelector("#novalnetchangePaymentForm").submit()}}))}})))})),null!=i&&null!=i&&i.addEventListener("submit",(e=>{if(document.querySelector('input[name="paymentMethodId"]:checked').value==n.value){const e=u.Z.serialize(t.closest("form")),n=t.closest("form").getAttribute("action");g.post(n,e,(e=>{r.Z.remove(),window.PluginManager.initializePlugins()}))}}))}}))}_createScript(e){const t="https://cdn.novalnet.de/js/pv13/checkout.js?"+(new Date).getTime(),n=document.createElement("script");n.type="text/javascript",n.src=t,n.addEventListener("load",e.bind(this),!1),document.head.appendChild(n)}_displayErrorMsg(e){document.querySelector(".flashbags").innerHTML="";let t=document.createElement("div"),n=document.createElement("div"),l=document.createElement("div"),r=document.createElement("span");t.className="alert alert-danger alert-has-icon",n.className="alert-content-container",l.className="alert-content",r.className="icon icon-blocked",r.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="24" height="24" viewBox="0 0 24 24"><defs><path d="M12 24C5.3726 24 0 18.6274 0 12S5.3726 0 12 0s12 5.3726 12 12-5.3726 12-12 12zm0-2c5.5228 0 10-4.4772 10-10S17.5228 2 12 2 2 6.4772 2 12s4.4772 10 10 10zm4.2929-15.7071c.3905-.3905 1.0237-.3905 1.4142 0 .3905.3905.3905 1.0237 0 1.4142l-10 10c-.3905.3905-1.0237.3905-1.4142 0-.3905-.3905-.3905-1.0237 0-1.4142l10-10z" id="icons-default-blocked"></path></defs><use xlink:href="#icons-default-blocked" fill="#758CA3" fill-rule="evenodd"></use></svg>',t.appendChild(r),t.appendChild(n),n.appendChild(l),l.innerHTML=e,document.querySelector(".flashbags").appendChild(t),document.querySelector(".flashbags").scrollIntoView()}_displayErrorMsgSubs(e){const t=document.getElementsByClassName("alert alert-danger alert-has-icon");for(;t.length>0;)t[0].parentNode.removeChild(t[0]);var n=document.getElementById("novalnetchangePaymentForm");let l=document.createElement("div"),r=document.createElement("div"),o=document.createElement("div"),a=document.createElement("span");l.className="alert alert-danger alert-has-icon",r.className="alert-content-container",o.className="alert-content",a.className="icon icon-blocked",a.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="24" height="24" viewBox="0 0 24 24"><defs><path d="M12 24C5.3726 24 0 18.6274 0 12S5.3726 0 12 0s12 5.3726 12 12-5.3726 12-12 12zm0-2c5.5228 0 10-4.4772 10-10S17.5228 2 12 2 2 6.4772 2 12s4.4772 10 10 10zm4.2929-15.7071c.3905-.3905 1.0237-.3905 1.4142 0 .3905.3905.3905 1.0237 0 1.4142l-10 10c-.3905.3905-1.0237.3905-1.4142 0-.3905-.3905-.3905-1.0237 0-1.4142l10-10z" id="icons-default-blocked"></path></defs><use xlink:href="#icons-default-blocked" fill="#758CA3" fill-rule="evenodd"></use></svg>',l.appendChild(a),l.appendChild(r),r.appendChild(o),o.innerHTML=e,n.parentNode.insertBefore(l,n),l.scrollIntoView()}_showSubmitForm(){if(null!=document.getElementById("confirmFormSubmit")){document.getElementById("confirmFormSubmit").disabled=!1;new c.Z(document.getElementById("confirmFormSubmit")).remove()}else{let e=document.querySelector('#confirmOrderForm button[type="submit"]');e.disabled=!1;new c.Z(e).remove()}}}window.PluginManager.register("NovalnetPayment",d,"#novalnet-payment-script")}},e=>{e.O(0,["vendor-node","vendor-shared"],(()=>{return t=2887,e(e.s=t);var t}));e.O()}]);