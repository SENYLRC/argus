// Small, unobtrusive progressive enhancements — no framework, no build step.
// Every action here also has a working non-JS fallback (plain form submits);
// this only makes the list screen's selection bar and the print buttons nicer.
(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  // ---- Request list: selection bar + quick-select buttons ----
  function initSelectionBar() {
    var form = document.querySelector('[data-selection-form]');
    if (!form) return;
    var boxes = Array.prototype.slice.call(form.querySelectorAll('[data-row-check]'));
    var bar = form.querySelector('[data-selection-bar]');
    var count = form.querySelector('[data-selection-count]');
    var selectAll = form.querySelector('[data-select-all]');

    function refresh() {
      var checked = boxes.filter(function (b) { return b.checked; });
      if (count) count.textContent = String(checked.length);
      if (bar) bar.classList.toggle('has-selection', checked.length > 0);
      if (selectAll) selectAll.checked = checked.length > 0 && checked.length === boxes.length;
    }

    boxes.forEach(function (b) { b.addEventListener('change', refresh); });
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        boxes.forEach(function (b) { b.checked = selectAll.checked; });
        refresh();
      });
    }
    form.querySelectorAll('[data-select-status]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var status = btn.getAttribute('data-select-status');
        boxes.forEach(function (b) {
          b.checked = status === 'clear' ? false : b.getAttribute('data-row-status') === status;
        });
        refresh();
      });
    });
    refresh();
  }

  // ---- New/edit request: attachment filename + custom budget line reveal ----
  function initRequestForm() {
    var fileInput = document.querySelector('[data-attachment-input]');
    if (fileInput) {
      fileInput.addEventListener('change', function () {
        var confirmEl = document.querySelector('[data-attachment-confirm]');
        var nameEl = document.querySelector('[data-attachment-name]');
        if (fileInput.files && fileInput.files[0] && confirmEl && nameEl) {
          nameEl.textContent = fileInput.files[0].name;
          confirmEl.hidden = false;
        }
      });
    }

    document.querySelectorAll('[data-line-code]').forEach(function (select) {
      function toggle() {
        var row = select.closest('[data-line-row]');
        var custom = row && row.querySelector('[data-line-custom]');
        if (custom) custom.hidden = select.value !== '__custom__';
      }
      select.addEventListener('change', toggle);
      toggle();
    });
  }

  // ---- Print sheets: the on-screen "Print / save PDF" button uses the browser's own print ----
  function initPrintButtons() {
    document.querySelectorAll('[data-window-print]').forEach(function (btn) {
      btn.addEventListener('click', function () { window.print(); });
    });
  }

  // ---- Any <form data-confirm="…">: ask before submitting (delete, reset…) ----
  function initConfirmForms() {
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
      });
    });
  }

  onReady(function () {
    initConfirmForms();
    initSelectionBar();
    initRequestForm();
    initPrintButtons();
  });
})();
