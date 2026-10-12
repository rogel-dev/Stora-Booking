(() => {
  const root = document.querySelector(".stora-units");
  if (!root) return;

  const unitTypeToggle = root.querySelector("[data-stora-unit-type-toggle]");
  const units = Array.from(root.querySelectorAll(".stora-unit"));

  const getActive = (toggle, attr, fallback = "all") => {
    if (!toggle) return fallback;
    const active = toggle.querySelector(".is-active");
    if (!active) return fallback;
    return active.getAttribute(attr) || fallback;
  };

  const setActiveButton = (toggle, selector, matchValue) => {
    if (!toggle) return;
    const buttons = Array.from(toggle.querySelectorAll(selector));
    buttons.forEach((b) => b.classList.toggle("is-active", (b.getAttribute(matchValue.attr) || "") === matchValue.value));
  };

  function updateUnitTypeButtons() {
    if (!unitTypeToggle) return;

    const counts = new Map();
    units.forEach((u) => {
      const ut = u.dataset.unitTypeId || "";
      if (!ut) return;
      counts.set(ut, (counts.get(ut) || 0) + 1);
    });

    Array.from(unitTypeToggle.querySelectorAll("[data-unit-type]")).forEach((btn) => {
      const ut = btn.getAttribute("data-unit-type") || "";
      if (ut === "all") {
        btn.hidden = false;
        btn.disabled = false;
        return;
      }
      const n = counts.get(ut) || 0;
      btn.hidden = n === 0;
      btn.disabled = n === 0;
    });

    // If current active unit-type no longer has matches, reset to All.
    const activeUnitType = getActive(unitTypeToggle, "data-unit-type", "all");
    if (activeUnitType !== "all" && (counts.get(activeUnitType) || 0) === 0) {
      Array.from(unitTypeToggle.querySelectorAll("[data-unit-type]")).forEach((b) =>
        b.classList.toggle("is-active", (b.getAttribute("data-unit-type") || "") === "all")
      );
    }
  }

  function applyFilters() {
    const activeUnitType = getActive(unitTypeToggle, "data-unit-type", "all");

    units.forEach((u) => {
      const ut = u.dataset.unitTypeId || "";

      const okType = activeUnitType === "all" || ut === activeUnitType;

      u.style.display = okType ? "" : "none";
    });
  }

  if (unitTypeToggle) {
    unitTypeToggle.addEventListener("click", (e) => {
      const btn = e.target.closest("[data-unit-type]");
      if (!btn) return;
      Array.from(unitTypeToggle.querySelectorAll("[data-unit-type]")).forEach((b) =>
        b.classList.toggle("is-active", b === btn)
      );
      applyFilters();
    });
  }

  updateUnitTypeButtons();
  applyFilters();
})();

