/**
 * Payment page behaviour: count the quote down, and ask the server whether the
 * transaction has shown up on the ledger yet.
 *
 * Both are conveniences. The page is fully usable without JavaScript — the
 * amount, destination and tag are server-rendered, and the cron endpoint
 * settles the order regardless of whether anyone is watching this page.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-ledgerdirect-payment]');
  if (!root) {
    return;
  }

  var POLL_INTERVAL_MS = 8000;

  /* ---- countdown ---- */

  var countdown = root.querySelector('[data-ld-countdown]');
  var expiredBox = root.querySelector('[data-ld-expired]');
  var liveBox = root.querySelector('[data-ld-live]');

  function renderCountdown(secondsLeft) {
    if (!countdown) {
      return;
    }

    if (secondsLeft <= 0) {
      // Only swaps which block is visible. The refreshed amount comes from the
      // server on reload — this never recomputes a price in the browser.
      if (liveBox) { liveBox.hidden = true; }
      if (expiredBox) { expiredBox.hidden = false; }
      return;
    }

    var minutes = Math.floor(secondsLeft / 60);
    var seconds = secondsLeft % 60;
    countdown.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
  }

  var secondsLeft = parseInt(root.getAttribute('data-ld-seconds-left'), 10);

  if (!isNaN(secondsLeft)) {
    renderCountdown(secondsLeft);
    window.setInterval(function () {
      secondsLeft -= 1;
      renderCountdown(secondsLeft);
    }, 1000);
  }

  /* ---- polling ---- */

  var pollUrl = root.getAttribute('data-ld-poll-url');
  if (!pollUrl) {
    return;
  }

  function poll() {
    fetch(pollUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) {
        return response.ok ? response.json() : null;
      })
      .then(function (payload) {
        if (!payload) {
          return;
        }

        if (payload.status === 'done' && payload.redirect) {
          window.location.href = payload.redirect;
          return;
        }

        // Trust the server's clock over the browser's: it is the one that
        // decides whether the quote still stands.
        if (typeof payload.seconds_left === 'number') {
          secondsLeft = payload.seconds_left;
          renderCountdown(secondsLeft);
        }

        window.setTimeout(poll, POLL_INTERVAL_MS);
      })
      .catch(function () {
        // A failed poll is not worth surfacing — the next one may well work,
        // and the cron job is the actual guarantee.
        window.setTimeout(poll, POLL_INTERVAL_MS);
      });
  }

  window.setTimeout(poll, POLL_INTERVAL_MS);
})();
