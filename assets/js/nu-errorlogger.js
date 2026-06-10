/**
 * nu-errorlogger.js — Frontend JS error capture.
 * Include this script in index.php (after login confirmed).
 *
 * Catches:
 *   - window.onerror        (runtime JS errors)
 *   - unhandledrejection    (unhandled Promise rejections)
 *   - console.error calls   (optional, enabled by default)
 *
 * Posts to api/errorlog.php?action=log_js
 * Errors are rate-limited client-side (max 20 per session) to avoid log floods.
 */
(function () {
  'use strict';

  let _elSent = 0;
  const _elMax = 20;
  const _elEndpoint = 'api/errorlog.php?action=log_js';

  function _send(payload) {
    if (_elSent >= _elMax) return;
    _elSent++;
    payload.url = window.location.href;
    payload.userAgent = navigator.userAgent;
    fetch(_elEndpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).catch(() => {}); // silently ignore network errors
  }

  // ── window.onerror ────────────────────────────────────────────────────────
  const _prevOnError = window.onerror;
  window.onerror = function (message, source, lineno, colno, error) {
    _send({
      message: String(message),
      source:  source  || '',
      lineno:  lineno  || 0,
      colno:   colno   || 0,
      stack:   (error && error.stack) ? error.stack : '',
    });
    if (typeof _prevOnError === 'function') return _prevOnError.apply(this, arguments);
    return false;
  };

  // ── Unhandled promise rejection ──────────────────────────────────────────
  window.addEventListener('unhandledrejection', function (event) {
    const reason = event.reason;
    const message = reason instanceof Error
      ? reason.message
      : (typeof reason === 'string' ? reason : JSON.stringify(reason));
    _send({
      message: 'UnhandledRejection: ' + message,
      source:  '',
      lineno:  0,
      colno:   0,
      stack:   (reason instanceof Error && reason.stack) ? reason.stack : '',
    });
  });

  // ── console.error intercept ──────────────────────────────────────────────
  const _origConsoleError = console.error;
  console.error = function () {
    _origConsoleError.apply(console, arguments);
    const msg = Array.from(arguments).map(a => {
      if (a instanceof Error) return a.message + (a.stack ? '\n' + a.stack : '');
      if (typeof a === 'object') { try { return JSON.stringify(a); } catch(e) { return String(a); } }
      return String(a);
    }).join(' ');
    _send({ message: 'console.error: ' + msg, source: '', lineno: 0, colno: 0, stack: '' });
  };

})();