(() => {
  const form = document.querySelector("[data-stora-checkout-form]");
  if (!form) return;

  const steps = Array.from(form.querySelectorAll("[data-stora-checkout-step]"));
  const progressBar = document.querySelector("[data-stora-checkout-progress]");
  const checkoutSplit = document.querySelector(".stora-checkout__split");
  const checkoutMain = document.querySelector(".stora-checkout__main");
  const checkoutSide = document.querySelector(".stora-checkout__side");
  const mobileCheckoutMq = window.matchMedia("(max-width: 960px)");
  const totalSteps = steps.length || 2;
  let currentStep = 1;

  const repositionCheckoutSide = () => {
    if (!checkoutSplit || !checkoutMain || !checkoutSide) return;

    if (mobileCheckoutMq.matches) {
      const activeStep = form.querySelector(".stora-checkout__step:not([hidden])");
      const nav = activeStep?.querySelector(".stora-checkout__nav");
      if (activeStep && nav) {
        checkoutSide.classList.add("is-mobile-inline");
        if (nav.previousElementSibling !== checkoutSide) {
          activeStep.insertBefore(checkoutSide, nav);
        }
      }
      return;
    }

    checkoutSide.classList.remove("is-mobile-inline");
    if (checkoutMain.nextElementSibling !== checkoutSide) {
      checkoutSplit.insertBefore(checkoutSide, checkoutMain.nextSibling);
    }
  };

  let checkoutSideResizeTimer = null;
  window.addEventListener("resize", () => {
    clearTimeout(checkoutSideResizeTimer);
    checkoutSideResizeTimer = setTimeout(repositionCheckoutSide, 150);
  });

  const getStepInputs = (step) => {
    if (step === 1) {
      return Array.from(form.querySelectorAll("[data-stora-required]:not([data-stora-step])"));
    }
    return Array.from(form.querySelectorAll(`[data-stora-required][data-stora-step="${step}"]`));
  };

  const updateProgress = (step) => {
    if (!progressBar) return;
    const pct = Math.max(0, Math.min(100, (step / totalSteps) * 100));
    progressBar.style.width = `${pct}%`;
  };

  const protectionRow = document.querySelector("[data-stora-summary-protection-row]");
  const protectionLabel = document.querySelector("[data-stora-summary-protection-label]");
  const protectionValue = document.querySelector("[data-stora-summary-protection-value]");

  const syncProtectionSummary = (step) => {
    if (!protectionRow) return;

    if (step < 3) {
      protectionRow.hidden = true;
      return;
    }

    const selected = form.querySelector('input[name="protection_level_id"]:checked');
    if (!selected) {
      protectionRow.hidden = true;
      return;
    }

    protectionRow.hidden = false;
    const cover = (selected.getAttribute("data-stora-protection-cover") || "").trim();
    const price = (selected.getAttribute("data-stora-protection-price") || "").trim();

    if (protectionLabel) {
      protectionLabel.textContent = cover !== "" ? cover : "Protection";
    }
    if (protectionValue) {
      protectionValue.textContent = price !== "" ? price : "-";
    }
  };

  const showStep = (step) => {
    currentStep = step;
    steps.forEach((panel) => {
      const n = Number(panel.getAttribute("data-stora-checkout-step") || "1");
      panel.hidden = n !== step;
    });
    updateProgress(step);
    syncProtectionSummary(step);
    if (step >= 3 && typeof window.storaUpdateLocalOrderSummary === "function") {
      window.storaUpdateLocalOrderSummary();
    }
    if (step >= 4) {
      if (typeof window.storaUpdateTotalDueLabel === "function") {
        window.storaUpdateTotalDueLabel();
      }
      if (typeof window.storaScheduleOrderSummaryRefresh === "function") {
        window.storaScheduleOrderSummaryRefresh();
      }
    }
    form.dispatchEvent(new CustomEvent("stora:checkout-step", { bubbles: true, detail: { step } }));
    repositionCheckoutSide();
    const activePanel = steps.find((panel) => Number(panel.getAttribute("data-stora-checkout-step") || "1") === step);
    if (activePanel) {
      const focusTarget = activePanel.querySelector("input:not([type='hidden']), select, textarea, button");
      if (focusTarget && typeof focusTarget.focus === "function") {
        focusTarget.focus({ preventScroll: true });
      }
    }
  };

  const requiredInputs = Array.from(form.querySelectorAll("[data-stora-required]"));
  const email = form.querySelector("[data-stora-email]");
  const emailConfirm = form.querySelector("[data-stora-email-confirm]");
  const phone = form.querySelector("[data-stora-phone]");
  const phoneConfirm = form.querySelector("[data-stora-phone-confirm]");

  const normalizePhone = (v) => (v || "").toString().replace(/\D+/g, "");

  const phoneItiInstances = [];

  // Max digits allowed for the selected country, based on an example number
  // (e.g. UK mobile = 11 digits). Falls back to the E.164 maximum of 15.
  function expectedMaxDigits(iti) {
    const utils = window.intlTelInputUtils;
    const country =
      iti && typeof iti.getSelectedCountryData === "function"
        ? iti.getSelectedCountryData()
        : null;
    const iso2 = country && country.iso2 ? country.iso2 : "gb";
    if (utils && typeof utils.getExampleNumber === "function") {
      try {
        const example = utils.getExampleNumber(iso2, true, utils.numberType.MOBILE);
        const digits = normalizePhone(example);
        if (digits.length) return digits.length;
      } catch (err) {
        /* utils not ready yet */
      }
    }
    return 15;
  }

  // Allow digits only. If a full international number is entered/pasted, strip the
  // leading country code (the flag dropdown already provides it), then cap the length.
  function sanitizePhoneInput(input, iti) {
    let digits = normalizePhone(input.value);
    if (digits === "") {
      if (input.value !== "") input.value = "";
      return;
    }

    const country =
      iti && typeof iti.getSelectedCountryData === "function"
        ? iti.getSelectedCountryData()
        : null;
    const dial = country && country.dialCode ? String(country.dialCode) : "";

    // Drop a pasted country code, e.g. "447403940600" -> "7403940600".
    if (dial && digits.indexOf(dial) === 0 && digits.length - dial.length >= 7) {
      digits = digits.slice(dial.length);
    }

    const max = expectedMaxDigits(iti);
    if (digits.length > max) digits = digits.slice(0, max);
    if (input.value !== digits) input.value = digits;
  }

  function initPhoneCountrySelector(input) {
    if (!input || typeof window.intlTelInput !== "function") return null;
    const iti = window.intlTelInput(input, {
      initialCountry: "gb",
      preferredCountries: ["gb"],
      autoPlaceholder: "aggressive",
      utilsScript:
        "https://cdn.jsdelivr.net/npm/intl-tel-input@17.0.19/build/js/utils.js",
    });
    phoneItiInstances.push({ input, iti });

    // Block non-numeric key presses (letters, symbols) up front.
    input.addEventListener("keypress", (e) => {
      if (e.key && e.key.length === 1 && !/[0-9]/.test(e.key)) {
        e.preventDefault();
      }
    });
    input.addEventListener("input", () => sanitizePhoneInput(input, iti));
    input.addEventListener("countrychange", () =>
      sanitizePhoneInput(input, iti)
    );

    return iti;
  }

  initPhoneCountrySelector(phone);
  initPhoneCountrySelector(phoneConfirm);

  // Convert a phone field to full international (E.164) form, e.g. +447403940600.
  function toE164(input, iti) {
    const raw = (input.value || "").trim();
    if (!iti) return raw;

    // Prefer the library formatter when utils.js has loaded.
    if (typeof iti.getNumber === "function") {
      const full = iti.getNumber();
      if (full && full.charAt(0) === "+") return full;
    }

    // Fallback (utils.js not ready): build from the selected country's dial code.
    const country =
      typeof iti.getSelectedCountryData === "function"
        ? iti.getSelectedCountryData()
        : null;
    const dial = country && country.dialCode ? String(country.dialCode) : "";
    let national = normalizePhone(raw).replace(/^0+/, "");
    if (!national) return raw;
    return dial ? "+" + dial + national : "+" + national;
  }

  // Rewrite each phone field to its full international number before submitting.
  function applyInternationalPhoneNumbers() {
    phoneItiInstances.forEach(({ input, iti }) => {
      if (!input) return;
      const e164 = toE164(input, iti);
      if (e164) input.value = e164;
    });
  }

  function ensureErrorEl(input) {
    const field = input.closest(".stora-checkout__field");
    if (!field) return null;
    let el = field.querySelector(".stora-checkout__error");
    if (!el) {
      el = document.createElement("div");
      el.className = "stora-checkout__error";
      field.appendChild(el);
    }
    return el;
  }

  function setError(input, message) {
    const el = ensureErrorEl(input);
    if (!el) return;
    if (message) {
      input.classList.add("is-invalid");
      el.textContent = message;
    } else {
      input.classList.remove("is-invalid");
      el.textContent = "";
    }
  }

  function validateRequired(input) {
    if (input.type === "radio") {
      const group = form.querySelectorAll(`input[type="radio"][name="${input.name}"]`);
      const checked = form.querySelector(`input[type="radio"][name="${input.name}"]:checked`);
      if (!checked) {
        group.forEach((radio) => setError(radio, "This field is required."));
        return false;
      }
      group.forEach((radio) => setError(radio, ""));
      return true;
    }

    const v = input.tagName === "SELECT" ? (input.value || "").trim() : (input.value || "").trim();
    if (!v) {
      setError(input, "This field is required.");
      return false;
    }
    setError(input, "");
    return true;
  }

  function isValidEmailFormat(value) {
    const v = (value || "").trim();
    if (!v) return false;
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i.test(v);
  }

  function isValidPhoneFormat(value) {
    const digits = normalizePhone(value);
    return digits.length >= 10 && digits.length <= 15;
  }

  function validateEmailFormat(input) {
    const v = (input.value || "").trim();
    if (!v) return true;
    if (!isValidEmailFormat(v)) {
      setError(input, "Please enter a valid email address.");
      return false;
    }
    setError(input, "");
    return true;
  }

  function validateEmailFields() {
    let ok = true;
    if (email) ok = validateEmailFormat(email) && ok;
    if (emailConfirm) ok = validateEmailFormat(emailConfirm) && ok;
    if (!ok) return false;

    if (!email || !emailConfirm) return true;
    const a = (email.value || "").trim().toLowerCase();
    const b = (emailConfirm.value || "").trim().toLowerCase();
    if (!a || !b) return true;
    if (a !== b) {
      setError(emailConfirm, "Email addresses do not match.");
      return false;
    }
    setError(emailConfirm, "");
    return true;
  }

  function getItiForInput(input) {
    const entry = phoneItiInstances.find((it) => it.input === input);
    return entry ? entry.iti : null;
  }

  function validatePhoneFormat(input) {
    const v = (input.value || "").trim();
    if (!v) return true;

    // Prefer the phone widget's precise validation (matches what Stora accepts)
    // when the intl-tel-input utils have loaded.
    const iti = getItiForInput(input);
    const utils = window.intlTelInputUtils;
    if (iti && utils && typeof iti.isValidNumber === "function") {
      if (!iti.isValidNumber()) {
        setError(
          input,
          "Please enter a valid mobile number for the selected country."
        );
        return false;
      }
      // Stora requires a mobile number specifically, not a landline.
      if (typeof iti.getNumberType === "function") {
        const type = iti.getNumberType();
        const mobileTypes = [utils.numberType.MOBILE, utils.numberType.FIXED_LINE_OR_MOBILE];
        if (mobileTypes.indexOf(type) === -1) {
          setError(input, "Please enter a mobile number (not a landline).");
          return false;
        }
      }
      setError(input, "");
      return true;
    }

    // Fallback while the validation library is still loading.
    if (!isValidPhoneFormat(v)) {
      setError(input, "Please enter a valid phone number.");
      return false;
    }
    setError(input, "");
    return true;
  }

  function validatePhoneFields() {
    let ok = true;
    if (phone) ok = validatePhoneFormat(phone) && ok;
    if (phoneConfirm) ok = validatePhoneFormat(phoneConfirm) && ok;
    if (!ok) return false;
    return validatePhoneMatch();
  }

  function validatePhoneMatch() {
    if (!phone || !phoneConfirm) return true;
    const a = normalizePhone(phone.value);
    const b = normalizePhone(phoneConfirm.value);
    if (!a || !b) return true;
    if (a !== b) {
      setError(phoneConfirm, "Mobile numbers do not match.");
      return false;
    }
    setError(phoneConfirm, "");
    return true;
  }

  function validateProtectionStep() {
    const protectionField = form.querySelector("[data-stora-protection-field]");
    if (!protectionField) return true;

    const selected = form.querySelector('input[name="protection_level_id"]:checked');
    const errorEl = form.querySelector("[data-stora-protection-error]");
    if (!selected) {
      if (errorEl) {
        errorEl.hidden = false;
        errorEl.textContent = "Please select a protection cover level.";
      }
      return false;
    }
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = "";
    }
    return true;
  }

  function validateConsentStep() {
    const consentField = form.querySelector("[data-stora-consent-field]");
    if (!consentField) return true;

    const consentInput = form.querySelector('input[name="booking_consent"]');
    const errorEl = form.querySelector("[data-stora-consent-error]");
    if (!consentInput || !consentInput.checked) {
      if (consentInput) consentInput.classList.add("is-invalid");
      if (errorEl) {
        errorEl.hidden = false;
        errorEl.textContent = "Please agree to the terms and privacy policy before continuing.";
      }
      return false;
    }
    if (consentInput) consentInput.classList.remove("is-invalid");
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = "";
    }
    return true;
  }

  function validateStep(step) {
    let ok = true;
    getStepInputs(step).forEach((input) => {
      ok = validateRequired(input) && ok;
    });
    if (step === 1) {
      ok = validateEmailFields() && ok;
      ok = validatePhoneFields() && ok;
    }
    if (step === 3) {
      ok = validateProtectionStep() && ok;
    }
    if (step === 5) {
      ok = validateConsentStep() && ok;
    }
    if (!ok) {
      const firstInvalid = form.querySelector(".is-invalid");
      if (firstInvalid && firstInvalid.focus) firstInvalid.focus({ preventScroll: false });
    }
    return ok;
  }

  requiredInputs.forEach((input) => {
    input.addEventListener("blur", () => {
      validateRequired(input);
      if (input === email || input === emailConfirm) validateEmailFields();
    });
    input.addEventListener("input", () => {
      if (input.classList.contains("is-invalid")) validateRequired(input);
      if (input === email || input === emailConfirm) validateEmailFields();
      if (input === phone || input === phoneConfirm) validatePhoneFields();
    });
    input.addEventListener("change", () => {
      if (input.classList.contains("is-invalid")) validateRequired(input);
    });
  });

  form.querySelectorAll("[data-stora-checkout-next]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const targetStep = Number(btn.getAttribute("data-stora-checkout-next") || String(currentStep + 1));
      if (validateStep(currentStep)) {
        showStep(targetStep);
      }
    });
  });

  form.querySelectorAll("[data-stora-checkout-back]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const targetStep = Number(btn.getAttribute("data-stora-checkout-back") || String(currentStep - 1));
      showStep(targetStep);
    });
  });

  form.querySelectorAll(".stora-checkout__protection-option").forEach((label) => {
    const input = label.querySelector('input[name="protection_level_id"]');
    if (!input) return;
    input.addEventListener("change", () => {
      form.querySelectorAll(".stora-checkout__protection-option").forEach((option) => {
        const radio = option.querySelector('input[name="protection_level_id"]');
        option.classList.toggle("is-selected", Boolean(radio && radio.checked));
      });
      validateProtectionStep();
      syncProtectionSummary(currentStep);
      if (typeof window.storaUpdateLocalOrderSummary === "function") {
        window.storaUpdateLocalOrderSummary();
      }
    });
  });

  const consentInput = form.querySelector('input[name="booking_consent"]');
  if (consentInput) {
    consentInput.addEventListener("change", () => {
      if (consentInput.checked) {
        validateConsentStep();
      }
    });
  }

  form.addEventListener("submit", (e) => {
    const couponInput = document.querySelector("[data-stora-coupon-code]");
    let couponNameRemoved = false;
    if (couponInput && couponInput.hasAttribute("name") && !couponInput.classList.contains("is-valid")) {
      couponInput.removeAttribute("name");
      couponNameRemoved = true;
    }

    let ok = true;
    for (let step = 1; step <= totalSteps; step += 1) {
      ok = validateStep(step) && ok;
    }

    if (!ok) {
      e.preventDefault();
      if (couponNameRemoved && couponInput) {
        couponInput.setAttribute("name", "coupon_code");
      }
      for (let step = 1; step <= totalSteps; step += 1) {
        if (!validateStep(step)) {
          showStep(step);
          break;
        }
      }
      return;
    }

    applyInternationalPhoneNumbers();
  });

  window.storaIsValidEmailFormat = isValidEmailFormat;
  window.storaIsValidPhoneFormat = isValidPhoneFormat;
  window.STORA_COUPON_MSG = {
    missingContact: "Please enter your name, email, and phone number before applying a coupon.",
    invalidEmail: "Please enter a valid email address before applying the coupon.",
    invalidPhone: "Please enter a valid phone number before applying the coupon.",
    invalidCode: "This coupon code is not valid. Please check the code and try again.",
    apiError: "We couldn't validate the coupon right now. Please try again in a moment.",
    emptyCode: "Please enter a coupon code.",
  };
  window.storaGetContactPreviewError = (checkoutForm) => {
    const fullNameEl = checkoutForm.querySelector('input[name="full_name"]');
    const emailEl = checkoutForm.querySelector('input[name="email"]');
    const phoneEl = checkoutForm.querySelector('input[name="phone"]');
    const full = fullNameEl ? (fullNameEl.value || "").trim() : "";
    const emailValue = emailEl ? (emailEl.value || "").trim() : "";
    const phoneValue = phoneEl ? (phoneEl.value || "").trim() : "";

    if (!full) return "Please enter your full name.";
    if (!emailValue) return "Please enter your email address.";
    if (!isValidEmailFormat(emailValue)) return "Please enter a valid email address.";
    if (!phoneValue) return "Please enter your mobile number.";
    if (!isValidPhoneFormat(phoneValue)) return "Please enter a valid phone number.";
    return "";
  };
  window.storaGetCouponContactError = (checkoutForm) => {
    const msg = window.STORA_COUPON_MSG || {};
    const fullNameEl = checkoutForm.querySelector('input[name="full_name"]');
    const emailEl = checkoutForm.querySelector('input[name="email"]');
    const phoneEl = checkoutForm.querySelector('input[name="phone"]');
    const full = fullNameEl ? (fullNameEl.value || "").trim() : "";
    const emailValue = emailEl ? (emailEl.value || "").trim() : "";
    const phoneValue = phoneEl ? (phoneEl.value || "").trim() : "";

    if (!full || !emailValue || !phoneValue) {
      return msg.missingContact || window.storaGetContactPreviewError(checkoutForm);
    }
    if (!isValidEmailFormat(emailValue)) {
      return msg.invalidEmail || "Please enter a valid email address before applying the coupon.";
    }
    if (!isValidPhoneFormat(phoneValue)) {
      return msg.invalidPhone || "Please enter a valid phone number before applying the coupon.";
    }
    return "";
  };
  window.storaResolveCouponErrorMessage = (message) => {
    const msg = window.STORA_COUPON_MSG || {};
    const text = (message || "").trim();
    if (!text) return msg.apiError;

    const lower = text.toLowerCase();
    if (
      lower.includes("before applying a coupon") ||
      lower.includes("before applying the coupon") ||
      lower.includes("not valid") ||
      lower.includes("coupon code is invalid") ||
      lower.includes("cannot be applied") ||
      lower.includes("no longer available") ||
      lower.includes("not valid for the selected billing period")
    ) {
      return text;
    }
    if (
      lower.includes("unable to validate coupon") ||
      lower.includes("unable to calculate total") ||
      lower.includes("security check failed") ||
      lower.includes("missing unit selection")
    ) {
      return msg.apiError;
    }

    return text;
  };
  window.storaHasPositiveDiscount = (data) => {
    if (!data) return false;
    if (data.discount_amount != null && data.discount_amount !== "") {
      return (parseInt(data.discount_amount, 10) || 0) > 0;
    }
    const d = (data.discount || "").trim();
    if (!d) return false;
    const num = parseFloat(d.replace(/[^\d.]/g, ""));
    return !Number.isNaN(num) && num > 0;
  };

  repositionCheckoutSide();
})();

