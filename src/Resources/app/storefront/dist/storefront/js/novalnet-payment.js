(window.webpackJsonp=window.webpackJsonp||[]).push([["novalnet-payment"],{JXk0:function(e,t,n){"use strict";(function(e){n.d(t,"a",(function(){return s}));var a=n("FGIj"),o=n("k8s9"),l=n("477Q");function r(e){return(r="function"==typeof Symbol&&"symbol"==typeof Symbol.iterator?function(e){return typeof e}:function(e){return e&&"function"==typeof Symbol&&e.constructor===Symbol&&e!==Symbol.prototype?"symbol":typeof e})(e)}function u(e,t){if(!(e instanceof t))throw new TypeError("Cannot call a class as a function")}function i(e,t){for(var n=0;n<t.length;n++){var a=t[n];a.enumerable=a.enumerable||!1,a.configurable=!0,"value"in a&&(a.writable=!0),Object.defineProperty(e,a.key,a)}}function c(e,t){return!t||"object"!==r(t)&&"function"!=typeof t?function(e){if(void 0===e)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return e}(e):t}function d(e){return(d=Object.setPrototypeOf?Object.getPrototypeOf:function(e){return e.__proto__||Object.getPrototypeOf(e)})(e)}function m(e,t){return(m=Object.setPrototypeOf||function(e,t){return e.__proto__=t,e})(e,t)}var s=function(t){function n(){return u(this,n),c(this,d(n).apply(this,arguments))}var a,r,s;return function(e,t){if("function"!=typeof t&&null!==t)throw new TypeError("Super expression must either be null or a function");e.prototype=Object.create(t&&t.prototype,{constructor:{value:e,writable:!0,configurable:!0}}),t&&m(e,t)}(n,t),a=n,(r=[{key:"init",value:function(){var e=this;this.client=new o.a;var t=document.querySelectorAll(".novalnet-payment-name"),n=document.querySelector("#nnShopVersion").value,a=["novalnetsepa","novalnetsepaguarantee","novalnetsepainstalment"],r=["novalnetinvoiceguarantee","novalnetinvoiceinstalment"];t.forEach((function(t){var o=document.querySelector("input[name=paymentMethodId]:checked"),u=document.querySelector("#"+t.value+"Id"),i=document.querySelector('input[type="radio"].'+t.value+"-SavedPaymentMethods-tokenInput:checked"),c=document.querySelectorAll('input[type="radio"].'+t.value+"-SavedPaymentMethods-tokenInput"),d=document.querySelectorAll('input[name="paymentMethodId"]');if(null!=o&&void 0!==u&&o.value===u.value&&(void 0!==document.getElementById(t.value+"PaymentNotification")&&null!==document.getElementById(t.value+"PaymentNotification")&&(document.getElementById(t.value+"PaymentNotification").style.display="block"),void 0!==document.getElementById(t.value+"ZeroAmountNotify")&&null!==document.getElementById(t.value+"ZeroAmountNotify")&&(document.getElementById(t.value+"ZeroAmountNotify").style.display="block"),null!=document.getElementById(t.value+"HideButton")&&1==document.getElementById(t.value+"HideButton").value&&e._disableSubmitButton(),null!=document.getElementById(t.value+"-payment")&&null!=document.getElementById(t.value+"-payment")&&(document.getElementById(t.value+"-payment").style.display="block")),null!=i&&e.showComponents(i,t.value),n>="6.4")var m=document.querySelector('#confirmOrderForm button[type="submit"]');else m=document.querySelector('#confirmPaymentForm button[type="submit"]');if("novalnetcreditcard"==t.value)e._createScript((function(){var n=JSON.parse(document.getElementById(t.value+"-payment").getAttribute("data-"+t.value+"-payment-config"));e.loadIframe(n,t.value)}));else if(a.includes(t.value)||r.includes(t.value)){e._createScript((function(){var e=JSON.parse(document.getElementById(t.value+"-payment").getAttribute("data-"+t.value+"-payment-config"));("novalnetsepa"!=t.value&&null===e.company||!NovalnetUtility.isValidCompanyName(e.company))&&(document.getElementById(t.value+"DobField").style.display="block")}));var s=document.getElementById(t.value+"AccountData");null!=s&&["click","paste","keydown","keyup"].forEach((function(e){s.addEventListener(e,(function(e){var t=e.target.value;null!=t&&""!=t&&((t=t.toUpperCase()).match(/(?:CH|MC|SM|GB)/)?document.querySelector(".nn-bic-field").classList.remove("nnhide"):document.querySelector(".nn-bic-field").classList.add("nnhide"))}))}))}var y=document.querySelectorAll(".remove_cc_card_details"),v=document.querySelectorAll(".remove_card_details"),p=document.querySelectorAll(".remove_guarantee_card_details "),f=document.querySelectorAll(".remove_instalment_card_details");"novalnetcreditcard"===t.value?y.forEach((function(n){n.addEventListener("click",(function(){e.removeStoredCard(n,t.value)}))})):"novalnetsepa"===t.value?v.forEach((function(n){n.addEventListener("click",(function(){e.removeStoredCard(n,t.value)}))})):"novalnetsepaguarantee"===t.value?p.forEach((function(n){n.addEventListener("click",(function(){e.removeStoredCard(n,t.value)}))})):"novalnetsepainstalment"===t.value&&f.forEach((function(n){n.addEventListener("click",(function(){e.removeStoredCard(n,t.value)}))})),"novalnetinvoiceinstalment"!==t.value&&"novalnetsepainstalment"!==t.value||(null!=document.getElementById(t.value+"Duration")&&(document.getElementById(t.value+"Duration").selectedIndex=0,document.getElementById(t.value+"Duration").addEventListener("change",(function(e){var n=e.target.value;document.querySelectorAll("."+t.value+"Detail").forEach((function(e){e.dataset.duration===n?e.hidden=!1:e.hidden="hidden"}))}))),null!=document.getElementById(t.value+"Info")&&document.getElementById(t.value+"Info").addEventListener("click",(function(n){e.hideSummary(t.value)}))),null!=c&&c.forEach((function(n,a){n.addEventListener("click",(function(){e.showComponents(n,t.value)}))}));var g=!0;d.forEach((function(n){!0===n.checked&&(g=!1),n.addEventListener("click",(function(){e.showPaymentForm(n,t.value)}))})),1==g&&e._disableSubmitButton(),null!=m&&m.addEventListener("click",(function(o){var u=document.querySelector("#"+t.value+"Id"),i=document.querySelector("input[name=paymentMethodId]:checked"),c=document.querySelector('input[type="radio"].'+t.value+"-SavedPaymentMethods-tokenInput:checked");if("novalnetcreditcard"==t.value){if(void 0!==u&&""!==u.value&&i.value===u.value&&(null==c||"new"==c.value)){var d=document.querySelector("#tos");if(null!=d&&!d.checked)return!1;if(null!=document.getElementById("confirmFormSubmit"))document.getElementById("confirmFormSubmit").disabled=!0,new l.a(document.getElementById("confirmFormSubmit")).create();else{var m=document.querySelector('#confirmOrderForm button[type="submit"]');m.disabled=!0,new l.a(m).create()}o.preventDefault(),o.stopImmediatePropagation(),u.scrollIntoView(),NovalnetUtility.getPanHash()}}else if(a.includes(t.value)){var s=document.getElementById(t.value+"AccountData"),y=document.getElementById(t.value+"AccountBic"),v=document.getElementById(t.value+"Dob"),p=JSON.parse(document.getElementById(t.value+"-payment").getAttribute("data-"+t.value+"-payment-config"));if(void 0!==u&&""!==u.value&&i.value===u.value)if(void 0!==s&&""!==s.value||null!=c&&"new"!=c.value)if(void 0!==y&&""!==y.value||0!=document.querySelector(".nn-bic-field").classList.contains("nnhide")||null!=c&&"new"!=c.value){if("novalnetsepainstalment"===t.value&&(null===p.company||!NovalnetUtility.isValidCompanyName(p.company))||"novalnetsepaguarantee"===t.value&&(null===p.company||!NovalnetUtility.isValidCompanyName(p.company)||0===p.allowB2B))if(void 0===v||""===v.value){if(void 0!==document.getElementById("novalnetsepaId")&&null!=p.forceGuarantee&&1==p.forceGuarantee&&"novalnetsepaguarantee"===t.value)return n<"6.4"&&(i.value=document.getElementById("novalnetsepaId").value),document.getElementById("doForceSepaPayment").value=1,document.getElementById("SepaForcePayment").value=1,!0;e.preventForm(v,t.value,p.text.dobEmpty)}else if(void 0!==v&&""!==v.value){var f=e.validateAge(v.value);if((f<18||isNaN(f))&&void 0!==document.getElementById("novalnetsepaId")&&null!=p.forceGuarantee&&1==p.forceGuarantee&&"novalnetsepaguarantee"===t.value)return n<"6.4"&&(i.value=document.getElementById("novalnetsepaId").value),document.getElementById("doForceSepaPayment").value=1,document.getElementById("SepaForcePayment").value=1,!0;(f<18||isNaN(f))&&e.preventForm(v,t.value,p.text.dobInvalid)}}else e.preventForm(y,t.value,p.text.invalidIban);else e.preventForm(s,t.value,p.text.invalidIban)}else if(r.includes(t.value)){var g=document.getElementById(t.value+"Dob"),I=JSON.parse(document.getElementById(t.value+"-payment").getAttribute("data-"+t.value+"-payment-config"));if(void 0!==u.value&&""!==u.value&&i.value===u.value&&(null===I.company||!NovalnetUtility.isValidCompanyName(I.company)||0===I.allowB2B))if(void 0===g||""===g.value){if(void 0!==document.getElementById("novalnetinvoiceId")&&null!=I.forceGuarantee&&1==I.forceGuarantee&&"novalnetinvoiceguarantee"===t.value)return n<"6.4"&&(i.value=document.getElementById("novalnetinvoiceId").value),document.getElementById("doForceInvoicePayment").value=1,document.getElementById("InvoiceForcePayment").value=1,!0;e.preventForm(g,t.value,I.text.dobEmpty)}else if(void 0!==g&&""!==g.value){var b=e.validateAge(g.value);if((b<18||isNaN(b))&&void 0!==document.getElementById("novalnetinvoiceId")&&null!=I.forceGuarantee&&1==I.forceGuarantee&&"novalnetinvoiceguarantee"===t.value)return n<"6.4"&&(i.value=document.getElementById("novalnetinvoiceId").value),document.getElementById("doForceInvoicePayment").value=1,document.getElementById("InvoiceForcePayment").value=1,!0;(b<18||isNaN(b))&&e.preventForm(g,t.value,I.text.dobInvalid)}}}))}))}},{key:"_createScript",value:function(e){var t=document.createElement("script");t.type="text/javascript",t.src="https://cdn.novalnet.de/js/v2/NovalnetUtility.js",t.addEventListener("load",e.bind(this),!1),document.head.appendChild(t)}},{key:"loadIframe",value:function(e,t){if("novalnetcreditcard"===t){if(NovalnetUtility.setClientKey(e.clientKey),document.querySelector("#nnShopVersion").value>="6.4")var n=document.querySelector("#confirmOrderForm");else n=document.querySelector("#confirmPaymentForm");var a={callback:{on_success:function(e){return document.getElementById("novalnetcreditcard-panhash").value=e.hash,document.getElementById("novalnetcreditcard-uniqueid").value=e.unique_id,document.getElementById("novalnetcreditcard-doRedirect").value=e.do_redirect,null!=e.card_exp_month&&null!=e.card_exp_year&&(document.getElementById("novalnetcreditcard-expiry-date").value=e.card_exp_month+"/"+e.card_exp_year),document.getElementById("novalnetcreditcard-masked-card-no").value=e.card_number,document.getElementById("novalnetcreditcard-card-type").value=e.card_type,n.disabled=!1,n.submit(),!0},on_error:function(e){var t=document.getElementById("novalnetcreditcard-error-container"),a=t.querySelector(".alert-content");if(a.innerHTML="",void 0!==e.error_message&&""!==e.error_message){if(n.disabled=!1,null!=document.getElementById("confirmFormSubmit"))document.getElementById("confirmFormSubmit").disabled=!1,new l.a(document.getElementById("confirmFormSubmit")).remove();else{var o=document.querySelector('#confirmOrderForm button[type="submit"]');o.disabled=!1,new l.a(o).remove()}a.innerHTML=e.error_message,t.style.display="block",t.scrollIntoView()}else t.style.display="none";return!1},on_show_overlay:function(e){document.getElementById("novalnetCreditcardIframe").classList.add("novalnet-challenge-window-overlay")},on_hide_overlay:function(e){document.getElementById("novalnetCreditcardIframe").classList.remove("novalnet-challenge-window-overlay")},on_show_captcha:function(){return elementContent.innerHTML="Your Credit Card details are Invalid",element.style.display="block",element.scrollIntoView(),elementContent.focus(),!1}},iframe:e.iframe,customer:e.customer,transaction:e.transaction,custom:e.custom};NovalnetUtility.createCreditCardForm(a)}}},{key:"showComponents",value:function(e,t){"new"===e.value&&!0===e.checked?document.getElementById(t+"-payment-form").classList.remove("nnhide"):document.getElementById(t+"-payment-form").classList.add("nnhide")}},{key:"hideSummary",value:function(e){var t=document.getElementById(e+"Summary");t.classList.contains("nnhide")?t.classList.remove("nnhide"):t.classList.add("nnhide")}},{key:"showPaymentForm",value:function(e,t){var n=document.querySelector("#"+t+"Id");void 0!==n&&""!==n.value&&e.value===n.value?("novalnetcreditcard"==t&&NovalnetUtility.setCreditCardFormHeight(),void 0!==document.getElementById(t+"-payment")&&null!==document.getElementById(t+"-payment")&&(document.getElementById(t+"-payment").style.display="block"),void 0!==document.getElementById(t+"PaymentNotification")&&null!==document.getElementById(t+"PaymentNotification")&&(document.getElementById(t+"PaymentNotification").style.display="block"),void 0!==document.getElementById(element.value+"ZeroAmountNotify")&&null!==document.getElementById(element.value+"ZeroAmountNotify")&&(document.getElementById(element.value+"ZeroAmountNotify").style.display="block")):(void 0!==document.getElementById(t+"-payment")&&null!==document.getElementById(t+"-payment")&&(document.getElementById(t+"-payment").style.display="none"),void 0!==document.getElementById(t+"PaymentNotification")&&null!==document.getElementById(t+"PaymentNotification")&&(document.getElementById(t+"PaymentNotification").style.display="none"),void 0!==document.getElementById(element.value+"ZeroAmountNotify")&&null!==document.getElementById(element.value+"ZeroAmountNotify")&&(document.getElementById(element.value+"ZeroAmountNotify").style.display="none"))}},{key:"removeStoredCard",value:function(t,n){var a=document.querySelector('input[name="'+n+'FormData[paymentToken]"]:checked');null!=a&&""!=a&&(this.client.post(e("#cardRemoveUrl").val(),JSON.stringify({token:a.value}),""),setTimeout((function(){return window.location.reload()}),2e3))}},{key:"_disableSubmitButton",value:function(){var e=document.querySelector("#confirmOrderForm button");e&&e.setAttribute("disabled","disabled")}},{key:"validateAge",value:function(e){var t=new Date;if(void 0===e||""===e)return NaN;var n=e.split("."),a=t.getFullYear()-n[2],o=t.getMonth()-n[1];return((o+=1)<0||"0"==o&&t.getDate()<n[0])&&a--,a}},{key:"preventForm",value:function(e,t,n){e.style.borderColor="red";var a=document.getElementById(t+"-error-container");event.preventDefault(),event.stopImmediatePropagation(),a.scrollIntoView();var o=a.querySelector(".alert-content");return o.innerHTML="",void 0!==n&&""!==n?(o.innerHTML=n,a.style.display="block",a.scrollIntoView()):a.style.display="none",!1}}])&&i(a.prototype,r),s&&i(a,s),n}(a.a)}).call(this,n("UoTJ"))},qseZ:function(e,t,n){"use strict";n.r(t);var a=n("JXk0"),o=n("FGIj"),l=n("k8s9"),r=n("gHbT");function u(e){return(u="function"==typeof Symbol&&"symbol"==typeof Symbol.iterator?function(e){return typeof e}:function(e){return e&&"function"==typeof Symbol&&e.constructor===Symbol&&e!==Symbol.prototype?"symbol":typeof e})(e)}function i(e,t){if(!(e instanceof t))throw new TypeError("Cannot call a class as a function")}function c(e,t){for(var n=0;n<t.length;n++){var a=t[n];a.enumerable=a.enumerable||!1,a.configurable=!0,"value"in a&&(a.writable=!0),Object.defineProperty(e,a.key,a)}}function d(e,t){return!t||"object"!==u(t)&&"function"!=typeof t?function(e){if(void 0===e)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return e}(e):t}function m(e){return(m=Object.setPrototypeOf?Object.getPrototypeOf:function(e){return e.__proto__||Object.getPrototypeOf(e)})(e)}function s(e,t){return(s=Object.setPrototypeOf||function(e,t){return e.__proto__=t,e})(e,t)}var y=function(e){function t(){return i(this,t),d(this,m(t).apply(this,arguments))}var n,a,o;return function(e,t){if("function"!=typeof t&&null!==t)throw new TypeError("Super expression must either be null or a function");e.prototype=Object.create(t&&t.prototype,{constructor:{value:e,writable:!0,configurable:!0}}),t&&s(e,t)}(t,e),n=t,(a=[{key:"init",value:function(){var e=this;new l.a,this._createScript((function(){try{r.a.querySelectorAll(document,"[data-wallet-payments]"),e._loadWalletPaymentForm()}catch(e){console.log(e.message)}}))}},{key:"_createScript",value:function(e){var t=document.createElement("script");t.type="text/javascript",t.src="https://cdn.novalnet.de/js/v3/payment.js",t.addEventListener("load",e.bind(this),!1),document.head.appendChild(t)}},{key:"_loadWalletPaymentForm",value:function(){var e=NovalnetPayment().createPaymentObject(),t=this.el,n=r.a.getDataAttribute(this.el,"data-payment-config",!1),a=r.a.querySelector(document,"#nnShopVersion"),o=new l.a;if(null!=t&&("nn-apple-pay-button"==t.className||t.className.indexOf("nn-apple-pay-button")>=0))var u="novalnetapplepay";else u="novalnetgooglepay";var i={clientKey:n.clientKey,paymentIntent:{merchant:n.merchant,transaction:n.transaction,order:n.order,custom:n.custom,button:n.button,callbacks:{onProcessCompletion:function(e,n){if(e.result&&e.result.status)if("SUCCESS"==e.result.status){if("checkoutPage"==r.a.getDataAttribute(t,"data-page-type")){if(n({status:"SUCCESS",statusText:""}),null!=a&&a.value>="6.4")var l=r.a.querySelector(document,"#confirmOrderForm");else l=r.a.querySelector(document,"#confirmPaymentForm");if(null!=t&&"nn-apple-pay-button"==t.className)document.getElementById("novalnetapplepay-wallet-token").value=e.transaction.token,l.submit();else if(document.getElementById("novalnetgooglepay-wallet-token").value=e.transaction.token,document.getElementById("novalnetgooglepay-do-redirect").value=e.transaction.doRedirect,e.transaction.doRedirect&&0!=e.transaction.doRedirect){var i=r.a.getAttribute(t,"data-success-url"),c={serverResponse:e,paymentMethodId:r.a.getAttribute(t,"data-paymentMethodId"),paymentName:u};o.post(i,JSON.stringify(c),(function(e){var t=JSON.parse(e);1==t.success?(n({status:"SUCCESS",statusText:""}),window.location.replace(t.url)):window.location.reload()}))}else l.submit();return!0}var d=r.a.getAttribute(t,"data-success-url"),m={serverResponse:e,paymentMethodId:r.a.getAttribute(t,"data-paymentMethodId"),paymentName:u};o.post(d,JSON.stringify(m),(function(e){var t=JSON.parse(e);1==t.success?(n({status:"SUCCESS",statusText:""}),window.location.replace(t.url)):window.location.reload()}))}else n({status:"FAILURE",statusText:e.result.status_text}),e.result.status_text&&alert(e.result.status_text)},onShippingContactChange:function(e,n){var a=r.a.getDataAttribute(t,"data-shipping-url"),l={shippingInfo:e,paymentMethodId:r.a.getAttribute(t,"data-paymentMethodId")};return new Promise((function(e,t){o.post(a,JSON.stringify(l),(function(n,a){a.status>=400&&t(n);try{n=JSON.parse(n),e(n)}catch(e){t(e)}}))})).then((function(e){e.shipping.length?n({amount:e.totalPrice,lineItems:e.lineItem,methods:e.shipping,defaultIdentifier:e.shipping[0].identifier}):n({methodsNotFound:"No Shipping Contact Available, please enter a valid contact"})}))},onShippingMethodChange:function(e,n){var a=r.a.getAttribute(t,"data-shippingUpdate-url"),l={shippingMethod:e};return new Promise((function(e,t){o.post(a,JSON.stringify(l),(function(a,o){o.status>=400&&(t(a),n({status:"FAILURE"}));try{a=JSON.parse(a),e(a)}catch(e){t(e),n({status:"FAILURE"})}}))})).then((function(e){n({amount:e.totalPrice,lineItems:e.lineItem})}))},onPaymentButtonClicked:function(e){var n=document.querySelector("#tos");if(null!=n&&!n.checked&&"checkoutPage"==r.a.getDataAttribute(t,"data-page-type")){if(null!=a&&a.value>="6.4")var l=r.a.querySelector(document,"#confirmOrderForm");else l=r.a.querySelector(document,"#confirmPaymentForm");return e({status:"FAILURE"}),l.submit(),!1}if("productDetailPage"==r.a.getDataAttribute(t,"data-page-type")||"productListingPage"==r.a.getDataAttribute(t,"data-page-type")){e({status:"SUCCESS"});var u=1,i=r.a.querySelector(t,"#productId").value,c=document.querySelector(".product-detail-quantity-select"),d=r.a.getDataAttribute(t,"data-addToCartUrl",!1);null!=c&&(u=c.value),o.post(d,JSON.stringify({productId:i,quantity:u,type:"product"}),(function(e){var t=JSON.parse(e);0==t.success&&window.location.replace(t.url)}))}else e({status:"SUCCESS"})}}}};e.setPaymentIntent(i),e.isPaymentMethodAvailable((function(n){void 0!==t&&n&&("nn-apple-pay-button"==t.className&&void 0!==document.querySelectorAll(".nn-apple-pay-error-message")&&null!==document.querySelectorAll(".nn-apple-pay-error-message")&&document.querySelectorAll(".nn-apple-pay-error-message").forEach((function(e){e.remove()})),e.addPaymentButton("."+t.className),null!=t.querySelector("button")?t.querySelector("button").style.width="100%":null!=t.querySelector("apple-pay-button")&&(t.querySelector("apple-pay-button").style.width="100%"))}))}}])&&c(n.prototype,a),o&&c(n,o),t}(o.a),v=window.PluginManager;v.register("NovalnetPayment",a.a,"#novalnet-payment-script"),v.register("NovalnetWalletPayment",y,"[data-wallet-payments]")}},[["qseZ","runtime","vendor-node","vendor-shared"]]]);