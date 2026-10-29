(function () {
  "use strict";

  var sidebar = document.getElementById("appSidebar") || document.querySelector(".db-sidebar");
  var backdrop = document.getElementById("sidebarBackdrop");
  var toggle = document.getElementById("sidebarToggle");
  var mq = window.matchMedia("(max-width: 960px)");

  function setOpen(open) {
    if (!sidebar) return;
    sidebar.classList.toggle("is-open", open);
    if (backdrop) {
      backdrop.classList.toggle("is-open", open);
      backdrop.hidden = !open;
    }
    if (toggle) toggle.setAttribute("aria-expanded", open ? "true" : "false");
    document.body.style.overflow = open && mq.matches ? "hidden" : "";
  }

  if (toggle && sidebar) {
    toggle.addEventListener("click", function () {
      setOpen(!sidebar.classList.contains("is-open"));
    });
  }
  if (backdrop) {
    backdrop.addEventListener("click", function () {
      setOpen(false);
    });
  }
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      setOpen(false);
      document.querySelectorAll(".modal-overlay.active").forEach(function (el) {
        el.classList.remove("active");
      });
    }
  });
  if (mq.addEventListener) {
    mq.addEventListener("change", function (e) {
      if (!e.matches) setOpen(false);
    });
  }

  document.querySelectorAll("form").forEach(function (form) {
    if (form.method && form.method.toLowerCase() !== "post") return;
    form.addEventListener("submit", function () {
      var btn = form.querySelector('button[type="submit"], input[type="submit"]');
      if (btn) {
        btn.disabled = true;
        if (btn.tagName === "BUTTON") {
          btn.dataset.originalText = btn.innerHTML;
          btn.innerHTML = "Menyimpan...";
        }
      }
    });
  });

  function enhanceTable(table) {
    if (table.dataset.enhanced === "1") return;
    var rows = Array.prototype.slice.call(table.tBodies[0] ? table.tBodies[0].rows : []);
    if (rows.length < 8) return;
    table.dataset.enhanced = "1";

    var wrap = table.closest(".table-wrap, .db-table-box") || table.parentElement;
    var toolbar = document.createElement("div");
    toolbar.className = "table-toolbar";
    toolbar.innerHTML =
      '<div class="db-search-inner"><i class="fa-solid fa-magnifying-glass"></i>' +
      '<input type="search" class="table-search" placeholder="Cari dalam tabel..." aria-label="Cari dalam tabel"></div>' +
      '<span class="table-count"></span>';

    var pager = document.createElement("div");
    pager.className = "table-pager";
    pager.innerHTML =
      '<span class="pager-info"></span><div class="act-wrap">' +
      '<button type="button" class="pager-prev">Sebelumnya</button>' +
      '<button type="button" class="pager-next">Berikutnya</button></div>';

    wrap.parentNode.insertBefore(toolbar, wrap);
    wrap.parentNode.insertBefore(pager, wrap.nextSibling);

    var search = toolbar.querySelector(".table-search");
    var countEl = toolbar.querySelector(".table-count");
    var info = pager.querySelector(".pager-info");
    var prev = pager.querySelector(".pager-prev");
    var next = pager.querySelector(".pager-next");
    var pageSize = 10;
    var page = 1;
    var filtered = rows.slice();

    function apply() {
      var q = (search.value || "").toLowerCase().trim();
      filtered = rows.filter(function (row) {
        return !q || row.textContent.toLowerCase().indexOf(q) !== -1;
      });
      var pages = Math.max(1, Math.ceil(filtered.length / pageSize));
      if (page > pages) page = pages;
      rows.forEach(function (row) {
        row.style.display = "none";
      });
      var start = (page - 1) * pageSize;
      filtered.slice(start, start + pageSize).forEach(function (row) {
        row.style.display = "";
      });
      countEl.textContent = filtered.length + " dari " + rows.length + " baris";
      info.textContent = "Halaman " + page + " / " + pages;
      prev.disabled = page <= 1;
      next.disabled = page >= pages;
    }

    search.addEventListener("input", function () {
      page = 1;
      apply();
    });
    prev.addEventListener("click", function () {
      page -= 1;
      apply();
    });
    next.addEventListener("click", function () {
      page += 1;
      apply();
    });
    apply();
  }

  document.querySelectorAll("table.db-table").forEach(enhanceTable);

  document.querySelectorAll("label").forEach(function (label) {
    var target = null;
    if (label.htmlFor) target = document.getElementById(label.htmlFor);
    if (!target) {
      var wrap = label.parentElement;
      if (wrap) target = wrap.querySelector("input, select, textarea");
    }
    if (target && target.required && label.textContent.indexOf("*") === -1) {
      var star = document.createElement("span");
      star.className = "req";
      star.textContent = " *";
      label.appendChild(star);
    }
  });

  // ===== MODAL HANDLERS =====
  // Buka modal: <button data-modal-open="modalId">
  // Tutup modal: <button data-modal-close> atau <button data-modal-close="modalId">
  document.addEventListener("click", function (e) {
    var opener = e.target.closest("[data-modal-open]");
    if (opener) {
      var id = opener.getAttribute("data-modal-open");
      var modal = document.getElementById(id);
      if (modal) {
        modal.classList.add("active", "show");
        modal.removeAttribute("hidden");
        var firstEl = modal.querySelector("input:not([type=hidden]), select, textarea, button:not([data-modal-close])");
        if (firstEl) firstEl.focus();
      }
    }
    var closer = e.target.closest("[data-modal-close]");
    if (closer) {
      var closeId = closer.getAttribute("data-modal-close") || "";
      var target2 = closeId ? document.getElementById(closeId) : closer.closest(".modal-overlay, .delete-modal");
      if (target2) { target2.classList.remove("active", "show"); target2.hidden = true; }
    }
    // Klik area gelap luar modal untuk tutup
    if (e.target.classList.contains("modal-overlay") || e.target.classList.contains("delete-modal")) {
      e.target.classList.remove("active", "show");
      e.target.hidden = true;
    }
  });

  // ===== AUTO-DISMISS FLASH =====
  document.querySelectorAll(".db-notif").forEach(function (el) {
    setTimeout(function () {
      el.style.transition = "opacity 0.5s";
      el.style.opacity = "0";
      setTimeout(function () { el.remove(); }, 550);
    }, 6000);
  });

  // ===== DATA-CONFIRM =====
  // <button data-confirm="Pesan konfirmasi"> — tanya sebelum aksi
  document.querySelectorAll("[data-confirm]").forEach(function (el) {
    el.addEventListener("click", function (evt) {
      var msg = el.getAttribute("data-confirm") || "Apakah Anda yakin?";
      if (!window.confirm(msg)) { evt.preventDefault(); evt.stopPropagation(); }
    });
  });
})();
