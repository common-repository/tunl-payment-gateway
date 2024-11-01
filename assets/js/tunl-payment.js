jQuery(document).ready(function () {

  toastr.options = {
    closeButton: false,
    debug: false,
    newestOnTop: false,
    progressBar: false,
    positionClass: "toast-top-right",
    preventDuplicates: false,
    onclick: null,
    showDuration: "300",
    hideDuration: "1000",
    timeOut: "3000",
    extendedTimeOut: "1000",
    showEasing: "swing",
    hideEasing: "linear",
    showMethod: "fadeIn",
    hideMethod: "fadeOut",
  };

  function disableAutoComplete(selector) {
    jQuery(selector).attr("autocomplete", "new-password");
  }

  disableAutoComplete("#woocommerce_tunl_password");

  const loaderLive = `<img src="${adminAjax.ajaxloader}" class="loader-live-class" />`;
  const loaderTest = `<img src="${adminAjax.ajaxloader}" class="loader-test-class" />`;

  const testTestKeys = `<a class="btn button-primary btn-connect-payment validate-test-keys-btn">Validate Test Keys</a>`;
  const testLiveKeys = `<a style="margin-bottom: 20px;" class="btn button-primary btn-connect-payment validate-live-keys-btn">Validate Live Keys</a>`;

  const validateLiveButtonElm = jQuery(testLiveKeys);
  const validateTestButtonElm = jQuery(testTestKeys);

  const buttonsAndLiveLoaderSection = jQuery(
    `<div class="connect-btn-section">${loaderLive}</div>`
  );
  buttonsAndLiveLoaderSection.append(validateLiveButtonElm);

  const buttonsAndTestLoaderSection = jQuery(
    `<div class="connect-btn-section">${loaderTest}</div>`
  );
  buttonsAndTestLoaderSection.append(validateTestButtonElm);

  const tunlLiveConnectBtn = jQuery("#woocommerce_tunl_live_connect_button");
  const tunlTestConnectBtn = jQuery("#woocommerce_tunl_test_connect_button");

  const formInpLive = tunlLiveConnectBtn.parents(".forminp");
  const formInpTest = tunlTestConnectBtn.parents(".forminp");
  formInpLive.append(buttonsAndLiveLoaderSection);
  formInpTest.append(buttonsAndTestLoaderSection);

  function showLoader(type) {
    jQuery(`.loader-${type}-class`).show();
    jQuery(`.validate-${type}-keys-btn`).css("pointer-events", "none");
    jQuery(`.validate-${type}-keys-btn`).css("opacity", "0.2");
  }

  function hideLoader(type) {
    jQuery(`.loader-${type}-class`).hide();
    jQuery(`.validate-${type}-keys-btn`).css("pointer-events", "unset");
    jQuery(`.validate-${type}-keys-btn`).css("opacity", "1");
  }

  function post(data, loader) {
    showLoader(loader);
    jQuery.ajax({
      type: "POST",
      url: adminAjax.ajaxurl,
      data,
      success: function (response) {
        hideLoader(loader);
        if (!response.status) {
          return toastr["error"](response.message);
        }
        toastr["success"](response.message);
      },
    });
  }

  function validateKeys(testMode = false, loader) {
    const demoUser = jQuery("#woocommerce_tunl_username").val();
    const demoPass = jQuery("#woocommerce_tunl_password").val();
    const liveUser = jQuery("#woocommerce_tunl_live_username").val();
    const livePass = jQuery("#woocommerce_tunl_live_password").val();

    const api_mode = testMode ? "yes" : "no";

    const enabled = jQuery("#woocommerce_tunl_enabled").is(":checked");
    const tunl_enabled = enabled ? "yes" : "no";

    const username = testMode ? demoUser : liveUser;
    const password = testMode ? demoPass : livePass;
    if (!username) return toastr["error"]("Please enter your API Key.");
    if (!password) return toastr["error"]("Please enter your Secret Key.");

    var tunl_title = jQuery("#woocommerce_tunl_title").val();

    const data = {
      action: "tunl_gateway_wc_admin_connect_to_api",
      tunl_title,
      username,
      password,
      api_mode,
      tunl_enabled,
    };

    post(data, loader);
  }

  jQuery(document).on("click", ".validate-test-keys-btn", function () {
    validateKeys(true, "test");
  });

  jQuery(document).on("click", ".validate-live-keys-btn", function () {
    validateKeys(false, "live");
  });
});
