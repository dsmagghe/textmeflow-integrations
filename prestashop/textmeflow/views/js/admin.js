/**
 * Copyright (c) Fades Management EOOD (TextMeFlow)
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    TextMeFlow <support@textmeflow.eu>
 * @copyright Fades Management EOOD
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License (AFL 3.0)
 */
(function () {
  'use strict';

  var app = document.getElementById('tmf-app');
  if (!app || app.getAttribute('data-connected') !== '1') {
    return;
  }
  var ajaxUrl = app.getAttribute('data-ajax-url');
  var S = JSON.parse(app.getAttribute('data-strings') || '{}');
  var el = function (id) { return document.getElementById(id); };
  var statusEl = el('tmf-status');
  var pairBox = el('tmf-pair');
  var qrImg = el('tmf-qr');
  var pairMsg = el('tmf-pair-msg');
  var pairBtn = el('tmf-pair-btn');
  var upgrade = el('tmf-upgrade');
  var pollTimer = null;
  var pairId = null;
  var restarts = 0;
  var MAX_RESTARTS = 3;

  function call(action, params) {
    var body = new URLSearchParams();
    body.append('ajax', '1');
    body.append('action', action);
    Object.keys(params || {}).forEach(function (k) { body.append(k, params[k]); });
    return fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: body.toString()
    }).then(function (r) { return r.json(); });
  }

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  function setLinked(number) {
    stopPolling();
    pairBox.hidden = true;
    pairBtn.hidden = true;
    statusEl.className = 'tmf-status is-ok';
    statusEl.textContent = S.connectedTo + (number ? ' ' + number : '') + ' ✓';
  }

  function showQr(qr) {
    if (qr) { qrImg.src = qr; qrImg.hidden = false; }
  }

  function startPair() {
    stopPolling();
    pairBox.hidden = false;
    upgrade.hidden = true;
    pairBtn.hidden = true;
    pairMsg.textContent = S.waiting;
    call('pair').then(function (res) {
      if (!res.ok) {
        qrImg.hidden = true;
        if (res.error === 'device_limit_reached') {
          pairMsg.textContent = S.limit;
          upgrade.hidden = false;
        } else {
          pairMsg.textContent = S.error;
          pairBtn.hidden = false;
        }
        return;
      }
      pairId = res.pair_id;
      showQr(res.qr);
      pairMsg.textContent = S.scanHelp;
      pollTimer = setInterval(poll, 3000);
    }).catch(function () { pairMsg.textContent = S.error; pairBtn.hidden = false; });
  }

  function poll() {
    if (!pairId) { return; }
    call('poll', { pair_id: pairId }).then(function (res) {
      if (!res.ok) { return; }
      if (res.status === 'connected') {
        refreshStatus();
        return;
      }
      if (res.status === 'expired') {
        stopPolling();
        if (restarts < MAX_RESTARTS) { restarts += 1; startPair(); }
        else { qrImg.hidden = true; pairMsg.textContent = S.expired; pairBtn.textContent = S.newCode; pairBtn.hidden = false; restarts = 0; }
        return;
      }
      showQr(res.qr); // the QR rotates: always show the latest
    }).catch(function () { /* keep polling */ });
  }

  function refreshStatus() {
    call('status').then(function (res) {
      if (!res.ok) { statusEl.textContent = S.error; return; }
      if (res.linked) { setLinked(res.number); return; }
      statusEl.className = 'tmf-status';
      statusEl.textContent = S.notLinked;
      pairBtn.hidden = false;
      startPair();
    }).catch(function () { statusEl.textContent = S.error; });
  }

  pairBtn.addEventListener('click', function () { restarts = 0; startPair(); });

  var testBtn = el('tmf-test-btn');
  var testMsg = el('tmf-test-msg');
  testBtn.addEventListener('click', function () {
    var phone = el('tmf_merchant_phone').value;
    testMsg.textContent = S.checking;
    call('test', { phone: phone }).then(function (res) {
      if (res.ok) { testMsg.textContent = S.testSent; }
      else if (res.error === 'invalid_number') { testMsg.textContent = S.testInvalid; }
      else if (res.error === 'quota_exceeded') { testMsg.textContent = S.quota; }
      else { testMsg.textContent = S.testFailed; }
    }).catch(function () { testMsg.textContent = S.error; });
  });

  refreshStatus();
}());
