/*
 * Session guard — 3-minute inactivity timeout.
 *  - Tracks real user interaction (mouse, keyboard, touch, scroll).
 *  - Tags every backend request with X-Client-Idle so background polling
 *    (cashier live refresh, HelloMoney status checks) cannot keep an idle
 *    session alive on the server.
 *  - Warns 30 seconds before sign-out, then signs out automatically.
 */
(function () {
  'use strict';
  var TIMEOUT_MS = 180 * 1000;
  var WARN_MS = 30 * 1000;
  var lastActivity = Date.now();
  var expiring = false;
  var banner = null;

  function loggedIn() {
    try { return sessionStorage.getItem('loggedIn') === 'true'; } catch (e) { return false; }
  }
  function markActive() {
    lastActivity = Date.now();
    if (banner && banner.classList.contains('show')) banner.classList.remove('show');
  }
  ['mousedown', 'mousemove', 'keydown', 'touchstart', 'wheel', 'scroll', 'input'].forEach(function (evt) {
    window.addEventListener(evt, markActive, { passive: true, capture: true });
  });

  function isBackendUrl(url) { return typeof url === 'string' && url.indexOf('backend/') !== -1; }
  function isAuthUrl(url) { return /backend\/(login|verify_otp|resend_otp|logout|me)\.php/.test(url); }

  var nativeFetch = window.fetch ? window.fetch.bind(window) : null;
  if (nativeFetch) {
    window.fetch = function (input, init) {
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      if (isBackendUrl(url)) {
        try {
          init = Object.assign({}, init || {});
          var headers = new Headers(init.headers || (typeof input !== 'string' && input.headers) || undefined);
          headers.set('X-Client-Idle', String(Math.floor((Date.now() - lastActivity) / 1000)));
          init.headers = headers;
        } catch (e) { /* never block a request because of the guard */ }
      }
      return nativeFetch(input, init).then(function (response) {
        if (response.status === 401 && isBackendUrl(url) && !isAuthUrl(url) && loggedIn()) {
          response.clone().json().then(function (d) {
            if (d && d.code === 'session_expired') expire('expired');
          }).catch(function () {});
        }
        return response;
      });
    };
  }

  function clearClientSession() {
    try {
      ['loggedIn', 'username', 'fullName', 'studentId', 'employeeId', 'role', 'studentLevel'].forEach(function (k) { sessionStorage.removeItem(k); });
    } catch (e) {}
  }

  function expire(reason) {
    if (expiring) return;
    expiring = true;
    var done = function () {
      clearClientSession();
      var url = window.location.pathname + '?session=' + (reason === 'idle' ? 'idle' : 'expired');
      window.location.replace(url);
    };
    if (reason === 'idle' && nativeFetch) {
      nativeFetch('backend/logout.php?reason=idle', { credentials: 'include' }).then(done, done);
    } else {
      done();
    }
  }

  function ensureBanner() {
    if (banner || !document.body) return banner;
    banner = document.createElement('div');
    banner.className = 'session-timeout-banner';
    banner.setAttribute('role', 'alert');
    banner.innerHTML = '<span><b>Still there?</b> For your security you will be signed out in <b id="sessionTimeoutCount">30</b>s because of inactivity.</span><button type="button">Stay signed in</button>';
    banner.querySelector('button').addEventListener('click', function () {
      markActive();
      if (nativeFetch) nativeFetch('backend/me.php', { credentials: 'include', headers: { 'X-Client-Idle': '0' } }).catch(function () {});
    });
    document.body.appendChild(banner);
    return banner;
  }

  setInterval(function () {
    if (!loggedIn() || expiring) return;
    var idle = Date.now() - lastActivity;
    if (idle >= TIMEOUT_MS) { expire('idle'); return; }
    if (idle >= TIMEOUT_MS - WARN_MS) {
      var b = ensureBanner();
      if (b) {
        b.classList.add('show');
        var c = b.querySelector('#sessionTimeoutCount');
        if (c) c.textContent = String(Math.max(0, Math.ceil((TIMEOUT_MS - idle) / 1000)));
      }
    }
  }, 1000);

  window.SessionGuard = {
    timeoutSeconds: TIMEOUT_MS / 1000,
    markActive: markActive,
    expire: expire,
    idleSeconds: function () { return Math.floor((Date.now() - lastActivity) / 1000); }
  };
})();
