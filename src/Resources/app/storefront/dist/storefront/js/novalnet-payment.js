(window.webpackJsonp=window.webpackJsonp||[]).push([["novalnet-payment"],{jJSA:function(e,t,n){"use strict";(function(e){n.d(t,"a",(function(){return d}));var o=n("FGIj"),a=n("k8s9");function l(e){return(l="function"==typeof Symbol&&"symbol"==typeof Symbol.iterator?function(e){return typeof e}:function(e){return e&&"function"==typeof Symbol&&e.constructor===Symbol&&e!==Symbol.prototype?"symbol":typeof e})(e)}function r(e,t){for(var n=0;n<t.length;n++){var o=t[n];o.enumerable=o.enumerable||!1,o.configurable=!0,"value"in o&&(o.writable=!0),Object.defineProperty(e,o.key,o)}}function u(e,t){return!t||"object"!==l(t)&&"function"!=typeof t?function(e){if(void 0===e)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return e}(e):t}function i(e){return(i=Object.setPrototypeOf?Object.getPrototypeOf:function(e){return e.__proto__||Object.getPrototypeOf(e)})(e)}function c(e,t){return(c=Object.setPrototypeOf||function(e,t){return e.__proto__=t,e})(e,t)}var d=function(t){function n(){return function(e,t){if(!(e instanceof t))throw new TypeError("Cannot call a class as a function")}(this,n),u(this,i(n).apply(this,arguments))}var o,l,d;return function(e,t){if("function"!=typeof t&&null!==t)throw new TypeError("Super expression must either be null or a function");e.prototype=Object.create(t&&t.prototype,{constructor:{value:e,writable:!0,configurable:!0}}),t&&c(e,t)}(n,t),o=n,(l=[{key:"init",value:function(){var e=this;this.client=new a.a;var t=document.querySelectorAll(".novalnet-payment-name"),n=document.querySelector("#nnShopVersion").value,o=["novalnetsepa","novalnetsepaguarantee","novalnetsepainstalment"],l=["novalnetinvoiceguarantee","novalnetinvoiceinstalment"];t.forEach((function(t){var a=document.querySelector("input[name=paymentMethodId]:checked"),r=document.querySelector("#"+t.value+"Id"),u=document.querySelector('input[type="radio"].'+t.value+"-SavedPaymentMethods-tokenInput:checked"),i=document.querySelectorAll('input[type="radio"].'+t.value+"-SavedPaymentMethods-tokenInput"),c=document.querySelectorAll('input[name="paymentMethodId"]');if(null!=a&&void 0!==r&&a.value===r.value&&(void 0!==document.getElementById(t.value+"PaymentNotification")&&null!==document.getElementById(t.value+"PaymentNotification")&&(document.getElementById(t.value+"PaymentNotification").style.display="block"),null!=document.getElementById(t.value+"HideButton")&&1==document.getElementById(t.value+"HideButton").value&&e._disableSubmitButton(),null!=document.getElementById(t.value+"-payment")&&null!=document.getElementById(t.value+"-payment")&&(document.getElementById(t.value+"-payment").style.display="block")),null!=u&&e.showComponents(u,t.value),n>="6.4")var d=document.querySelector('#confirmOrderForm button[type="submit"]');else d=document.querySelector('#confirmPaymentForm button[type="submit"]');"novalnetcreditcard"==t.value?e._createScript((function(){var n=JSON.parse(document.getElementById(t.value+"-payment").getAttribute("data-"+t.value+"-payment-config"));e.loadIframe(n,t.value)})):(o.includes(t.value)||l.includes(t.value))&&e._createScript((function(){var e=JSON.parse(document.getElementById(t.value+"-payment").getAttribute("data-"+t.value+"-payment-config"));("novalnetsepa"!=t.value&&null===e.company||!NovalnetUtility.isValidCompanyName(e.company))&&(document.getElementById(t.value+"DobField").style.display="block")}));var m=document.querySelectorAll(".remove_cc_card_details"),v=document.querySelectorAll(".remove_paypal_account_details"),y=document.querySelectorAll(".remove_card_details"),s=document.querySelectorAll(".remove_guarantee_card_details "),f=document.querySelectorAll(".remove_instalment_card_details");"novalnetcreditcard"===t.value?m.forEach((function(n){n.addEventListener("click",(function(){e.removeStoredCard(n,t.value)}))})):"novalnetsepa"===t.value?y.forEach((function(n){n.addEventListener("click",(function(){e.removeStoredCard(n,t.value)}))})):"novalnetsepaguarantee"===t.value?s.forEach((function(n){n.addEventListener("click",(function(){e.removeStoredCard(n,t.value)}))})):"novalnetpaypal"===t.value?v.forEach((function(n){n.addEventListener("click",(function(){e.removeStoredCard(n,t.value)}))})):"novalnetsepainstalment"===t.value&&f.forEach((function(n){n.addEventListener("click",(function(){e.removeStoredCard(n,t.value)}))})),"novalnetinvoiceinstalment"!==t.value&&"novalnetsepainstalment"!==t.value||(document.getElementById(t.value+"Duration").selectedIndex=0,document.getElementById(t.value+"Duration").addEventListener("change",(function(e){var n=e.target.value;document.querySelectorAll("."+t.value+"Detail").forEach((function(e){e.dataset.duration===n?e.hidden=!1:e.hidden="hidden"}))})),document.getElementById(t.value+"Info").addEventListener("click",(function(n){e.hideSummary(t.value)}))),i.forEach((function(n,o){n.addEventListener("click",(function(){e.showComponents(n,t.value)}))})),c.forEach((function(n){n.addEventListener("click",(function(){e.showPaymentForm(n,t.value)}))})),d.addEventListener("click",(function(a){var r=document.querySelector("#"+t.value+"Id"),u=document.querySelector("input[name=paymentMethodId]:checked"),i=document.querySelector('input[type="radio"].'+t.value+"-SavedPaymentMethods-tokenInput:checked");if("novalnetcreditcard"==t.value)void 0===r||""===r.value||u.value!==r.value||null!=i&&"new"!=i.value||(document.getElementById("confirmFormSubmit").disabled=!0,a.preventDefault(),a.stopImmediatePropagation(),r.scrollIntoView(),NovalnetUtility.getPanHash());else if(o.includes(t.value)){var c=document.getElementById(t.value+"AccountData"),d=document.getElementById(t.value+"Dob");if(void 0!==r&&""!==r.value&&u.value===r.value)if(void 0!==c&&""!==c.value||null!=i&&"new"!=i.value){if("novalnetsepainstalment"===t.value&&(null===config.company||!NovalnetUtility.isValidCompanyName(config.company))||"novalnetsepaguarantee"===t.value&&(null===config.company||!NovalnetUtility.isValidCompanyName(config.company)||0===config.allowB2B))if(void 0===d||""===d.value){if(void 0!==document.getElementById("novalnetsepaId")&&null!=config.forceGuarantee&&1==config.forceGuarantee&&"novalnetsepaguarantee"===t.value)return n<"6.4"&&(u.value=document.getElementById("novalnetsepaId").value),document.getElementById("doForceSepaPayment").value=1,document.getElementById("SepaForcePayment").value=1,!0;e.preventForm(d,t.value,config.text.dobEmpty)}else if(void 0!==d&&""!==d.value){var m=e.validateAge(d.value);if((m<18||isNaN(m))&&void 0!==document.getElementById("novalnetsepaId")&&null!=config.forceGuarantee&&1==config.forceGuarantee&&"novalnetsepaguarantee"===t.value)return n<"6.4"&&(u.value=document.getElementById("novalnetsepaId").value),document.getElementById("doForceSepaPayment").value=1,document.getElementById("SepaForcePayment").value=1,!0;(m<18||isNaN(m))&&e.preventForm(d,t.value,config.text.dobInvalid)}}else e.preventForm(c,t.value,config.text.invalidIban)}else if(l.includes(t.value)){var v=document.getElementById(t.value+"Dob");if(void 0!==r.value&&""!==r.value&&u.value===r.value&&(null===config.company||!NovalnetUtility.isValidCompanyName(config.company)||0===config.allowB2B))if(void 0===v||""===v.value){if(void 0!==document.getElementById("novalnetinvoiceId")&&null!=config.forceGuarantee&&1==config.forceGuarantee&&"novalnetinvoiceguarantee"===t.value)return n<"6.4"&&(u.value=document.getElementById("novalnetinvoiceId").value),document.getElementById("doForceInvoicePayment").value=1,document.getElementById("InvoiceForcePayment").value=1,!0;e.preventForm(v,t.value,config.text.dobEmpty)}else if(void 0!==v&&""!==v.value){var y=e.validateAge(v.value);if((y<18||isNaN(y))&&void 0!==document.getElementById("novalnetinvoiceId")&&null!=config.forceGuarantee&&1==config.forceGuarantee&&"novalnetinvoiceguarantee"===t.value)return n<"6.4"&&(u.value=document.getElementById("novalnetinvoiceId").value),document.getElementById("doForceInvoicePayment").value=1,document.getElementById("InvoiceForcePayment").value=1,!0;(y<18||isNaN(y))&&e.preventForm(v,t.value,config.text.dobInvalid)}}}))}))}},{key:"_createScript",value:function(e){var t=document.createElement("script");t.type="text/javascript",t.src="https://cdn.novalnet.de/js/v2/NovalnetUtility.js",t.addEventListener("load",e.bind(this),!1),document.head.appendChild(t)}},{key:"loadIframe",value:function(e,t){if("novalnetcreditcard"===t){if(NovalnetUtility.setClientKey(e.clientKey),document.querySelector("#nnShopVersion").value>="6.4")var n=document.querySelector("#confirmOrderForm");else n=document.querySelector("#confirmPaymentForm");var o={callback:{on_success:function(e){return document.getElementById("novalnetcreditcard-panhash").value=e.hash,document.getElementById("novalnetcreditcard-uniqueid").value=e.unique_id,document.getElementById("novalnetcreditcard-doRedirect").value=e.do_redirect,null!=e.card_exp_month&&null!=e.card_exp_year&&(document.getElementById("novalnetcreditcard-expiry-date").value=e.card_exp_month+"/"+e.card_exp_year),document.getElementById("novalnetcreditcard-masked-card-no").value=e.card_number,document.getElementById("novalnetcreditcard-card-type").value=e.card_type,document.getElementById("confirmFormSubmit").disabled=!1,n.submit(),!0},on_error:function(e){var t=document.getElementById("novalnetcreditcard-error-container"),n=t.querySelector(".alert-content");return n.innerHTML="",void 0!==e.error_message&&""!==e.error_message?(document.getElementById("confirmFormSubmit").disabled=!1,n.innerHTML=e.error_message,t.style.display="block",t.scrollIntoView()):t.style.display="none",!1},on_show_overlay:function(e){document.getElementById("novalnetCreditcardIframe").classList.add("novalnet-challenge-window-overlay")},on_hide_overlay:function(e){document.getElementById("novalnetCreditcardIframe").classList.remove("novalnet-challenge-window-overlay")},on_show_captcha:function(){return elementContent.innerHTML="Your Credit Card details are Invalid",element.style.display="block",element.scrollIntoView(),elementContent.focus(),!1}},iframe:e.iframe,customer:e.customer,transaction:e.transaction,custom:e.custom};NovalnetUtility.createCreditCardForm(o)}}},{key:"showComponents",value:function(e,t){"new"===e.value&&!0===e.checked?document.getElementById(t+"-payment-form").classList.remove("nnhide"):document.getElementById(t+"-payment-form").classList.add("nnhide")}},{key:"hideSummary",value:function(e){var t=document.getElementById(e+"Summary");t.classList.contains("nnhide")?t.classList.remove("nnhide"):t.classList.add("nnhide")}},{key:"showPaymentForm",value:function(e,t){var n=document.querySelector("#"+t+"Id");void 0!==n&&""!==n.value&&e.value===n.value?("novalnetcreditcard"==t&&NovalnetUtility.setCreditCardFormHeight(),void 0!==document.getElementById(t+"-payment")&&null!==document.getElementById(t+"-payment")&&(document.getElementById(t+"-payment").style.display="block"),void 0!==document.getElementById(t+"PaymentNotification")&&null!==document.getElementById(t+"PaymentNotification")&&(document.getElementById(t+"PaymentNotification").style.display="block")):(void 0!==document.getElementById(t+"-payment")&&null!==document.getElementById(t+"-payment")&&(document.getElementById(t+"-payment").style.display="none"),void 0!==document.getElementById(t+"PaymentNotification")&&null!==document.getElementById(t+"PaymentNotification")&&(document.getElementById(t+"PaymentNotification").style.display="none"))}},{key:"removeStoredCard",value:function(t,n){var o=document.querySelector('input[name="'+n+'FormData[paymentToken]"]:checked');null!=o&&""!=o&&(this.client.post(e("#cardRemoveUrl").val(),JSON.stringify({token:o.value}),""),setTimeout((function(){return window.location.reload()}),2e3))}},{key:"_disableSubmitButton",value:function(){var e=document.querySelector("#confirmOrderForm button");e&&e.setAttribute("disabled","disabled")}},{key:"validateAge",value:function(e){var t=new Date;if(void 0===e||""===e)return NaN;var n=e.split("."),o=t.getFullYear()-n[2],a=t.getMonth()-n[1];return((a+=1)<0||"0"==a&&t.getDate()<n[0])&&o--,o}},{key:"preventForm",value:function(e,t,n){e.style.borderColor="red";var o=document.getElementById(t+"-error-container");event.preventDefault(),event.stopImmediatePropagation(),o.scrollIntoView();var a=o.querySelector(".alert-content");return a.innerHTML="",void 0!==n&&""!==n?(a.innerHTML=n,o.style.display="block",o.scrollIntoView()):o.style.display="none",!1}}])&&r(o.prototype,l),d&&r(o,d),n}(o.a)}).call(this,n("UoTJ"))},piAX:function(e,t,n){"use strict";n.r(t);var o=n("jJSA");window.PluginManager.register("NovalnetPayment",o.a,"#novalnet-payment-script")}},[["piAX","runtime","vendor-node","vendor-shared"]]]);