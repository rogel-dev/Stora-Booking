(() => {
  const toggle = document.querySelector("[data-stora-category-toggle]");
  if (!toggle) return;

  const buttons = Array.from(toggle.querySelectorAll("[data-category]"));
  const units = Array.from(document.querySelectorAll(".stora-unit[data-category]"));

  function setActive(category) {
    buttons.forEach((b) => b.classList.toggle("is-active", b.dataset.category === category));
    units.forEach((u) => {
      const match = category === "all" || u.dataset.category === category;
      u.style.display = match ? "" : "none";
    });
  }

  toggle.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-category]");
    if (!btn) return;
    setActive(btn.dataset.category || "all");
  });
})();

(() => {
  const toggle = document.querySelector("[data-stora-unit-type-toggle]");
  if (!toggle) return;

  const root = toggle.closest(".stora-units") || document;
  const buttons = Array.from(toggle.querySelectorAll("[data-unit-type]"));
  const units = Array.from(root.querySelectorAll(".stora-unit[data-unit-type-id]"));

  function setActive(unitTypeId) {
    buttons.forEach((b) => b.classList.toggle("is-active", b.dataset.unitType === unitTypeId));
    units.forEach((u) => {
      const id = u.dataset.unitTypeId || "";
      const match = unitTypeId === "all" || id === unitTypeId;
      u.style.display = match ? "" : "none";
    });
  }

  toggle.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-unit-type]");
    if (!btn) return;
    setActive(btn.dataset.unitType || "all");
  });
})();