(() => {
  const summary = document.querySelector("[data-stora-order-summary]");
  const form = document.querySelector("#stora_checkout_form");
  if (!summary || !form) return;

  const defaultPromotionLabel = (summary.getAttribute("data-stora-promotion-label") || "Discount").trim();
  const rentEl = document.querySelector("[data-stora-summary-rent]");
  const subtotalEl = document.querySelector("[data-stora-summary-subtotal]");
  const totalEl = document.querySelector("[data-stora-summary-total]");
  const totalLabelEl = document.querySelector("[data-stora-summary-total-label]");
  const discountRow = document.querySelector("[data-stora-summary-discount-row]");
  const discountLabel = document.querySelector("[data-stora-summary-discount-label]");
  const discountValue = document.querySelector("[data-stora-summary-discount-value]");
  const nonceEl = document.querySelector("[data-stora-coupon-nonce]");
  const couponInput = document.querySelector("[data-stora-coupon-code]");

  let baseTotalAmount = parseInt(summary.getAttribute("data-stora-base-total-amount") || "0", 10);
  let currencyRef = (summary.getAttribute("data-stora-currency-ref") || totalEl?.textContent || "").trim();

  const PREVIEW_DEBOUNCE_MS = 600;
  let previewDebounceTimer = null;
  let previewAbortController = null;
  let previewRequestId = 0;

  const formatMoneyMinor = (minor, ref) => {
    const major = (minor / 100).toFixed(2);
    const prefixMatch = (ref || "").match(/^([^\d\-+.\s]+)\s*/);
    if (prefixMatch) return prefixMatch[1] + major;
    const symMatch = (ref || "").match(/^(\D+)/);
    if (symMatch) return symMatch[1].trim() + major;
    return "£" + major;
  };

  const getSelectedProtectionAmount = () => {
    const selected = form.querySelector('input[name="protection_level_id"]:checked');
    if (!selected) return 0;
    return parseInt(selected.getAttribute("data-stora-protection-amount") || "0", 10) || 0;
  };

  const formatDueDate = (isoDate) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec((isoDate || "").trim());
    if (!match) return "";
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    if (Number.isNaN(date.getTime())) return "";
    return date.toLocaleDateString("en-GB", { day: "numeric", month: "short" });
  };

  const getSelectedMoveInInput = () => form.querySelector('input[name="move_in"]:checked');

  const isOnPricingStep = () => Boolean(
    form.querySelector('[data-stora-checkout-step="4"]:not([hidden])') ||
    form.querySelector('[data-stora-checkout-step="5"]:not([hidden])')
  );

  const hasAppliedCoupon = () => (
    couponInput &&
    couponInput.classList.contains("is-valid") &&
    (couponInput.value || "").trim() !== ""
  );

  const shouldFetchStoraPreview = () => isOnPricingStep() || hasAppliedCoupon();

  const updateTotalDueLabel = () => {
    if (!totalLabelEl) return;
    const onMoveInStep = Boolean(
      form.querySelector('[data-stora-checkout-step="4"]:not([hidden])') ||
      form.querySelector('[data-stora-checkout-step="5"]:not([hidden])')
    );
    const moveInEl = getSelectedMoveInInput();
    const due = onMoveInStep ? formatDueDate(moveInEl?.value || "") : "";
    totalLabelEl.textContent = due ? `First Month Total (Due ${due})` : "First Month Total";
  };

  const updateLocalOrderSummary = () => {
    if (!totalEl) return;

    baseTotalAmount = parseInt(summary.getAttribute("data-stora-base-total-amount") || String(baseTotalAmount), 10) || 0;
    const step3Visible = Boolean(form.querySelector('[data-stora-checkout-step="3"]:not([hidden])'));
    const step4Visible = Boolean(form.querySelector('[data-stora-checkout-step="4"]:not([hidden])'));
    const step5Visible = Boolean(form.querySelector('[data-stora-checkout-step="5"]:not([hidden])'));
    const onProtectionStep = step3Visible || step4Visible || step5Visible;
    const protectionAmount = onProtectionStep ? getSelectedProtectionAmount() : 0;
    const totalMinor = Math.max(0, baseTotalAmount + protectionAmount);

    currencyRef = (summary.getAttribute("data-stora-currency-ref") || currencyRef || totalEl.textContent || "").trim();
    totalEl.textContent = formatMoneyMinor(totalMinor, currencyRef);
  };

  const syncSummaryTotalDisplay = () => {
    if (!totalEl) return;
    const rentText = rentEl ? (rentEl.textContent || "").trim() : "";
    const onProtectionStep = Boolean(
      form.querySelector('[data-stora-checkout-step="3"]:not([hidden])') ||
      form.querySelector('[data-stora-checkout-step="4"]:not([hidden])') ||
      form.querySelector('[data-stora-checkout-step="5"]:not([hidden])')
    );
    if (!onProtectionStep && rentText !== "") {
      totalEl.textContent = rentText;
      currencyRef = rentText;
      summary.setAttribute("data-stora-currency-ref", rentText);
      return;
    }
    updateLocalOrderSummary();
  };

  const getAjaxUrl = () => {
    if (window.ajaxurl) return window.ajaxurl;
    const actionUrl = form.getAttribute("action") || "";
    if (!actionUrl) return null;
    return actionUrl.replace(/admin-post\.php(?:\?.*)?$/i, "admin-ajax.php");
  };

  const canPreviewTotals = () => {
    if (typeof window.storaGetContactPreviewError === "function") {
      return window.storaGetContactPreviewError(form) === "";
    }
    const fullNameEl = form.querySelector('input[name="full_name"]');
    const emailEl = form.querySelector('input[name="email"]');
    const phoneEl = form.querySelector('input[name="phone"]');
    return Boolean(
      fullNameEl && (fullNameEl.value || "").trim() &&
      emailEl && (emailEl.value || "").trim() &&
      phoneEl && (phoneEl.value || "").trim()
    );
  };

  const hasPositiveDiscount = (data) => (
    typeof window.storaHasPositiveDiscount === "function"
      ? window.storaHasPositiveDiscount(data)
      : Boolean((data && data.discount) || "")
  );

  const hasPendingCoupon = () => (
    couponInput &&
    (couponInput.value || "").trim() !== "" &&
    !couponInput.classList.contains("is-valid")
  );

  const captureInitialSummaryState = () => {
    if (summary.dataset.storaInitialCaptured === "1") return;
    summary.dataset.storaInitialCaptured = "1";
    summary.dataset.storaInitialBaseTotal = summary.getAttribute("data-stora-base-total-amount") || "0";
    summary.dataset.storaInitialRent = rentEl ? (rentEl.textContent || "").trim() : "";
    summary.dataset.storaInitialTotalRef = summary.getAttribute("data-stora-currency-ref") || (totalEl ? totalEl.textContent : "");
    summary.dataset.storaInitialDiscountVisible = discountRow && !discountRow.hidden ? "1" : "0";
    summary.dataset.storaInitialDiscountValue = discountValue ? (discountValue.textContent || "-").trim() : "-";
    summary.dataset.storaInitialDiscountLabel = discountLabel ? (discountLabel.textContent || defaultPromotionLabel).trim() : defaultPromotionLabel;
  };

  const resetSummaryToBaseline = () => {
    captureInitialSummaryState();

    const initialRent = summary.dataset.storaInitialRent || "";
    if (rentEl && initialRent !== "") {
      rentEl.textContent = initialRent;
    }
    if (subtotalEl && initialRent !== "") {
      subtotalEl.textContent = initialRent;
    }

    if (discountRow && discountLabel && discountValue) {
      const showInitialDiscount = summary.dataset.storaInitialDiscountVisible === "1";
      discountRow.hidden = !showInitialDiscount;
      discountValue.textContent = showInitialDiscount
        ? (summary.dataset.storaInitialDiscountValue || "-")
        : "-";
      discountLabel.textContent = summary.dataset.storaInitialDiscountLabel || defaultPromotionLabel;
    }

    const initialBase = parseInt(summary.dataset.storaInitialBaseTotal || "0", 10) || 0;
    baseTotalAmount = initialBase;
    summary.setAttribute("data-stora-base-total-amount", String(initialBase));

    const initialTotalRef = (summary.dataset.storaInitialTotalRef || "").trim();
    if (initialTotalRef !== "") {
      summary.setAttribute("data-stora-currency-ref", initialTotalRef);
      currencyRef = initialTotalRef;
    }

    syncSummaryTotalDisplay();
  };

  const applySummaryData = (data, options = {}) => {
    if (!data) return;
    if (rentEl && data.subtotal) rentEl.textContent = data.subtotal;
    if (subtotalEl && data.subtotal) subtotalEl.textContent = data.subtotal;

    const ignorePendingCoupon = Boolean(options.ignorePendingCoupon);
    const showDiscount = hasPositiveDiscount(data) && (
      ignorePendingCoupon || !hasPendingCoupon() || couponInput?.classList.contains("is-valid")
    );
    if (discountRow && discountLabel && discountValue) {
      if (showDiscount) {
        const d = data.discount || "";
        discountRow.hidden = false;
        discountLabel.textContent = (data.discount_label || defaultPromotionLabel || "Discount").trim();
        discountValue.textContent = "-" + String(d).replace(/^\-+/, "");
      } else {
        discountRow.hidden = true;
        discountValue.textContent = "-";
      }
    }
    if (data.total_amount != null && data.total_amount !== "") {
      baseTotalAmount = parseInt(data.total_amount, 10) || 0;
      summary.setAttribute("data-stora-base-total-amount", String(baseTotalAmount));
    }
    if (data.total) {
      summary.setAttribute("data-stora-currency-ref", data.total);
      currencyRef = data.total;
    }
    updateLocalOrderSummary();
  };

  async function runOrderSummaryPreview(options = {}) {
    const withoutCoupon = Boolean(options.withoutCoupon);
    if (withoutCoupon) {
      resetSummaryToBaseline();
    }
    if (!canPreviewTotals() || !shouldFetchStoraPreview()) {
      updateLocalOrderSummary();
      return;
    }

    const ajaxUrl = getAjaxUrl();
    if (!ajaxUrl || !nonceEl) return;

    if (previewAbortController) {
      previewAbortController.abort();
    }
    previewAbortController = new AbortController();
    const requestId = ++previewRequestId;
    const signal = previewAbortController.signal;

    const body = new URLSearchParams();
    body.set("action", "stora_preview_totals");
    body.set("nonce", nonceEl.value || "");

    const unitIdEl = form.querySelector('input[name="unit_id"]');
    if (unitIdEl && unitIdEl.value) body.set("unit_id", unitIdEl.value);

    if (
      !withoutCoupon &&
      couponInput &&
      couponInput.classList.contains("is-valid") &&
      (couponInput.value || "").trim()
    ) {
      body.set("coupon_code", couponInput.value.trim());
    }

    const fullNameEl = form.querySelector('input[name="full_name"]');
    const emailEl = form.querySelector('input[name="email"]');
    const phoneEl = form.querySelector('input[name="phone"]');
    const moveInEl = getSelectedMoveInInput();
    if (fullNameEl && fullNameEl.value) body.set("full_name", fullNameEl.value);
    if (emailEl && emailEl.value) body.set("email", emailEl.value);
    if (phoneEl && phoneEl.value) body.set("phone", phoneEl.value);
    if (moveInEl && moveInEl.value) body.set("move_in", moveInEl.value);

    const protectionEl = form.querySelector('input[name="protection_level_id"]:checked');
    if (protectionEl && protectionEl.value) {
      body.set("protection_level_id", protectionEl.value);
    }

    try {
      const res = await fetch(ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: body.toString(),
        credentials: "same-origin",
        signal,
      });
      if (requestId !== previewRequestId) return;

      const json = await res.json().catch(() => null);
      if (json && json.success && json.data) {
        applySummaryData(json.data, { ignorePendingCoupon: withoutCoupon });
      }
    } catch (e) {
      if (e && e.name === "AbortError") return;
      // Keep PHP-estimated totals when preview is unavailable.
    }
  }

  function refreshOrderSummary(options = {}) {
    const immediate = Boolean(options.immediate);
    const withoutCoupon = Boolean(options.withoutCoupon);

    if (!shouldFetchStoraPreview()) {
      if (withoutCoupon) {
        resetSummaryToBaseline();
      } else {
        updateLocalOrderSummary();
      }
      return;
    }

    if (immediate) {
      if (previewDebounceTimer) {
        clearTimeout(previewDebounceTimer);
        previewDebounceTimer = null;
      }
      return runOrderSummaryPreview({ withoutCoupon });
    }

    if (previewDebounceTimer) {
      clearTimeout(previewDebounceTimer);
    }
    previewDebounceTimer = setTimeout(() => {
      previewDebounceTimer = null;
      runOrderSummaryPreview({ withoutCoupon });
    }, PREVIEW_DEBOUNCE_MS);
  }

  window.storaScheduleOrderSummaryRefresh = () => refreshOrderSummary();
  window.storaRefreshOrderSummary = (options) => refreshOrderSummary(options || {});
  window.storaRefreshOrderSummaryWithoutCoupon = () => refreshOrderSummary({ withoutCoupon: true });
  window.storaResetCouponSummaryToBaseline = resetSummaryToBaseline;
  window.storaApplyCouponSummaryData = (data) => applySummaryData(data, { ignorePendingCoupon: true });
  window.storaUpdateLocalOrderSummary = updateLocalOrderSummary;
  window.storaUpdateTotalDueLabel = updateTotalDueLabel;

  form.addEventListener("change", (e) => {
    const input = e.target;
    if (!(input instanceof HTMLInputElement) || input.name !== "move_in") return;
    updateTotalDueLabel();
    refreshOrderSummary();
  });
  form.addEventListener("input", (e) => {
    const input = e.target;
    if (!(input instanceof HTMLInputElement) || input.name !== "move_in") return;
    updateTotalDueLabel();
  });

  updateTotalDueLabel();
  syncSummaryTotalDisplay();
  captureInitialSummaryState();
})();

