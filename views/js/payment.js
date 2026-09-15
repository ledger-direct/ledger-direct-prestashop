/**
 * Payment page behaviour: count the quote down, and ask the server which
 * state the payment is in.
 *
 * Both are conveniences. The page is fully usable without JavaScript — the
 * amount, destination and tag are server-rendered, every state block exists
 * in the markup, and the cron endpoint settles the order regardless of whether
 * anyone is watching this page.
 *
 * The poll answers with the core's payment-status payload (state, amounts,
 * seconds left) plus a `redirect` once the order no longer waits. This script
 * knows no sentence a customer reads: it switches the server-rendered blocks
 * and fills in two numbers.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-ledgerdirect-payment]');
  if (!root) {
    return;
  }

  var POLL_INTERVAL_MS = 8000;

  var blocks = {
    waiting: root.querySelector('[data-ld-live]'),
    expired: root.querySelector('[data-ld-expired]'),
    partial: root.querySelector('[data-ld-partial]'),
    wrong_asset: root.querySelector('[data-ld-wrong-asset]')
  };

  var state = root.getAttribute('data-ld-state') || 'waiting';

  /* ---- amounts ---- */

  /**
   * The only formatting in this script, and the same rule the server uses:
   * a native amount (a number) has five places, a token amount (an object
   * with a value) has two. Nothing is computed here — the server already
   * decided what is paid and what is missing.
   */
  function formatAmount(amount) {
    if (amount === null || amount === undefined) {
      return '';
    }
    var isNative = typeof amount === 'number';
    var value = isNative ? amount : parseFloat(amount.value);
    return isNaN(value) ? '' : value.toFixed(isNative ? 5 : 2);
  }

  function fillAmounts(block, payload) {
    if (!block) {
      return;
    }
    var paid = block.querySelector('[data-ld-paid]');
    var shortfall = block.querySelector('[data-ld-shortfall]');
    if (paid) { paid.textContent = formatAmount(payload.amount_paid); }
    if (shortfall) { shortfall.textContent = formatAmount(payload.shortfall); }
  }

  /* ---- state blocks ---- */

  function showState(nextState) {
    state = nextState;
    root.setAttribute('data-ld-state', nextState);

    Object.keys(blocks).forEach(function (name) {
      if (blocks[name]) {
        blocks[name].hidden = name !== nextState;
      }
    });
  }

  /* ---- countdown ---- */

  var countdown = root.querySelector('[data-ld-countdown]');
  var secondsLeft = parseInt(root.getAttribute('data-ld-seconds-left'), 10);

  function renderCountdown() {
    if (!countdown) {
      return;
    }

    if (secondsLeft <= 0) {
      // Only swaps which block is visible, and only while nothing has arrived:
      // once a payment is in, the partial/wrong-asset block stays and the
      // refresh button must not be offered. The refreshed amount comes from the
      // server on submit — this never recomputes a price in the browser.
      if (state === 'waiting') {
        showState('expired');
      }
      return;
    }

    var minutes = Math.floor(secondsLeft / 60);
    var seconds = secondsLeft % 60;
    countdown.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
  }

  if (!isNaN(secondsLeft)) {
    renderCountdown();
    window.setInterval(function () {
      secondsLeft -= 1;
      renderCountdown();
    }, 1000);
  }

  /* ---- polling ---- */

  var pollUrl = root.getAttribute('data-ld-poll-url');
  if (!pollUrl) {
    return;
  }

  function applyStatus(payload) {
    // Whatever ended the wait — settled on-chain, cancelled or paid by hand in
    // the Back Office — the server sends where to go. That, and only that,
    // stops the polling: a partial payment keeps polling so the top-up is
    // noticed, and an expired quote keeps polling so a late payment is.
    if (payload.redirect) {
      window.location.href = payload.redirect;
      return false;
    }

    if (payload.state === 'partial' || payload.state === 'wrong_asset') {
      fillAmounts(blocks[payload.state], payload);
      showState(payload.state);
    } else if (payload.state === 'expired') {
      secondsLeft = 0;
      showState('expired');
    } else if (payload.state === 'waiting') {
      // Trust the server's clock over the browser's: it is the one that
      // decides whether the quote still stands.
      if (typeof payload.seconds_left === 'number') {
        secondsLeft = payload.seconds_left;
      }
      showState('waiting');
      renderCountdown();
    }

    return true;
  }

  function poll() {
    fetch(pollUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) {
        return response.ok ? response.json() : null;
      })
      .then(function (payload) {
        if (!payload || applyStatus(payload)) {
          window.setTimeout(poll, POLL_INTERVAL_MS);
        }
      })
      .catch(function () {
        // A failed poll is not worth surfacing — the next one may well work,
        // and the cron job is the actual guarantee.
        window.setTimeout(poll, POLL_INTERVAL_MS);
      });
  }

  window.setTimeout(poll, POLL_INTERVAL_MS);
})();
