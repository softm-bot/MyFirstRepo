(function () {
  var root = document.documentElement;
  var key = "pntd_theme";
  var allowed = [];

  function readAllowed() {
    var box = document.querySelector(".theme-switch[data-themes]");
    if (!box) return allowed;
    return (box.getAttribute("data-themes") || "").split(",").map(function (s) {
      return s.trim();
    }).filter(Boolean);
  }

  function setTheme(id) {
    var list = readAllowed();
    if (list.indexOf(id) === -1) return false;
    root.setAttribute("data-theme", id);
    try { localStorage.setItem(key, id); } catch (e) {}
    document.cookie = key + "=" + encodeURIComponent(id) + "; path=/pntd/; max-age=31536000; samesite=lax";
    document.querySelectorAll("[data-theme-set]").forEach(function (el) {
      var on = el.getAttribute("data-theme-set") === id;
      el.classList.toggle("is-active", on);
      if (on) el.setAttribute("aria-current", "true");
      else el.removeAttribute("aria-current");
    });
    return true;
  }

  document.addEventListener("click", function (e) {
    var t = e.target;
    if (!(t instanceof Element)) return;
    var btn = t.closest("[data-theme-set]");
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    var id = btn.getAttribute("data-theme-set") || "";
    if (!setTheme(id)) return;
    if (window.history && window.history.replaceState) {
      try {
        var url = new URL(window.location.href);
        url.searchParams.set("theme", id);
        window.history.replaceState({}, "", url.toString());
      } catch (err) {}
    }
  });

  var NATURAL_MSG = "Цена должна быть натуральным числом: 1, 2, 3 … Без нуля, дробей, букв и текста.";

  function priceInputs(form) {
    return Array.prototype.slice.call(form.querySelectorAll(".price-input"));
  }

  function normalizePrice(raw) {
    return String(raw || "").replace(/\u00a0/g, " ").trim().replace(/\s+/g, "");
  }

  function isNaturalPrice(raw) {
    return /^[1-9]\d*$/.test(normalizePrice(raw));
  }

  function isBlankPrice(raw) {
    return normalizePrice(raw) === "";
  }

  function priceError(input) {
    var raw = input.value;
    if (isBlankPrice(raw)) return null;
    if (!isNaturalPrice(raw)) {
      return { message: NATURAL_MSG, short: "не натуральное число" };
    }
    return null;
  }

  function isAllowedPrice(input) {
    return !priceError(input);
  }

  function fieldLabel(input) {
    var td = input.closest("td");
    if (td) {
      var sku = td.querySelector(".cell-sku");
      if (sku && sku.textContent.trim()) return sku.textContent.trim();
    }
    var hid = input.parentNode ? input.parentNode.querySelector('input[name="software_code[]"], input[name="base_article[]"]') : null;
    if (hid && hid.value) return hid.value;
    var row = input.closest("tr");
    var article = row ? row.querySelector(".catalog-article") : null;
    if (article instanceof HTMLInputElement && article.value.trim()) return article.value.trim();
    var codeCell = row ? row.querySelector(".col-code, td.mono") : null;
    if (codeCell && codeCell.textContent.trim()) return codeCell.textContent.trim();
    return "поле";
  }

  function markPriceState(input) {
    var bad = !isAllowedPrice(input);
    input.classList.toggle("is-invalid", bad);
    return bad;
  }

  function originalPrice(input) {
    return input.getAttribute("data-original") || "";
  }

  function formIsDirty(form) {
    if (form.getAttribute("data-catalog-dirty") === "1") return true;
    return priceInputs(form).some(function (input) {
      return normalizePrice(input.value) !== originalPrice(input);
    });
  }

  function formCanSave(form) {
    if (!formIsDirty(form)) return false;
    return priceInputs(form).every(isAllowedPrice);
  }

  function syncSaveButton(form) {
    var btn = form.querySelector(".price-save");
    if (!btn) return;
    var ok = formCanSave(form);
    btn.hidden = !ok;
    btn.disabled = !ok;
  }

  function invalidInputs(form) {
    return priceInputs(form).filter(function (input) {
      return markPriceState(input);
    });
  }

  function ensureModal() {
    var box = document.getElementById("pntd-modal");
    if (box) return box;
    box = document.createElement("div");
    box.id = "pntd-modal";
    box.className = "pntd-modal";
    box.hidden = true;
    box.innerHTML =
      '<div class="pntd-modal-backdrop" data-modal-dismiss="1"></div>' +
      '<div class="pntd-modal-dialog" role="alertdialog" aria-modal="true" aria-labelledby="pntd-modal-title">' +
      '<h2 id="pntd-modal-title">Некорректная цена</h2>' +
      '<p class="pntd-modal-text"></p>' +
      '<ul class="pntd-modal-list"></ul>' +
      '<button type="button" class="btn pntd-modal-ok">Понятно</button>' +
      "</div>";
    document.body.appendChild(box);
    return box;
  }

  function bindBasesCatalog(form, syncSaveButton) {
    var body = form.querySelector("[data-bases-body]");
    var tpl = document.getElementById("base-row-template");
    if (!body) return;

    function markDirty() {
      form.setAttribute("data-catalog-dirty", "1");
      syncSaveButton(form);
    }

    function nextIndex() {
      var n = parseInt(body.getAttribute("data-next-index") || "0", 10);
      if (!isFinite(n) || n < 0) n = body.querySelectorAll("[data-base-row]").length;
      body.setAttribute("data-next-index", String(n + 1));
      return n;
    }

    function reindex(tr, index) {
      tr.querySelectorAll("[name]").forEach(function (el) {
        var name = el.getAttribute("name") || "";
        el.setAttribute("name", name.replace(/bases\[\d+\]/, "bases[" + index + "]"));
      });
    }

    form.addEventListener("click", function (e) {
      var t = e.target;
      if (!(t instanceof Element)) return;
      var rm = t.closest("[data-base-remove]");
      if (rm) {
        var tr = rm.closest("[data-base-row]");
        if (tr) tr.remove();
        markDirty();
        return;
      }
      if (!t.closest("[data-base-add]")) return;
      if (!(tpl instanceof HTMLTemplateElement) || !tpl.content) return;
      var node = tpl.content.cloneNode(true);
      var tr = node.querySelector("[data-base-row]");
      if (!tr) return;
      reindex(tr, nextIndex());
      tr.querySelectorAll(".price-input").forEach(function (input) {
        if (!(input instanceof HTMLInputElement)) return;
        input.setAttribute("data-original", "");
        input.value = "";
        markPriceState(input);
      });
      body.appendChild(node);
      markDirty();
      var focus = tr.querySelector(".catalog-article");
      if (focus instanceof HTMLInputElement) focus.focus();
    });
  }

  function bindPriceForms() {
    var forms = document.querySelectorAll("form.price-form");
    if (!forms.length) return;

    var modal = ensureModal();
    var textEl = modal.querySelector(".pntd-modal-text");
    var listEl = modal.querySelector(".pntd-modal-list");
    var okBtn = modal.querySelector(".pntd-modal-ok");
    var invalidInput = null;
    var restoring = false;

    function closeModal() {
      modal.hidden = true;
      var input = invalidInput;
      invalidInput = null;
      if (input && document.contains(input)) {
        restoring = true;
        input.focus();
        if (typeof input.select === "function") input.select();
        restoring = false;
      }
    }

    function openModal(input, message, items) {
      invalidInput = input;
      textEl.textContent = message;
      listEl.innerHTML = "";
      (items || []).forEach(function (item) {
        var li = document.createElement("li");
        var code = document.createElement("code");
        code.textContent = item.label;
        li.appendChild(code);
        li.appendChild(document.createTextNode(" — «" + item.value + "»"));
        listEl.appendChild(li);
      });
      listEl.hidden = !items || !items.length;
      modal.hidden = false;
      okBtn.focus();
    }

    function showInvalid(input) {
      var err = priceError(input);
      openModal(input, err ? err.message : NATURAL_MSG, [{
        label: fieldLabel(input),
        value: String(input.value || "")
      }]);
      input.classList.add("is-invalid");
      markPriceState(input);
    }

    function showSaveInvalid(form) {
      var bad = invalidInputs(form);
      if (!bad.length) return false;
      var items = bad.map(function (input) {
        var err = priceError(input);
        return {
          label: fieldLabel(input),
          value: String(input.value || "") + (err && err.short ? " — " + err.short : "")
        };
      });
      openModal(bad[0], "Сохранить нельзя. Стоимость — натуральное число (1, 2, 3 …).", items);
      return true;
    }

    function rejectLeave(input) {
      var owner = input.form;
      if (isAllowedPrice(input)) {
        markPriceState(input);
        if (!isBlankPrice(input.value)) {
          var norm = normalizePrice(input.value);
          if (input.value !== norm) input.value = norm;
        }
        if (owner) syncSaveButton(owner);
        return false;
      }
      showInvalid(input);
      if (owner) syncSaveButton(owner);
      return true;
    }

    modal.addEventListener("click", function (e) {
      var t = e.target;
      if (!(t instanceof Element)) return;
      if (t.classList.contains("pntd-modal-ok") || t.getAttribute("data-modal-dismiss")) {
        closeModal();
      }
    });
    document.addEventListener("keydown", function (e) {
      if (modal.hidden) return;
      if (e.key === "Escape" || e.key === "Enter") {
        e.preventDefault();
        closeModal();
      }
    });

    forms.forEach(function (form) {
      form.setAttribute("novalidate", "novalidate");

      form.addEventListener("input", function (e) {
        if (!(e.target instanceof HTMLInputElement)) return;
        if (e.target.classList.contains("catalog-input")) {
          form.setAttribute("data-catalog-dirty", "1");
          syncSaveButton(form);
          return;
        }
        if (!e.target.classList.contains("price-input")) return;
        markPriceState(e.target);
        syncSaveButton(form);
      });

      form.addEventListener("keydown", function (e) {
        if (e.key !== "Enter") return;
        if (!(e.target instanceof HTMLInputElement) || !e.target.classList.contains("price-input")) return;
        e.preventDefault();
        rejectLeave(e.target);
      });

      form.addEventListener("keydown", function (e) {
        if (e.key !== "Tab") return;
        if (!(e.target instanceof HTMLInputElement) || !e.target.classList.contains("price-input")) return;
        if (rejectLeave(e.target)) e.preventDefault();
      });

      form.addEventListener("mousedown", function (e) {
        if (restoring) return;
        var current = document.activeElement;
        if (!(current instanceof HTMLInputElement) || !current.classList.contains("price-input")) return;
        var t = e.target;
        if (!(t instanceof Element)) return;
        var next = t.closest(".price-input");
        if (!next || next === current) return;
        if (rejectLeave(current)) {
          e.preventDefault();
          e.stopPropagation();
        }
      }, true);

      form.addEventListener("click", function (e) {
        var t = e.target;
        if (!(t instanceof Element)) return;
        var btn = t.closest("button[type=submit], input[type=submit]");
        if (!btn || !form.contains(btn)) return;
        if (showSaveInvalid(form)) {
          e.preventDefault();
          e.stopPropagation();
        }
      }, true);

      form.addEventListener("submit", function (e) {
        if (!formCanSave(form) || showSaveInvalid(form)) e.preventDefault();
      });

      priceInputs(form).forEach(function (input) {
        input.setAttribute("data-original", normalizePrice(input.value));
        markPriceState(input);
      });
      if (form.hasAttribute("data-bases-form")) {
        bindBasesCatalog(form, syncSaveButton);
      }
      syncSaveButton(form);
    });
  }

  function formatKitMoney(n) {
    var rounded = Math.round(n);
    var s = String(rounded);
    return s.replace(/\B(?=(\d{3})+(?!\d))/g, " ") + " ₽";
  }

  function kitLinePrice(tr) {
    var raw = tr.getAttribute("data-price");
    if (raw === "" || raw == null) return null;
    var n = parseFloat(raw);
    return isFinite(n) ? n : null;
  }

  function kitLineQty(tr) {
    var input = tr.querySelector("[data-kit-qty]");
    if (input instanceof HTMLInputElement) {
      var q = parseInt(input.value, 10);
      if (!isFinite(q) || q < 1) q = 1;
      if (q > 9999) q = 9999;
      input.value = String(q);
      return q;
    }
    var out = tr.querySelector("[data-kit-qty-out]");
    var n = parseInt(out ? out.textContent : "1", 10);
    return isFinite(n) && n > 0 ? n : 1;
  }

  function bindKitBox(box) {
    var monthsEl = box.querySelector("[data-kit-months]");
    var outEl = box.querySelector("[data-kit-months-out]");
    var payEl = box.querySelector("[data-kit-pay]");
    var formulaEl = box.querySelector("[data-kit-formula]");
    var openEl = box.querySelector("[data-kit-open]");
    var body = box.querySelector("[data-kit-body]");
    if (!(monthsEl instanceof HTMLInputElement) || !payEl) return;
    var discount = parseFloat(box.getAttribute("data-discount") || "40");
    var min = parseInt(monthsEl.min || "4", 10);
    var max = parseInt(monthsEl.max || "100", 10);
    var caseId = box.getAttribute("data-kit-id") || "";
    if (!isFinite(min)) min = 4;
    if (!isFinite(max)) max = 100;
    if (!isFinite(discount)) discount = 40;

    function render() {
      var m = parseInt(monthsEl.value, 10);
      if (!isFinite(m)) m = 12;
      if (m < min) m = min;
      if (m > max) m = max;
      monthsEl.value = String(m);
      if (outEl) outEl.textContent = String(m);
      var monthly = 0;
      var open = false;
      var priced = false;
      box.querySelectorAll("[data-kit-line]").forEach(function (tr) {
        var price = kitLinePrice(tr);
        var qty = kitLineQty(tr);
        var sumEl = tr.querySelector("[data-kit-sum]");
        if (price === null) {
          open = true;
          if (sumEl) sumEl.textContent = "по запросу";
        } else {
          priced = true;
          monthly += price * qty;
          if (sumEl) sumEl.textContent = formatKitMoney(price * qty);
        }
      });
      if (!priced) {
        payEl.textContent = "по запросу";
        if (formulaEl) formulaEl.textContent = "";
      } else {
        var gross = monthly * m;
        var off = m >= 12 ? gross * (discount / 100) : 0;
        payEl.textContent = formatKitMoney(gross - off);
        if (formulaEl) {
          var disc = m >= 12 ? " − " + String(discount).replace(/\.0$/, "") + "%" : "";
          formulaEl.textContent = "(" + formatKitMoney(monthly) + " × " + m + disc + ")";
        }
      }
      if (openEl) {
        var discLabel = String(discount).replace(/\.0$/, "") + "%";
        openEl.textContent = open
          ? "Часть позиций — по запросу; в сумму входят только заданные цены. Скидка " + discLabel + " при сроке 12 месяцев и более."
          : "Скидка " + discLabel + " при сроке 12 месяцев и более — как в каталоге при годовом продлении.";
      }
    }

    function existingLine(code, kind, tariff) {
      var found = null;
      box.querySelectorAll("[data-kit-line]").forEach(function (tr) {
        var hidden = tr.querySelector('input[type="hidden"][name*="[code]"]');
        var kindEl = tr.querySelector('input[type="hidden"][name*="[kind]"]');
        var tariffEl = tr.querySelector('input[type="hidden"][name*="[tariff]"]');
        var rowKind = kindEl ? kindEl.value : "software";
        var rowTariff = tariffEl ? tariffEl.value : "";
        if (hidden && hidden.value === code && rowKind === kind && rowTariff === (tariff || "")) found = tr;
      });
      return found;
    }

    function addLine(opt, wrap) {
      if (!(opt instanceof HTMLOptionElement) || !opt.value || !body) return;
      var code = opt.value;
      var kind = opt.getAttribute("data-kind") || "software";
      var tariff = "";
      var priceRaw = opt.getAttribute("data-price") || "";
      var name = opt.getAttribute("data-name") || code;
      var rangeSel = wrap ? wrap.querySelector("[data-kit-base-range]") : null;
      if (kind === "base" && rangeSel instanceof HTMLSelectElement) {
        tariff = rangeSel.value || "price_local";
        var map = {};
        try { map = JSON.parse(opt.getAttribute("data-tariffs") || "{}"); } catch (err) { map = {}; }
        if (Object.prototype.hasOwnProperty.call(map, tariff)) {
          priceRaw = map[tariff] == null || map[tariff] === "" ? "" : String(map[tariff]);
        }
        var rangeOpt = rangeSel.options[rangeSel.selectedIndex];
        var rangeLabel = rangeOpt ? String(rangeOpt.textContent || "").trim() : "";
        if (rangeLabel) name = name + " · " + rangeLabel;
      }
      var found = existingLine(code, kind, tariff);
      if (found) {
        var qty = found.querySelector("[data-kit-qty]");
        if (qty instanceof HTMLInputElement) {
          qty.value = String((parseInt(qty.value, 10) || 1) + 1);
        }
        render();
        return;
      }
      var tr = document.createElement("tr");
      tr.setAttribute("data-kit-line", "");
      tr.setAttribute("data-price", priceRaw);
      var tdCode = document.createElement("td");
      tdCode.className = "mono col-code";
      tdCode.appendChild(document.createTextNode(code));
      var hidden = document.createElement("input");
      hidden.type = "hidden";
      hidden.name = "case[" + caseId + "][code][]";
      hidden.value = code;
      tdCode.appendChild(hidden);
      var kindHidden = document.createElement("input");
      kindHidden.type = "hidden";
      kindHidden.name = "case[" + caseId + "][kind][]";
      kindHidden.value = kind;
      tdCode.appendChild(kindHidden);
      var tariffHidden = document.createElement("input");
      tariffHidden.type = "hidden";
      tariffHidden.name = "case[" + caseId + "][tariff][]";
      tariffHidden.value = tariff;
      tdCode.appendChild(tariffHidden);
      var tdName = document.createElement("td");
      tdName.className = "col-name";
      tdName.textContent = name;
      var tdQty = document.createElement("td");
      tdQty.className = "num";
      var qty = document.createElement("input");
      qty.className = "kit-qty";
      qty.setAttribute("data-kit-qty", "");
      qty.type = "number";
      qty.min = "1";
      qty.max = "9999";
      qty.step = "1";
      qty.name = "case[" + caseId + "][qty][]";
      qty.value = "1";
      tdQty.appendChild(qty);
      var tdUnit = document.createElement("td");
      tdUnit.className = "price";
      tdUnit.setAttribute("data-kit-unit", "");
      tdUnit.textContent = priceRaw === "" ? "по запросу" : formatKitMoney(parseFloat(priceRaw));
      var tdSum = document.createElement("td");
      tdSum.className = "price";
      tdSum.setAttribute("data-kit-sum", "");
      var tdAct = document.createElement("td");
      tdAct.className = "kit-act";
      var rm = document.createElement("button");
      rm.type = "button";
      rm.className = "btn-ghost";
      rm.setAttribute("data-kit-remove", "");
      rm.setAttribute("aria-label", "Убрать");
      rm.textContent = "×";
      tdAct.appendChild(rm);
      tr.appendChild(tdCode);
      tr.appendChild(tdName);
      tr.appendChild(tdQty);
      tr.appendChild(tdUnit);
      tr.appendChild(tdSum);
      tr.appendChild(tdAct);
      body.appendChild(tr);
      render();
    }

    monthsEl.addEventListener("change", render);
    monthsEl.addEventListener("input", render);
    box.addEventListener("input", function (e) {
      if (e.target instanceof HTMLInputElement && e.target.hasAttribute("data-kit-qty")) render();
    });
    box.addEventListener("click", function (e) {
      var t = e.target;
      if (!(t instanceof Element)) return;
      var rm = t.closest("[data-kit-remove]");
      if (rm) {
        var tr = rm.closest("[data-kit-line]");
        if (tr) tr.remove();
        render();
        return;
      }
      var addBtn = t.closest("[data-kit-add-btn]");
      if (!addBtn) return;
      var wrap = addBtn.closest(".kit-add");
      var select = wrap ? wrap.querySelector("[data-kit-add]") : null;
      if (!(select instanceof HTMLSelectElement)) return;
      var opt = select.options[select.selectedIndex];
      addLine(opt, wrap);
      select.selectedIndex = 0;
    });
    render();
  }

  function bindKitExample() {
    document.querySelectorAll("[data-kit]").forEach(bindKitBox);
  }

  function bindCopyForms() {
    document.querySelectorAll("form.copy-form").forEach(function (form) {
      var btn = form.querySelector(".price-save");
      function fields() {
        return Array.prototype.slice.call(form.querySelectorAll(".copy-input"));
      }
      fields().forEach(function (el) {
        if (!el.hasAttribute("data-original")) el.setAttribute("data-original", el.value);
      });
      function dirty() {
        return fields().some(function (el) {
          return String(el.value) !== String(el.getAttribute("data-original") || "");
        });
      }
      function sync() {
        if (!btn) return;
        var ok = dirty();
        btn.hidden = !ok;
        btn.disabled = !ok;
      }
      form.addEventListener("input", sync);
      form.addEventListener("change", sync);
      sync();
    });
  }

  function startUi() {
    bindPriceForms();
    bindCopyForms();
    bindKitExample();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", startUi);
  } else {
    startUi();
  }
})();