(() => {
  const wrap = document.querySelector("[data-stora-coupon]");
  if (!wrap) return;

  const input = wrap.querySelector("[data-stora-coupon-code]");
  const btn = wrap.querySelector("[data-stora-coupon-apply]");
  const msg = wrap.querySelector("[data-stora-coupon-msg]");
  const nonceEl = wrap.querySelector("[data-stora-coupon-nonce]");
  const form = document.querySelector("#stora_checkout_form");

  if (!input || !btn || !msg || !nonceEl) return;

  const setMsg = (text, kind) => {
    msg.textContent = text || "";
    msg.classList.toggle("is-success", kind === "success");
    msg.classList.toggle("is-error", kind === "error");
  };

  const setValidity = (state) => {
    input.classList.toggle("is-valid", state === "valid");
    input.classList.toggle("is-invalid", state === "invalid");
    if (state === "invalid") input.setAttribute("aria-invalid", "true");
    else input.removeAttribute("aria-invalid");
  };

  const getAjaxUrl = () => {
    if (window.ajaxurl) return window.ajaxurl;
    if (!form) return null;
    const actionUrl = form.getAttribute("action") || "";
    if (!actionUrl) return null;
    // admin-post.php -> admin-ajax.php
    return actionUrl.replace(/admin-post\.php(?:\?.*)?$/i, "admin-ajax.php");
  };

  const hasPositiveDiscount = (data) => (
    typeof window.storaHasPositiveDiscount === "function"
      ? window.storaHasPositiveDiscount(data)
      : Boolean((data && data.discount) || "")
  );

  const couponMsg = () => window.STORA_COUPON_MSG || {};
  const resolveCouponError = (message) => (
    typeof window.storaResolveCouponErrorMessage === "function"
      ? window.storaResolveCouponErrorMessage(message)
      : (message || couponMsg().apiError)
  );

  const refreshTotalsWithoutCoupon = async () => {
    if (typeof window.storaResetCouponSummaryToBaseline === "function") {
      window.storaResetCouponSummaryToBaseline();
    }
    if (typeof window.storaRefreshOrderSummaryWithoutCoupon === "function") {
      await window.storaRefreshOrderSummaryWithoutCoupon();
    }
  };

  async function validate() {
    const code = (input.value || "").trim();
    if (!code) {
      setMsg(couponMsg().emptyCode || "Please enter a coupon code.", "error");
      setValidity("invalid");
      await refreshTotalsWithoutCoupon();
      input.focus();
      return;
    }

    if (form && typeof window.storaGetCouponContactError === "function") {
      const contactError = window.storaGetCouponContactError(form);
      if (contactError) {
        setMsg(contactError, "error");
        setValidity("invalid");
        return;
      }
    }

    const ajaxUrl = getAjaxUrl();
    if (!ajaxUrl) {
      setMsg(couponMsg().apiError, "error");
      setValidity("invalid");
      return;
    }

    btn.disabled = true;
    setMsg("Checking coupon…", "");
    const hadAppliedCoupon = input.classList.contains("is-valid");
    setValidity("");
    if (hadAppliedCoupon && typeof window.storaResetCouponSummaryToBaseline === "function") {
      window.storaResetCouponSummaryToBaseline();
    }

    try {
      const summaryEl = document.querySelector("[data-stora-order-summary]");
      const baseTotalBefore = summaryEl
        ? parseInt(summaryEl.getAttribute("data-stora-base-total-amount") || "0", 10) || 0
        : 0;

      const body = new URLSearchParams();
      body.set("action", "stora_preview_totals");
      body.set("nonce", nonceEl.value || "");
      body.set("coupon_code", code);
      if (form) {
        const unitIdEl = form.querySelector('input[name="unit_id"]');
        if (unitIdEl && unitIdEl.value) body.set("unit_id", unitIdEl.value);

        const fullNameEl = form.querySelector('input[name="full_name"]');
        const emailEl = form.querySelector('input[name="email"]');
        const phoneEl = form.querySelector('input[name="phone"]');
        const moveInEl = form.querySelector('input[name="move_in"]:checked');
        if (fullNameEl && fullNameEl.value) body.set("full_name", fullNameEl.value);
        if (emailEl && emailEl.value) body.set("email", emailEl.value);
        if (phoneEl && phoneEl.value) body.set("phone", phoneEl.value);
        if (moveInEl && moveInEl.value) body.set("move_in", moveInEl.value);
      }

      const res = await fetch(ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: body.toString(),
        credentials: "same-origin",
      });

      const json = await res.json().catch(() => null);
      if (json && json.success && json.data) {
        const newTotalAmount = json.data.total_amount != null && json.data.total_amount !== ""
          ? parseInt(json.data.total_amount, 10) || 0
          : null;
        const couponApplied = hasPositiveDiscount(json.data) || (
          newTotalAmount !== null && newTotalAmount < baseTotalBefore
        );

        if (!couponApplied) {
          setMsg(couponMsg().invalidCode, "error");
          setValidity("invalid");
          await refreshTotalsWithoutCoupon();
          return;
        }

        setValidity("valid");
        if (typeof window.storaApplyCouponSummaryData === "function") {
          window.storaApplyCouponSummaryData(json.data);
        }
        setMsg(json.data.message || "Coupon applied.", "success");
        return;
      }

      if (json && json.data && json.data.message) {
        setMsg(resolveCouponError(json.data.message), "error");
        setValidity("invalid");
        await refreshTotalsWithoutCoupon();
        return;
      }

      setMsg(couponMsg().apiError, "error");
      setValidity("invalid");
      await refreshTotalsWithoutCoupon();
    } catch (e) {
      setMsg(couponMsg().apiError, "error");
      setValidity("invalid");
      await refreshTotalsWithoutCoupon();
    } finally {
      btn.disabled = false;
    }
  }

  btn.addEventListener("click", validate);
  input.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      validate();
    }
  });

  input.addEventListener("input", () => {
    // Clear previous validation state while editing.
    setMsg("", "");
    const wasValid = input.classList.contains("is-valid");
    setValidity("");
    const code = (input.value || "").trim();
    if (wasValid) {
      if (typeof window.storaResetCouponSummaryToBaseline === "function") {
        window.storaResetCouponSummaryToBaseline();
      }
      if (typeof window.storaRefreshOrderSummaryWithoutCoupon === "function") {
        window.storaRefreshOrderSummaryWithoutCoupon();
      } else if (typeof window.storaRefreshOrderSummary === "function") {
        window.storaRefreshOrderSummary();
      }
      return;
    }
    if (!code) {
      if (typeof window.storaResetCouponSummaryToBaseline === "function") {
        window.storaResetCouponSummaryToBaseline();
      }
      if (typeof window.storaRefreshOrderSummaryWithoutCoupon === "function") {
        window.storaRefreshOrderSummaryWithoutCoupon();
      }
      return;
    }
    const discountRowEl = document.querySelector("[data-stora-summary-discount-row]");
    const discountValueEl = document.querySelector("[data-stora-summary-discount-value]");
    if (discountRowEl) discountRowEl.hidden = true;
    if (discountValueEl) discountValueEl.textContent = "-";
  });
})();