(() => {
  const form = document.querySelector("[data-stora-checkout-form]");
  if (!form) return;

  const requiredInputs = Array.from(form.querySelectorAll("[data-stora-required]"));
  const email = form.querySelector("[data-stora-email]");
  const emailConfirm = form.querySelector("[data-stora-email-confirm]");
  const phone = form.querySelector("[data-stora-phone]");
  const phoneConfirm = form.querySelector("[data-stora-phone-confirm]");

  const normalizePhone = (v) => (v || "").toString().replace(/\D+/g, "");

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
    const v = (input.value || "").trim();
    if (!v) {
      setError(input, "This field is required.");
      return false;
    }
    setError(input, "");
    return true;
  }

  function validateEmailMatch() {
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

  requiredInputs.forEach((input) => {
    input.addEventListener("blur", () => validateRequired(input));
    input.addEventListener("input", () => {
      if (input.classList.contains("is-invalid")) validateRequired(input);
      if (input === email || input === emailConfirm) validateEmailMatch();
      if (input === phone || input === phoneConfirm) validatePhoneMatch();
    });
  });

  form.addEventListener("submit", (e) => {
    let ok = true;
    requiredInputs.forEach((input) => {
      ok = validateRequired(input) && ok;
    });
    ok = validateEmailMatch() && ok;
    ok = validatePhoneMatch() && ok;

    if (!ok) {
      e.preventDefault();
      const firstInvalid = form.querySelector(".is-invalid");
      if (firstInvalid) firstInvalid.focus({ preventScroll: false });
    }
  });
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

  async function validate() {
    const code = (input.value || "").trim();
    if (!code) {
      setMsg("Please enter a coupon code.", "error");
      setValidity("invalid");
      input.focus();
      return;
    }

    const ajaxUrl = getAjaxUrl();
    if (!ajaxUrl) {
      setMsg("Unable to validate coupon right now.", "error");
      setValidity("invalid");
      return;
    }

    btn.disabled = true;
    setMsg("Checking coupon…", "");
    setValidity("");

    try {
      const body = new URLSearchParams();
      body.set("action", "stora_validate_coupon");
      body.set("nonce", nonceEl.value || "");
      body.set("coupon_code", code);
      if (form) {
        const unitIdEl = form.querySelector('input[name="unit_id"]');
        if (unitIdEl && unitIdEl.value) body.set("unit_id", unitIdEl.value);
      }

      const res = await fetch(ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: body.toString(),
        credentials: "same-origin",
      });

      const json = await res.json().catch(() => null);
      if (!json || typeof json.success !== "boolean") {
        setMsg("Unable to validate coupon right now.", "error");
        setValidity("invalid");
        return;
      }

      if (json.success) {
        // Only treat the coupon as "applied" once we have recalculated totals from Stora.
        const subtotalEl = document.querySelector("[data-stora-summary-subtotal]");
        const totalEl = document.querySelector("[data-stora-summary-total]");
        const rentEl = document.querySelector("[data-stora-summary-rent]");
        const discountRow = document.querySelector("[data-stora-summary-discount-row]");
        const discountLabel = document.querySelector("[data-stora-summary-discount-label]");
        const discountValue = document.querySelector("[data-stora-summary-discount-value]");

        if (form && (subtotalEl || totalEl || rentEl)) {
          const body2 = new URLSearchParams();
          body2.set("action", "stora_preview_totals");
          body2.set("nonce", nonceEl.value || "");
          body2.set("coupon_code", code);
          const unitIdEl = form.querySelector('input[name="unit_id"]');
          if (unitIdEl && unitIdEl.value) body2.set("unit_id", unitIdEl.value);

          const fullNameEl = form.querySelector('input[name="full_name"]');
          const emailEl = form.querySelector('input[name="email"]');
          const phoneEl = form.querySelector('input[name="phone"]');
          const moveInEl = form.querySelector('input[name="move_in"]');
          if (fullNameEl && fullNameEl.value) body2.set("full_name", fullNameEl.value);
          if (emailEl && emailEl.value) body2.set("email", emailEl.value);
          if (phoneEl && phoneEl.value) body2.set("phone", phoneEl.value);
          if (moveInEl && moveInEl.value) body2.set("move_in", moveInEl.value);

          const res2 = await fetch(ajaxUrl, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
            body: body2.toString(),
            credentials: "same-origin",
          });
          const json2 = await res2.json().catch(() => null);
          if (json2 && json2.success && json2.data) {
            if (rentEl && json2.data.subtotal) rentEl.textContent = json2.data.subtotal;
            if (subtotalEl && json2.data.subtotal) subtotalEl.textContent = json2.data.subtotal;
            if (totalEl && json2.data.total) totalEl.textContent = json2.data.total;
            if (discountRow && discountLabel && discountValue) {
              const d = json2.data.discount || "";
              if (d) {
                discountRow.hidden = false;
                discountLabel.textContent = "Discount";
                discountValue.textContent = "-" + d.replace(/^\-+/, "");
              } else {
                discountRow.hidden = true;
              }
            }

            setMsg((json.data && json.data.message) || "Coupon applied.", "success");
            setValidity("valid");
          } else if (json2 && json2.data && json2.data.message) {
            // Coupon exists, but we couldn't confirm it against a priced order yet.
            setMsg(json2.data.message, "error");
            setValidity("invalid");
            if (discountRow) discountRow.hidden = true;
            return;
          } else {
            setMsg("Unable to calculate total right now.", "error");
            setValidity("invalid");
            if (discountRow) discountRow.hidden = true;
            return;
          }
        } else {
          // No summary elements found; still show coupon success.
          setMsg((json.data && json.data.message) || "Coupon applied.", "success");
          setValidity("valid");
        }
      } else {
        setMsg((json.data && json.data.message) || "Coupon code is invalid.", "error");
        setValidity("invalid");

        const discountRow = document.querySelector("[data-stora-summary-discount-row]");
        if (discountRow) discountRow.hidden = true;
      }
    } catch (e) {
      setMsg("Unable to validate coupon right now.", "error");
      setValidity("invalid");

      const discountRow = document.querySelector("[data-stora-summary-discount-row]");
      if (discountRow) discountRow.hidden = true;
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
    setValidity("");
    const discountRow = document.querySelector("[data-stora-summary-discount-row]");
    if (discountRow) discountRow.hidden = true;
  });
})();