(() => {
  const picker = document.querySelector("[data-stora-move-in-picker]");
  if (!picker) return;

  const form = document.querySelector("#stora_checkout_form");
  const selectedEl = picker.querySelector("[data-stora-move-in-selected]");
  let optionsContainer = picker.querySelector(".stora-checkout__move-in-options");
  let loadPromise = null;

  const getContinueButton = () => {
    const step = picker.closest("[data-stora-checkout-step]");
    return step ? step.querySelector("[data-stora-checkout-next]") : null;
  };

  const formatSelectedLabel = (iso) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec((iso || "").trim());
    if (!match) return iso;
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    if (Number.isNaN(date.getTime())) return iso;
    return date.toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" });
  };

  const formatMonthLabel = (iso) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec((iso || "").trim());
    if (!match) return "";
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    if (Number.isNaN(date.getTime())) return "";
    return date.toLocaleDateString("en-GB", { month: "short" });
  };

  const syncSelectedState = (input) => {
    const options = Array.from(picker.querySelectorAll(".stora-checkout__move-in-option"));
    options.forEach((option) => {
      const radio = option.querySelector('input[name="move_in"]');
      option.classList.toggle("is-selected", Boolean(radio && radio.checked));
    });

    if (!selectedEl || !input) return;
    const value = (input.value || "").trim();
    if (!value) {
      selectedEl.hidden = true;
      selectedEl.textContent = "";
      return;
    }
    selectedEl.hidden = false;
    selectedEl.textContent = `Selected: ${formatSelectedLabel(value)}`;
  };

  const bindMoveInOption = (option) => {
    const input = option.querySelector('input[name="move_in"]');
    if (!input || input.dataset.storaMoveInBound === "1") return;
    input.dataset.storaMoveInBound = "1";
    input.addEventListener("change", () => {
      input.classList.remove("is-invalid");
      syncSelectedState(input);
      input.dispatchEvent(new Event("input", { bubbles: true }));
    });
  };

  const bindAllMoveInOptions = () => {
    Array.from(picker.querySelectorAll(".stora-checkout__move-in-option")).forEach(bindMoveInOption);
  };

  const setPickerMessage = (text, isError = false) => {
    if (!optionsContainer) {
      optionsContainer = document.createElement("div");
      optionsContainer.className = "stora-checkout__move-in-options";
      const hint = picker.querySelector(".stora-checkout__move-in-hint");
      if (hint) {
        hint.replaceWith(optionsContainer);
      } else {
        picker.insertBefore(optionsContainer, selectedEl);
      }
    }
    optionsContainer.innerHTML = "";
    const p = document.createElement("p");
    p.className = isError ? "stora-checkout__move-in-hint stora-checkout__move-in-hint--error" : "stora-checkout__move-in-hint";
    p.textContent = text;
    optionsContainer.appendChild(p);
    const continueBtn = getContinueButton();
    if (continueBtn) continueBtn.disabled = true;
  };

  const renderMoveInDates = (dates) => {
    if (!Array.isArray(dates) || !dates.length) {
      setPickerMessage("Move-in dates are unavailable right now. Please contact us to complete your booking.", true);
      return;
    }

    if (!optionsContainer) {
      optionsContainer = document.createElement("div");
      optionsContainer.className = "stora-checkout__move-in-options";
      const hint = picker.querySelector(".stora-checkout__move-in-hint");
      if (hint) {
        hint.replaceWith(optionsContainer);
      } else {
        picker.insertBefore(optionsContainer, selectedEl);
      }
    }

    optionsContainer.innerHTML = "";
    dates.forEach((moveInDate, index) => {
      if (!moveInDate || !moveInDate.value) return;

      const moveInValue = String(moveInDate.value).trim();
      const moveInDay = moveInDate.day != null ? String(parseInt(moveInDate.day, 10)) : moveInValue.slice(-2).replace(/^0/, "");
      const moveInWeekday = moveInDate.weekday ? String(moveInDate.weekday) : formatSelectedLabel(moveInValue).split(" ")[0];
      const moveInMonth = formatMonthLabel(moveInValue);
      const isDefault = index === 0;

      const label = document.createElement("label");
      label.className = `stora-checkout__move-in-option${isDefault ? " is-selected" : ""}`;

      const input = document.createElement("input");
      input.type = "radio";
      input.name = "move_in";
      input.value = moveInValue;
      input.className = "stora-checkout__move-in-input";
      input.required = true;
      input.setAttribute("data-stora-required", "");
      input.setAttribute("data-stora-step", "4");
      if (isDefault) input.checked = true;

      const card = document.createElement("span");
      card.className = "stora-checkout__move-in-card";
      const day = document.createElement("span");
      day.className = "stora-checkout__move-in-day";
      day.textContent = moveInDay;
      const meta = document.createElement("span");
      meta.className = "stora-checkout__move-in-meta";
      meta.textContent = `${moveInWeekday} ${moveInMonth}`.trim();
      card.appendChild(day);
      card.appendChild(meta);

      label.appendChild(input);
      label.appendChild(card);
      optionsContainer.appendChild(label);
      bindMoveInOption(label);
    });

    const continueBtn = getContinueButton();
    if (continueBtn) continueBtn.disabled = false;

    const checked = picker.querySelector('input[name="move_in"]:checked');
    if (checked) {
      syncSelectedState(checked);
      if (typeof window.storaUpdateTotalDueLabel === "function") {
        window.storaUpdateTotalDueLabel();
      }
      if (typeof window.storaScheduleOrderSummaryRefresh === "function") {
        window.storaScheduleOrderSummaryRefresh();
      }
    }
  };

  const getAjaxUrl = () => {
    if (window.ajaxurl) return window.ajaxurl;
    if (!form) return null;
    const actionUrl = form.getAttribute("action") || "";
    if (!actionUrl) return null;
    return actionUrl.replace(/admin-post\.php(?:\?.*)?$/i, "admin-ajax.php");
  };

  const loadDeferredMoveInDates = () => {
    if (picker.dataset.storaMoveInLoaded === "1" || picker.dataset.storaMoveInDeferred !== "1") {
      return Promise.resolve();
    }
    if (loadPromise) return loadPromise;

    const unitId = (picker.dataset.storaUnitId || "").trim();
    const nonceEl = document.querySelector("[data-stora-coupon-nonce]");
    const ajaxUrl = getAjaxUrl();
    if (!unitId || !nonceEl || !ajaxUrl) {
      setPickerMessage("Move-in dates are unavailable right now. Please try again in a moment.", true);
      return Promise.resolve();
    }

    setPickerMessage("Loading available move-in dates…");
    const continueBtn = getContinueButton();
    if (continueBtn) continueBtn.disabled = true;

    loadPromise = (async () => {
      try {
        const body = new URLSearchParams();
        body.set("action", "stora_move_in_dates");
        body.set("nonce", nonceEl.value || "");
        body.set("unit_id", unitId);

        const res = await fetch(ajaxUrl, {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
          body: body.toString(),
          credentials: "same-origin",
        });
        const json = await res.json().catch(() => null);
        if (json && json.success && json.data && Array.isArray(json.data.dates)) {
          picker.dataset.storaMoveInLoaded = "1";
          renderMoveInDates(json.data.dates);
          return;
        }

        const message = (json && json.data && json.data.message)
          ? String(json.data.message)
          : "Move-in dates are unavailable right now. Please try again in a moment.";
        setPickerMessage(message, true);
      } catch (e) {
        setPickerMessage("Move-in dates are unavailable right now. Please try again in a moment.", true);
      } finally {
        loadPromise = null;
      }
    })();

    return loadPromise;
  };

  window.storaLoadMoveInDates = loadDeferredMoveInDates;

  bindAllMoveInOptions();

  const checked = picker.querySelector('input[name="move_in"]:checked');
  if (checked) {
    syncSelectedState(checked);
    checked.dispatchEvent(new Event("input", { bubbles: true }));
  }

  if (form) {
    form.addEventListener("stora:checkout-step", (e) => {
      if (e.detail && Number(e.detail.step) === 4) {
        loadDeferredMoveInDates();
      }
    });
  }
})();
