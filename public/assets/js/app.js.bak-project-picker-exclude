/* ============================================================
   NPO CRM — Single Source of Truth JS
   Handles: modal open/close, AJAX form submit, JSON errors,
            inline-script re-execution for injected modals.
   ============================================================ */

(function () {
  'use strict';

  const modalRoot  = () => document.getElementById('modal-root');
  const modalTitle = () => document.getElementById('modal-title');
  const modalBody  = () => document.getElementById('modal-body');
  let lastModalTrigger = null;

  /**
   * Browsers do NOT execute <script> tags injected via innerHTML.
   * This helper finds them and re-creates each one so it runs.
   */
  function runScriptsIn(container) {
    container.querySelectorAll('script').forEach(function (oldScript) {
      const newScript = document.createElement('script');
      if (oldScript.src) {
        newScript.src = oldScript.src;
      } else {
        newScript.textContent = oldScript.textContent;
      }
      oldScript.parentNode.replaceChild(newScript, oldScript);
    });
  }

  async function openModal(name, data) {
    const root = modalRoot();
    if (!root) return;

    modalTitle().textContent = 'Loading…';
    modalBody().innerHTML    = '<p class="muted">Please wait…</p>';
    root.hidden = false;
    root.classList.toggle('modal-root-task-open', name === 'complete-task');
    document.body.classList.toggle('task-execution-active', name === 'complete-task');
    document.body.style.overflow = 'hidden';

    try {
      const normalized = { ...(data || {}) };
      // Canonical task identifiers. data-followup-id becomes dataset.followupId.
      const candidateFollowup = normalized.followup_id
        || normalized.followupId
        || normalized.followup
        || normalized['followup-id']
        || '';
      if (candidateFollowup) normalized.followup_id = candidateFollowup;
      const candidateLead = normalized.lead_id
        || normalized.leadId
        || normalized.lead
        || normalized['lead-id']
        || '';
      if (candidateLead) normalized.lead_id = candidateLead;

      // HTML data-return-to becomes dataset.returnTo. Normalize it for modal
      // GET requests so the server-rendered completion form receives the same
      // return_to field that the completion endpoint already understands.
      // Keep this scoped to Complete Task so the rolled-back WhatsApp Share
      // flow is not changed by this release.
      if (name === 'complete-task') {
        const candidateReturnTo = normalized.return_to
          || normalized.returnTo
          || normalized['return-to']
          || '';
        if (candidateReturnTo) normalized.return_to = candidateReturnTo;
        delete normalized.returnTo;
        delete normalized['return-to'];
      }

      delete normalized.followup;
      delete normalized.followupId;
      delete normalized['followup-id'];
      delete normalized.leadId;
      delete normalized['lead-id'];
      // HTML data-return-to becomes dataset.returnTo. Normalize it for the
      // server's canonical return_to field for every work modal, not only Done.
      // This keeps Complete Task and Share Lead on exactly the same return path.
      const candidateReturnTo = normalized.return_to
        || normalized.returnTo
        || normalized['return-to']
        || '';
      if (candidateReturnTo) normalized.return_to = candidateReturnTo;
      delete normalized.returnTo;
      delete normalized['return-to'];
      const params = new URLSearchParams(normalized);
      const res    = await fetch(`/modals/${name}?${params.toString()}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      if (!res.ok) {
        const contentType = res.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
          let data = {};
          try { data = await res.json(); } catch (e) {}
          const message = data.message || data.error || ('Unable to load this action (HTTP ' + res.status + ').');
          modalBody().innerHTML = '<div class="alert alert-error" style="white-space:pre-wrap;line-height:1.5">' + escapeHtml(message) + '</div>';
          return;
        }
        const text = await res.text();
        if (text && text.length < 20000) {
          modalBody().innerHTML = text;
          return;
        }
        throw new Error('Failed to load modal');
      }

            let title = res.headers.get('X-Modal-Title') || '';
      // Header value was rawurlencoded on the server because HTTP headers
      // don't carry UTF-8 safely. Decode it back to a real string.
      try { title = decodeURIComponent(title); } catch (e) { /* leave as-is */ }
      modalTitle().textContent = title;
      modalBody().innerHTML    = await res.text();

      // 🔥 THE FIX — execute any inline scripts the modal contains
      runScriptsIn(modalBody());

      // Completing a task must never pop the mobile keyboard just because the
      // modal opened. Keep focus inside the dialog, then let the user choose
      // the outcome/channel before any text field is focused.
      if (name === 'complete-task' || name === 'task-share-lead') {
        // Critical mobile transaction modals must never autofocus a field.
        // Autofocus can summon the keyboard before the user has chosen what
        // they want to do, consuming most of a small screen.
        const close = root.querySelector('.modal-close');
        if (close) setTimeout(() => close.focus({ preventScroll: true }), 60);
      } else {
        const firstInput = modalBody().querySelector('input, select, textarea');
        if (firstInput) setTimeout(() => firstInput.focus(), 60);
      }
    } catch (err) {
      const message = (err && err.message) ? err.message : 'Please try again.';
      modalBody().innerHTML = '<div class="alert alert-error" style="white-space:pre-wrap;line-height:1.5"><strong>Could not load this action.</strong><br>' + escapeHtml(message) + '</div>';
    }
  }

  // Public hook for small admin-only tools that need the shared modal loader.
  window.NPO_CRM_OPEN_MODAL = function(name, data){ return openModal(name, data || {}); };

  /* ============================================================
     FUTURE TASK ACTION GATES — SINGLE IMPLEMENTATION
     Every future-follow-up surface uses the same rules:
       1. Actions remain locked until due.
       2. Start Early explicitly unlocks them.
       3. Every modal opened from the unlocked action area receives
          allow_early=1 so the server uses the normal action path.
       4. When the scheduled time arrives, the same unlock happens
          automatically without early authorization being needed.
     ============================================================ */
  function markEarlyAuthorized(container) {
    if (!container) return;
    container.querySelectorAll('[data-modal]').forEach(function (btn) {
      btn.dataset.allowEarly = '1';
    });
  }

  function initFutureActionGates() {
    const configs = [
      {
        selector: '[data-task-gate]',
        bound: 'gateBound',
        due: 'data-task-due',
        live: '[data-gated-actions]',
        early: '[data-task-start-early]',
        countdown: '[data-task-countdown]',
        lock: '.tc-early-lock',
        gatedClass: 'tc-gated-actions-locked',
        rootLockedClass: 'tc-actions-gated'
      },
      {
        selector: '[data-work-gate]',
        bound: 'workGateBound',
        due: 'data-work-due',
        live: '[data-work-live-actions]',
        early: '[data-work-start-early]',
        countdown: '[data-work-countdown]',
        lock: '.work-action-lock',
        gatedClass: null,
        rootLockedClass: null
      }
    ];

    configs.forEach(function (cfg) {
      document.querySelectorAll(cfg.selector).forEach(function (gate) {
        if (gate.dataset[cfg.bound] === '1') return;
        gate.dataset[cfg.bound] = '1';

        const dueRaw = gate.getAttribute(cfg.due);
        const live = gate.querySelector(cfg.live);
        const early = gate.querySelector(cfg.early);
        const countdown = gate.querySelector(cfg.countdown);
        const lock = gate.querySelector(cfg.lock);
        if (!dueRaw || !live || !early) return;

        let timer = null;

        function unlock(reason) {
          if (cfg.rootLockedClass) gate.classList.remove(cfg.rootLockedClass);
          live.hidden = false;
          if (cfg.gatedClass) live.classList.remove(cfg.gatedClass);
          live.style.pointerEvents = '';
          live.style.opacity = '';

          // Start Early is the explicit authorization for future work.
          // Due-time unlock also uses the same action area, but does not
          // need special authorization because the task is no longer future.
          if (reason === 'early') markEarlyAuthorized(live);

          if (lock) lock.remove();
          early.remove();
          if (reason === 'due' && countdown) countdown.textContent = 'Due now';
          if (timer) window.clearInterval(timer);
        }

        if (cfg.gatedClass) live.classList.add(cfg.gatedClass);
        live.style.pointerEvents = 'none';

        function tick() {
          const ms = new Date(dueRaw).getTime() - Date.now();
          if (ms <= 0) {
            unlock('due');
            return;
          }
          const total = Math.floor(ms / 1000);
          const h = Math.floor(total / 3600);
          const m = Math.floor((total % 3600) / 60);
          const sec = total % 60;
          if (countdown) {
            countdown.textContent = h > 0
              ? h + 'h ' + String(m).padStart(2,'0') + 'm remaining'
              : String(m).padStart(2,'0') + 'm ' + String(sec).padStart(2,'0') + 's remaining';
          }
        }

        early.addEventListener('click', function () { unlock('early'); });
        tick();
        timer = window.setInterval(function () {
          if (!document.body.contains(gate)) {
            window.clearInterval(timer);
            return;
          }
          tick();
        }, 1000);
      });
    });
  }

  function closeModal() {
    const root = modalRoot();
    if (!root) return;
    root.hidden = true;
    root.classList.remove('modal-root-task-open');
    document.body.classList.remove('task-execution-active');
    document.body.style.overflow = '';
    modalBody().innerHTML = '';
    const trigger = lastModalTrigger;
    lastModalTrigger = null;
    if (trigger && document.contains(trigger)) {
      setTimeout(() => { try { trigger.focus({ preventScroll: true }); } catch (e) {} }, 0);
    }
  }

  document.addEventListener('click', function (e) {
    const trigger = e.target.closest('[data-modal]');
    if (trigger) {
      e.preventDefault();
      const data = { ...trigger.dataset };
      if (Object.prototype.hasOwnProperty.call(data, 'allowEarly')) {
        data.allow_early = data.allowEarly;
        delete data.allowEarly;
      }
      delete data.modal;
      lastModalTrigger = trigger;
      openModal(trigger.dataset.modal, data);
      return;
    }
    if (e.target.closest('.modal-close')) {
      e.preventDefault();
      closeModal();
      return;
    }
    if (e.target.classList && e.target.classList.contains('modal-backdrop')) {
      closeModal();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();
  });

  /* ---------- AJAX FORM SUBMIT (inside modals) ---------- */

    document.addEventListener('submit', async function (e) {
    const form = e.target.closest('form[data-ajax]');
    if (!form) return;

    e.preventDefault();

    const submitBtn = form.querySelector('button[type="submit"]');
    const original  = submitBtn ? submitBtn.textContent : '';

    // Clear previous errors
    form.querySelectorAll('.field-error').forEach(el => el.remove());
    form.querySelectorAll('.field-error-banner').forEach(el => el.remove());
    form.querySelectorAll('.alert-error').forEach(el => el.remove());
    form.querySelectorAll('.has-error').forEach(el => el.classList.remove('has-error'));

    // ---- Client-side required check ----
    const missing = [];
    form.querySelectorAll('[required]').forEach(el => {
      const isEmpty =
        el.type === 'checkbox' ? !el.checked :
        el.type === 'radio'    ? !form.querySelector('input[name="' + el.name + '"]:checked') :
        el.value.trim() === '';

      if (isEmpty) {
        missing.push(el);
        el.classList.add('has-error');
      }
    });

    if (missing.length) {
      const banner = document.createElement('div');
      banner.className = 'field-error-banner';
      banner.textContent = missing.length === 1
        ? 'This field is required. Please fill it in.'
        : 'Please fill in all required fields marked with *';
      form.insertBefore(banner, form.firstChild);

      const first = missing[0];
      first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(() => first.focus(), 200);
      return;
    }

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Saving…';
    }

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Saving…';
    }

    try {
      const res = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        }
      });

      if (res.ok) {
        const contentType = res.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
          const data = await res.json();
          if (data && data.redirect_url) {
            closeModal();
            window.location.replace(data.redirect_url);
            return;
          }
        }

        closeModal();
        window.location.reload();
        return;
      }

      if (res.status === 422) {
        const data = await res.json();
        renderErrors(form, data.errors || {});
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = original;
        }
        return;
      }

      let message = 'Something went wrong (HTTP ' + res.status + '). Please try again.';
      try {
        const text = await res.text();
        try {
          const data = JSON.parse(text);
          if (data && data.message) {
            message = data.message + ' (HTTP ' + res.status + ')';
          } else if (text.length && text.length < 300) {
            message = 'HTTP ' + res.status + ': ' + text;
          }
        } catch (parseErr) {
          if (text && text.length < 300) {
            message = 'HTTP ' + res.status + ': ' + text;
          }
        }
      } catch (readErr) { /* ignore */ }

      showTopAlert(form, message);
    } catch (err) {
      showTopAlert(form, 'Network error: ' + err.message);
    } finally {
      if (submitBtn && submitBtn.disabled) {
        submitBtn.disabled = false;
        submitBtn.textContent = original;
      }
    }
  });

    function renderErrors(form, errors) {
    const keys = Object.keys(errors);
    if (keys.length === 0) return;

    // Clear existing errors first
    form.querySelectorAll('.field-error').forEach(el => el.remove());
    form.querySelectorAll('.field-error-banner').forEach(el => el.remove());
    form.querySelectorAll('.has-error').forEach(el => el.classList.remove('has-error'));

    // Banner at top
    const summary = document.createElement('div');
    summary.className = 'field-error-banner';
    summary.textContent = keys.length === 1
      ? errors[keys[0]][0]
      : 'Please fix the highlighted fields below';
    form.insertBefore(summary, form.firstChild);

    let firstInput = null;

    keys.forEach(key => {
      // Handle array names like additional_agent_ids.0
      const name = key.replace(/\.\d+$/, '');
      const input = form.querySelector('[name="' + key + '"]') ||
                    form.querySelector('[name="' + name + '[]"]') ||
                    form.querySelector('[name="' + name + '"]');

      if (input) {
        input.classList.add('has-error');
        if (! firstInput) firstInput = input;

        const err = document.createElement('div');
        err.className = 'field-error';
        err.textContent = errors[key][0];

        // Place error right after the input's wrapper, not the input itself
        const field = input.closest('.field') || input.parentNode;
        if (field && field !== form) {
          field.appendChild(err);
        } else if (input.parentNode) {
          input.parentNode.insertBefore(err, input.nextSibling);
        }
      }
    });

    // Scroll to first error
    if (firstInput) {
      firstInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(() => {
        try { firstInput.focus(); } catch (e) {}
      }, 200);
    }

    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function showTopAlert(form, message) {
    const el = document.createElement('div');
    el.className = 'alert alert-error';
    el.textContent = message;
    el.style.marginBottom = '12px';
    el.style.whiteSpace = 'pre-wrap';
    el.style.fontSize = '13px';
    form.insertBefore(el, form.firstChild);
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert[data-auto-dismiss]').forEach(function (el) {
      setTimeout(function () {
        el.style.transition = 'opacity .4s ease';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 400);
      }, 4000);
    });
  });

  // Mobile keyboards shrink the visual viewport. Keep focused controls and the
  // task modal's action area reachable without requiring the user to dismiss the keyboard.
  (function initModalViewportSupport() {
    if (!window.visualViewport) return;
    function sync() {
      const root = modalRoot();
      if (!root || root.hidden) return;
      const keyboardGap = Math.max(0, window.innerHeight - window.visualViewport.height - window.visualViewport.offsetTop);
      root.style.setProperty('--modal-keyboard-gap', keyboardGap + 'px');
      const active = document.activeElement;
      if (active && root.contains(active) && /^(INPUT|SELECT|TEXTAREA)$/.test(active.tagName)) {
        window.setTimeout(() => {
          try { active.scrollIntoView({ block: 'center', inline: 'nearest' }); } catch (e) {}
        }, 30);
      }
    }
    window.visualViewport.addEventListener('resize', sync);
    window.visualViewport.addEventListener('scroll', sync);
  })();

  window.NPOCRM = { openModal, closeModal };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initFutureActionGates();
    });
  } else {
    initFutureActionGates();
  }
  
  /* ============================================================
     NOTIFICATIONS — Desktop + Mobile bells + PWA push
     ============================================================ */
  (function () {
    var pairs = [
      { bell: 'notif-bell',   badge: 'notif-badge',   panel: 'notif-panel',   list: 'notif-list',   markAll: 'notif-mark-all'   },
      { bell: 'notif-bell-m', badge: 'notif-badge-m', panel: 'notif-panel-m', list: 'notif-list-m', markAll: 'notif-mark-all-m' },
    ].map(function (ids) {
      return {
        bell:    document.getElementById(ids.bell),
        badge:   document.getElementById(ids.badge),
        panel:   document.getElementById(ids.panel),
        list:    document.getElementById(ids.list),
        markAll: document.getElementById(ids.markAll),
      };
    }).filter(function (p) { return p.bell && p.panel; });

    if (! pairs.length) return;

    function csrf() {
      var m = document.querySelector('meta[name="csrf-token"]');
      return m ? m.getAttribute('content') : '';
    }

    function setAllBadges(count) {
      count = Math.max(0, parseInt(count, 10) || 0);
      pairs.forEach(function (p) {
        if (! p.badge) return;
        if (count > 0) {
          p.badge.textContent = count > 99 ? '99+' : count;
          p.badge.hidden = false;
        } else {
          p.badge.hidden = true;
          p.badge.textContent = '0';
        }
      });
    }

    function setBellExpanded(pair, expanded) {
      if (pair.bell) pair.bell.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    function closeAllPanels() {
      pairs.forEach(function (p) {
        if (p.panel) p.panel.hidden = true;
        setBellExpanded(p, false);
      });
    }

    function maybeVibrate() {
      try {
        if (navigator.vibrate) navigator.vibrate([120, 60, 120]);
      } catch (e) {}
    }

    var _flashTimer = null;
    var _flashOn = false;
    var _origTitle = null;

    function maybeFlashTitle(count) {
      if (document.visibilityState === 'visible' || count <= 0) return;
      if (_origTitle === null) _origTitle = document.title;
      if (_flashTimer) return;
      _flashTimer = setInterval(function () {
        _flashOn = !_flashOn;
        document.title = _flashOn ? '🔔 (' + count + ') ' + _origTitle : _origTitle;
      }, 1500);
    }

    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible' && _flashTimer) {
        clearInterval(_flashTimer);
        _flashTimer = null;
        if (_origTitle !== null) document.title = _origTitle;
      }
    });

    var _lastNotifCount = null;

    async function refreshCount() {
      try {
        var res = await fetch('/notifications/unread-count?_=' + Date.now(), {
          method: 'GET',
          cache: 'no-store',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Cache-Control': 'no-cache',
            'Pragma': 'no-cache'
          }
        });
        if (! res.ok) return null;

        var data = await res.json();
        var count = Math.max(0, parseInt(data.count, 10) || 0);

        if (_lastNotifCount !== null && count > _lastNotifCount) {
          try { window.NPOCRM && window.NPOCRM.chime && window.NPOCRM.chime(); } catch (e) {}
          maybeVibrate();
          maybeFlashTitle(count);
        }

        _lastNotifCount = count;
        setAllBadges(count);
        return count;
      } catch (e) {
        return null;
      }
    }

    function showPushSetup(listEl) {
      if (! listEl || ! ('serviceWorker' in navigator) || ! ('PushManager' in window) || ! ('Notification' in window)) return;

      var setup = listEl.querySelector('[data-push-setup]');
      if (! setup) return;

      navigator.serviceWorker.getRegistration('/').then(function (registration) {
        if (! registration) return;
        return registration.pushManager.getSubscription();
      }).then(function (subscription) {
        if (! subscription && Notification.permission !== 'granted') {
          setup.hidden = false;
        } else {
          setup.hidden = true;
        }
      }).catch(function () {});
    }

    async function loadPanelInto(listEl) {
      if (! listEl) return;
      listEl.innerHTML = '<p class="muted" style="padding:16px;text-align:center;">Loading…</p>';
      try {
        var res = await fetch('/notifications/panel?_=' + Date.now(), {
          cache: 'no-store',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Cache-Control': 'no-cache',
            'Pragma': 'no-cache'
          }
        });
        if (res.ok) {
          listEl.innerHTML = await res.text();
          showPushSetup(listEl);
        } else {
          listEl.innerHTML = '<p class="muted" style="padding:16px;text-align:center;">Could not load.</p>';
        }
      } catch (e) {
        listEl.innerHTML = '<p class="muted" style="padding:16px;text-align:center;">Could not load.</p>';
      }
    }

    pairs.forEach(function (p) {
      p.bell.addEventListener('click', async function (e) {
        e.stopPropagation();
        var willOpen = p.panel.hidden;
        closeAllPanels();
        if (willOpen) {
          p.panel.hidden = false;
          setBellExpanded(p, true);
          await loadPanelInto(p.list);
          await refreshCount();
        }
      });

      if (p.markAll) {
        p.markAll.addEventListener('click', async function (e) {
          e.stopPropagation();
          p.markAll.disabled = true;

          // Immediate UI state; server response below is authoritative.
          setAllBadges(0);

          try {
            var res = await fetch('/notifications/read-all', {
              method: 'POST',
              cache: 'no-store',
              headers: {
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Cache-Control': 'no-cache'
              }
            });

            if (res.ok) {
              var data = await res.json();
              setAllBadges(data.unread || 0);
              await loadPanelInto(p.list);
              // Keep the panel open and synchronise from the server once more.
              await refreshCount();
            } else {
              await refreshCount();
            }
          } catch (err) {
            await refreshCount();
          } finally {
            p.markAll.disabled = false;
          }
        });
      }
    });

    // Reconcile an already-authorized browser subscription automatically.
    // This is silent: it never requests permission. It keeps the server
    // synchronized if the browser rotates/replaces the push subscription.
    async function reconcileExistingPushSubscription() {
      try {
        if (!('serviceWorker' in navigator) ||
            !('PushManager' in window) ||
            !('Notification' in window) ||
            Notification.permission !== 'granted') {
          return;
        }

        var registration = await navigator.serviceWorker.ready;
        var subscription = await registration.pushManager.getSubscription();

        if (!subscription) return;

        var response = await fetch('/notifications/push-subscribe', {
          method: 'POST',
          cache: 'no-store',
          headers: {
            'X-CSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'Cache-Control': 'no-cache',
            'Pragma': 'no-cache'
          },
          body: JSON.stringify(subscription.toJSON())
        });

        if (!response.ok) {
          console.warn(
            'NPO CRM: push subscription reconciliation failed',
            response.status
          );
        }
      } catch (e) {
        // Push reconciliation must never interfere with the CRM UI.
      }
    }

    // Synchronize an already-authorized device without prompting.
    reconcileExistingPushSubscription();

        // ============================================================
    // PWA PUSH — device status + subscription reconciliation
    // ============================================================

    function pushElements(root) {
      var setup = root ? root.querySelector('[data-push-setup]') : null;

      return {
        setup: setup,
        status: setup ? setup.querySelector('[data-push-status]') : null,
        enable: setup ? setup.querySelector('[data-enable-push]') : null,
        disable: setup ? setup.querySelector('[data-disable-push]') : null,
        test: setup ? setup.querySelector('[data-test-push]') : null
      };
    }

    function setPushStatus(listEl, state, message) {
      var el = pushElements(listEl);

      if (el.status) {
        el.status.textContent = message;
      }

      if (!el.setup) return;

      if (state === 'enabled') {
        el.setup.hidden = false;
        if (el.enable) el.enable.hidden = true;
        if (el.disable) el.disable.hidden = false;
        if (el.test) el.test.hidden = false;
      } else if (state === 'available') {
        el.setup.hidden = false;
        if (el.enable) el.enable.hidden = false;
        if (el.disable) el.disable.hidden = true;
        if (el.test) el.test.hidden = true;
      } else {
        el.setup.hidden = false;
        if (el.enable) el.enable.hidden = true;
        if (el.disable) el.disable.hidden = true;
        if (el.test) el.test.hidden = true;
      }
    }

    async function reconcileExistingPushSubscription(listEl) {
      var el = pushElements(listEl);

      if (!el.setup) return;

      if (
        !('serviceWorker' in navigator) ||
        !('PushManager' in window) ||
        !('Notification' in window)
      ) {
        setPushStatus(
          listEl,
          'unavailable',
          'Push alerts are not supported on this device.'
        );
        return;
      }

      if (Notification.permission === 'denied') {
        setPushStatus(
          listEl,
          'unavailable',
          'Notifications are blocked in this browser.'
        );
        return;
      }

      setPushStatus(
        listEl,
        'checking',
        'Checking this device…'
      );

      try {
        var registration = await navigator.serviceWorker.ready;
        var subscription = await registration.pushManager.getSubscription();

        if (!subscription) {
          if (Notification.permission === 'granted') {
            setPushStatus(
              listEl,
              'available',
              'Alerts are permitted, but this device is not registered.'
            );
          } else {
            setPushStatus(
              listEl,
              'available',
              'Enable alerts on this device.'
            );
          }
          return;
        }

        /*
         * Permission is already granted, so this is completely silent.
         * Re-save the current browser subscription so a browser-rotated
         * endpoint/keys cannot leave the server pointing at an old device.
         */
        if (Notification.permission === 'granted') {
          var response = await fetch('/notifications/push-subscribe', {
            method: 'POST',
            cache: 'no-store',
            headers: {
              'X-CSRF-TOKEN': csrf(),
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'Cache-Control': 'no-cache',
              'Pragma': 'no-cache'
            },
            body: JSON.stringify(subscription.toJSON())
          });

          if (!response.ok) {
            throw new Error('The server could not register this device.');
          }
        }

        setPushStatus(
          listEl,
          'enabled',
          '✓ Alerts enabled on this device'
        );
      } catch (err) {
        console.warn(
          'NPO CRM push reconciliation failed:',
          err
        );

        setPushStatus(
          listEl,
          'available',
          'Could not verify this device. Try Enable alerts again.'
        );
      }
    }

    function showPushSetup(listEl) {
      if (!listEl) return;

      reconcileExistingPushSubscription(listEl);
    }

        async function enablePushOnDevice(button) {
      var setup = button.closest('[data-push-setup]');
      if (!setup) return;

      var listEl = setup.parentElement || setup;

      button.disabled = true;
      button.textContent = 'Enabling…';

      try {
        if (
          !('serviceWorker' in navigator) ||
          !('PushManager' in window) ||
          !('Notification' in window)
        ) {
          throw new Error(
            'Push notifications are not supported on this device/browser.'
          );
        }

        var permission = await Notification.requestPermission();

        if (permission !== 'granted') {
          throw new Error(
            'Notification permission was not granted.'
          );
        }

        var registration = await navigator.serviceWorker.ready;

        var subscription =
          await registration.pushManager.getSubscription();

        if (!subscription) {
          var keyResponse = await fetch(
            '/notifications/push-key?_=' + Date.now(),
            {
              method: 'GET',
              cache: 'no-store',
              headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Cache-Control': 'no-cache',
                'Pragma': 'no-cache'
              }
            }
          );

          if (!keyResponse.ok) {
            throw new Error(
              'Could not prepare push notifications.'
            );
          }

          var keyData = await keyResponse.json();

          if (!keyData.publicKey) {
            throw new Error(
              'The push server did not return a public key.'
            );
          }

          subscription =
            await registration.pushManager.subscribe({
              userVisibleOnly: true,
              applicationServerKey:
                urlBase64ToUint8Array(keyData.publicKey)
            });
        }

        var saveResponse = await fetch(
          '/notifications/push-subscribe',
          {
            method: 'POST',
            cache: 'no-store',
            headers: {
              'X-CSRF-TOKEN': csrf(),
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'Cache-Control': 'no-cache',
              'Pragma': 'no-cache'
            },
            body: JSON.stringify(
              subscription.toJSON()
            )
          }
        );

        if (!saveResponse.ok) {
          var saveText = await saveResponse.text().catch(function () {
            return '';
          });

          throw new Error(
            saveText || 'Could not save this device for alerts.'
          );
        }

        setPushStatus(
          listEl,
          'enabled',
          '✓ Alerts enabled on this device'
        );
      } catch (err) {
        button.disabled = false;
        button.textContent = 'Enable alerts';

        setPushStatus(
          listEl,
          'available',
          err && err.message
            ? err.message
            : 'Could not enable notifications.'
        );
      }
    }


    async function testPushOnDevice(button) {
  button.disabled = true;
  button.textContent = 'Sending…';

  try {
    var response = await fetch(
      '/notifications/push-test',
      {
        method: 'POST',
        cache: 'no-store',
        headers: {
          'X-CSRF-TOKEN': csrf(),
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
          'Cache-Control': 'no-cache',
          'Pragma': 'no-cache'
        }
      }
    );

    var data = {};

    try {
      data = await response.json();
    } catch (e) {}

    if (!response.ok || !data.success) {
      throw new Error(
        data.message ||
        'Could not send the test alert.'
      );
    }

    if (Number(data.sent || 0) > 0) {
      button.textContent = '✓ Test sent';
    } else {
      button.textContent = '⚠ No push delivered';
    }

    setTimeout(function () {
      button.textContent = 'Send test alert';
      button.disabled = false;
    }, 2500);

  } catch (err) {
    button.disabled = false;
    button.textContent = 'Send test alert';

    window.alert(
      err && err.message
        ? err.message
        : 'Could not send the test alert.'
    );
  }
}


    async function disablePushOnDevice(button) {
      if (!window.confirm('Disable alerts on this device?')) {
        return;
      }

      var setup = button.closest('[data-push-setup]');
      if (!setup) return;

      var status = setup.querySelector('[data-push-status]');
      var enable = setup.querySelector('[data-enable-push]');
      var test = setup.querySelector('[data-test-push]');

      button.disabled = true;
      button.textContent = 'Disabling…';

      try {
        if (
          !('serviceWorker' in navigator) ||
          !('PushManager' in window)
        ) {
          throw new Error(
            'Push notifications are not supported on this device/browser.'
          );
        }

        var registration =
          await navigator.serviceWorker.ready;

        var subscription =
          await registration.pushManager.getSubscription();

        if (subscription) {
          var response = await fetch(
            '/notifications/push-subscribe',
            {
              method: 'DELETE',
              cache: 'no-store',
              headers: {
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'Cache-Control': 'no-cache',
                'Pragma': 'no-cache'
              },
              body: JSON.stringify({
                endpoint: subscription.endpoint
              })
            }
          );

          if (!response.ok) {
            throw new Error(
              'Could not disable alerts on the server.'
            );
          }

          await subscription.unsubscribe();
        }

        if (status) {
          status.textContent =
            'Alerts are disabled on this device';
        }

        if (enable) {
          enable.hidden = false;
          enable.disabled = false;
          enable.textContent = 'Enable alerts';
        }

        if (test) {
          test.hidden = true;
        }

        button.hidden = true;
        button.disabled = false;
        button.textContent = 'Disable alerts';

      } catch (err) {
        button.disabled = false;
        button.textContent = 'Disable alerts';

        window.alert(
          err && err.message
            ? err.message
            : 'Could not disable alerts.'
        );
      }
    }


    document.addEventListener('click', async function (e) {
      var enable = e.target.closest('[data-enable-push]');
      if (enable) {
        e.preventDefault();
        await enablePushOnDevice(enable);
        return;
      }

      var test = e.target.closest('[data-test-push]');
      if (test) {
        e.preventDefault();
        await testPushOnDevice(test);
        return;
      }

      var disable = e.target.closest('[data-disable-push]');
      if (disable) {
        e.preventDefault();
        await disablePushOnDevice(disable);
      }
    });


    function urlBase64ToUint8Array(base64String) {
      var padding =
        '='.repeat((4 - base64String.length % 4) % 4);

      var base64 =
        (base64String + padding)
          .replace(/-/g, '+')
          .replace(/_/g, '/');

      var rawData = window.atob(base64);

      var outputArray =
        new Uint8Array(rawData.length);

      for (var i = 0; i < rawData.length; ++i) {
        outputArray[i] =
          rawData.charCodeAt(i);
      }

      return outputArray;
    }

    // Close panels when clicking outside.
    document.addEventListener('click', function (e) {
      pairs.forEach(function (p) {
        if (! p.panel.hidden && ! p.panel.contains(e.target) && ! p.bell.contains(e.target)) {
          p.panel.hidden = true;
          setBellExpanded(p, false);
        }
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeAllPanels();
    });

    // Notification item clicks (delete + mark read + navigate).
    document.addEventListener('click', async function (e) {
      var del = e.target.closest('[data-delete-id]');
      if (del) {
        e.preventDefault();
        e.stopPropagation();
        var did = del.getAttribute('data-delete-id');
        try {
          var res = await fetch('/notifications/' + did + '/delete', {
            method: 'POST',
            headers: {
              'X-CSRF-TOKEN': csrf(),
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            }
          });
          if (res.ok) {
            var item = del.closest('.notif-item');
            if (item) item.remove();
            await refreshCount();
          }
        } catch (err) {}
        return;
      }

      var item = e.target.closest('.notif-item');
      if (item && item.hasAttribute('data-notif-id')) {
        var id = item.getAttribute('data-notif-id');
        var url = item.getAttribute('data-action-url');
        var wasUnread = item.classList.contains('unread');

        try {
          await fetch('/notifications/' + id + '/read', {
            method: 'POST',
            headers: {
              'X-CSRF-TOKEN': csrf(),
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            }
          });
          item.classList.remove('unread');
          if (wasUnread) await refreshCount();
        } catch (err) {}

        if (url) window.location.href = url;
      }
    });

    // If a user arrived from a system push, acknowledge the notification now
    // that the CRM page is open. This does not equate to completing the work.
    (function acknowledgePushArrival() {
      try {
        var params = new URLSearchParams(window.location.search);
        var notificationId = params.get('notification_id');
        if (! notificationId) return;
        fetch('/notifications/' + encodeURIComponent(notificationId) + '/read', {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          }
        }).finally(function () {
          params.delete('notification_id');
          var query = params.toString();
          window.history.replaceState({}, document.title, window.location.pathname + (query ? '?' + query : '') + window.location.hash);
        });
      } catch (e) {}
    })();

    refreshCount();
    setInterval(refreshCount, 20000);
  })();
    /* ============================================================
     MOBILE DRAWER
     ============================================================ */
  (function () {
    var btn      = document.getElementById('hamburger-btn');
    var drawer   = document.getElementById('drawer');
    var backdrop = document.getElementById('drawer-backdrop');
    var closeBtn = document.getElementById('drawer-close');

    if (! btn || ! drawer || ! backdrop) return;

    function openDrawer() {
      drawer.hidden = false;
      backdrop.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
      btn.setAttribute('aria-label', 'Close menu');
      btn.classList.add('is-open');
      document.body.classList.add('drawer-open');
    }

    function closeDrawer() {
      drawer.hidden = true;
      backdrop.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
      btn.setAttribute('aria-label', 'Open menu');
      btn.classList.remove('is-open');
      document.body.classList.remove('drawer-open');
    }

    // The hamburger is a true toggle: tap once to open, tap again to close.
    btn.setAttribute('aria-expanded', 'false');
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      drawer.hidden ? openDrawer() : closeDrawer();
    });
    closeBtn.addEventListener('click', closeDrawer);
    backdrop.addEventListener('click', closeDrawer);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && ! drawer.hidden) closeDrawer();
    });

        // Close drawer when a navigation link is clicked.
    // Skip submit buttons (e.g. the Logout form) so the confirm
    // dialog has a chance to run before the drawer disappears.
    drawer.querySelectorAll('a.drawer-link').forEach(function (link) {
      link.addEventListener('click', closeDrawer);
    });

    // For the logout form inside the drawer: close only AFTER
    // the user actually confirms (submit event, not click).
    drawer.querySelectorAll('form').forEach(function (form) {
      form.addEventListener('submit', closeDrawer);
    });
  })();

  /* ============================================================
     NOTIFICATIONS — Mirror badge count to both bells
     ============================================================ */
  (function () {
    var badgeDesktop = document.getElementById('notif-badge');
    var badgeMobile  = document.getElementById('notif-badge-m');

    // Hook into the existing refreshCount by watching both badges
    function syncBadges() {
      if (! badgeDesktop || ! badgeMobile) return;
      var count = badgeDesktop.hidden ? 0 : parseInt(badgeDesktop.textContent) || 0;
      if (count > 0) {
        badgeMobile.textContent = badgeDesktop.textContent;
        badgeMobile.hidden = false;
      } else {
        badgeMobile.hidden = true;
      }
    }

    // Sync every second (cheap)
    setInterval(syncBadges, 1000);
  })();
    /* ============================================================
     PWA — Service Worker + Install Prompt
     ============================================================ */
  (function () {
    // 1. Register service worker
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function () {
       navigator.serviceWorker.register('/service-worker.js?v=74', {
  updateViaCache: 'none'
}).catch(function () {});
      });
    }

    // 2. Detect iOS (no beforeinstallprompt support)
    var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true;

    // If already installed as an app, don't show install buttons
    if (isStandalone) {
      document.querySelectorAll('[data-install-app]').forEach(function (el) {
        el.hidden = true;
      });
      return;
    }

    // 3. Android / Chrome: show native install prompt
    var deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();
      deferredPrompt = e;
      document.querySelectorAll('[data-install-app]').forEach(function (el) {
        el.hidden = false;
      });
    });

    document.addEventListener('click', async function (e) {
      var btn = e.target.closest('[data-install-app]');
      if (!btn) return;
      e.preventDefault();

      if (deferredPrompt) {
        deferredPrompt.prompt();
        var choice = await deferredPrompt.userChoice;
        deferredPrompt = null;
        if (choice.outcome === 'accepted') {
          document.querySelectorAll('[data-install-app]').forEach(function (el) {
            el.hidden = true;
          });
        }
      } else if (isIOS) {
        alert(
          '📱 To install NPO CRM on your iPhone:\n\n' +
          '1. Tap the Share button (⬆️) at the bottom of Safari\n' +
          '2. Scroll down and tap "Add to Home Screen"\n' +
          '3. Tap "Add"\n\n' +
          'The app will appear on your home screen like any other app.'
        );
      } else {
        alert(
          '📱 To install NPO CRM:\n\n' +
          'Open this page in Chrome (Android) or Safari (iOS), ' +
          'then look for "Install App" or "Add to Home Screen" in the browser menu.'
        );
      }
    });

    // 4. Show install button on iOS devices immediately
    if (isIOS) {
      document.querySelectorAll('[data-install-app]').forEach(function (el) {
        el.hidden = false;
      });
    }
  })();
    /* ============================================================
     LOGOUT — confirm + safety net
     Catches any leftover <a href="/logout"> anchors from old
     bookmarks, cached pages, or copy-pasted links. Forces a
     confirm and blocks the navigation if the user cancels.
     ============================================================ */
  document.addEventListener('click', function (e) {
    var link = e.target.closest('a[href$="/logout"]');
    if (!link) return;

    if (!confirm('Log out of NPO CRM?')) {
      e.preventDefault();
      e.stopPropagation();
      return false;
    }
  }, true);  // capture phase — runs before drawer-close listeners
  
    /* ============================================================
     NOTIFICATION SOUND
     Synthesizes a two-tone chime with Web Audio API — no files.
     Handles browser autoplay policy by unlocking on first gesture.
     ============================================================ */
  (function () {

    var audioCtx = null;
    var unlocked = false;

    function unlockAudio() {
      if (unlocked) return;
      try {
        var Ctx = window.AudioContext || window.webkitAudioContext;
        if (! Ctx) return;
        audioCtx = new Ctx();
        if (audioCtx.state === 'suspended') audioCtx.resume();
        unlocked = true;
      } catch (e) { /* silent */ }
    }

    // Unlock on the first user gesture (mobile requirement)
    ['click', 'touchstart', 'keydown'].forEach(function (evt) {
      document.addEventListener(evt, unlockAudio, { once: true, passive: true });
    });

    function playTone(freq, startAt, duration, gainVal) {
      var osc  = audioCtx.createOscillator();
      var gain = audioCtx.createGain();

      osc.type = 'sine';
      osc.frequency.value = freq;

      gain.gain.setValueAtTime(0, startAt);
      gain.gain.linearRampToValueAtTime(gainVal, startAt + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.001, startAt + duration);

      osc.connect(gain);
      gain.connect(audioCtx.destination);

      osc.start(startAt);
      osc.stop(startAt + duration + 0.05);
    }

    /**
     * Play the notification chime — two ascending tones, gentle.
     * Silent if audio has never been unlocked (user hasn't tapped yet).
     */
    function chime() {
      // If user hasn't interacted with the page yet, we can't play.
      // Try to unlock anyway — this covers desktop where user has already
      // clicked somewhere but the unlock handler may not have fired.
      if (! unlocked) unlockAudio();
      if (! audioCtx) return;

      // iOS Safari sometimes puts it to sleep
      if (audioCtx.state === 'suspended') {
        audioCtx.resume().then(function () {
          var now = audioCtx.currentTime;
          playTone(880, now,       0.18, 0.18);
          playTone(1318.5, now + 0.10, 0.28, 0.15);
        }).catch(function () {});
        return;
      }

      var now = audioCtx.currentTime;
      playTone(880, now,        0.18, 0.18);   // A5
      playTone(1318.5, now + 0.10, 0.28, 0.15); // E6
    }

    // Expose globally so other code can trigger a sound
    window.NPOCRM = window.NPOCRM || {};
    window.NPOCRM.chime = chime;
    window.NPOCRM.unlockAudio = unlockAudio;

  })();
    /* ============================================================
     PROJECT PICKER — searchable autocomplete
     Attaches to any element with [data-project-picker].
     Works inside modals because it uses event delegation
     (re-binds automatically when modals inject content).
     ============================================================ */
  (function () {

    function initPicker(wrap) {
      if (! wrap || wrap.dataset.ppReady === '1') return;
      wrap.dataset.ppReady = '1';

      var fieldId   = wrap.dataset.fieldId;
      var hidden    = document.getElementById(fieldId + '_value');
      var search    = document.getElementById(fieldId + '_search');
      var results   = wrap.querySelector('.pp-results');
      var clearBtn  = wrap.querySelector('.pp-clear');
      var inputWrap = wrap.querySelector('.pp-input-wrap');

      if (! hidden || ! search || ! results) return;

      var url      = search.dataset.searchUrl || '/api/projects/search';
      var timer    = null;
      var activeIx = -1;
      var items    = [];
      var controller = null;
      var autoSubmit = wrap.dataset.autoSubmit === '1';
      var searchContext = wrap.dataset.searchContext || '';
      var searchSource  = wrap.dataset.searchSource || '';
      var searchScope   = wrap.dataset.searchScope || '';

      function show(el) { el.hidden = false; }
      function hide(el) { el.hidden = true; }

      function markHasValue() {
        if (search.value.trim() !== '') inputWrap.classList.add('has-value');
        else inputWrap.classList.remove('has-value');
      }

      function notifyChange() {
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
        if (autoSubmit && hidden.form) hidden.form.requestSubmit();
      }

      function clearAll() {
        hidden.value = '';
        search.value = '';
        markHasValue();
        hide(results);
        items = [];
        activeIx = -1;
        notifyChange();
      }

      function setActive(ix) {
        items.forEach(function (el, i) { el.classList.toggle('active', i === ix); });
        activeIx = ix;
        var el = items[ix];
        if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
      }

      function renderResults(list) {
        items = [];
        activeIx = -1;

        if (! list || list.length === 0) {
          results.innerHTML = '<div class="pp-empty">No projects found</div>';
          show(results);
          return;
        }

        results.innerHTML = '';
        list.forEach(function (p) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'pp-item';
          btn.innerHTML =
            '<span class="pp-name">' + escape(p.name) + '</span>' +
            (p.location ? '<span class="pp-loc">📍 ' + escape(p.location) + '</span>' : '');
          btn.addEventListener('click', function () {
            hidden.value = p.id;
            search.value = p.name + (p.location ? ' · ' + p.location : '');
            markHasValue();
            hide(results);
            notifyChange();
          });
          results.appendChild(btn);
          items.push(btn);
        });
        show(results);
      }

      function escape(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
      }

      function fetchResults(q) {
        results.innerHTML = '<div class="pp-loading">Searching…</div>';
        show(results);

        if (controller) controller.abort();
        controller = new AbortController();

        var params = new URLSearchParams({ q: q });
        if (searchContext) params.set('context', searchContext);
        if (searchSource) params.set('source', searchSource);
        if (searchScope) params.set('scope', searchScope);

        fetch(url + '?' + params.toString(), {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          signal: controller.signal
        })
        .then(function (r) { return r.json(); })
        .then(function (data) { renderResults(data.results || []); })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          results.innerHTML = '<div class="pp-empty">Could not load results</div>';
        });
      }

      search.addEventListener('input', function () {
        var q = search.value.trim();
        markHasValue();
        hidden.value = '';   // must re-pick

        clearTimeout(timer);
        if (q.length < 2) {
          hide(results);
          return;
        }
        timer = setTimeout(function () { fetchResults(q); }, 180);
      });

      search.addEventListener('focus', function () {
        var q = search.value.trim();
        if (q.length >= 2 && results.hidden) fetchResults(q);
      });

      search.addEventListener('keydown', function (e) {
        if (results.hidden) return;

        if (e.key === 'ArrowDown') {
          e.preventDefault();
          if (items.length) setActive((activeIx + 1) % items.length);
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          if (items.length) setActive((activeIx - 1 + items.length) % items.length);
        } else if (e.key === 'Enter') {
          if (activeIx >= 0 && items[activeIx]) {
            e.preventDefault();
            items[activeIx].click();
          }
        } else if (e.key === 'Escape') {
          hide(results);
        }
      });

      if (clearBtn) {
        clearBtn.addEventListener('click', function (e) {
          e.preventDefault();
          clearAll();
          search.focus();
        });
      }

      // Close when clicking outside
      document.addEventListener('click', function (e) {
        if (! wrap.contains(e.target)) hide(results);
      });

      markHasValue();
    }

    // Attach to all pickers that exist on page load
    document.querySelectorAll('[data-project-picker]').forEach(initPicker);

    // Watch for modals that inject pickers later
    var mo = new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        m.addedNodes.forEach(function (n) {
          if (! n || n.nodeType !== 1) return;
          if (n.matches && n.matches('[data-project-picker]')) initPicker(n);
          if (n.querySelectorAll) {
            n.querySelectorAll('[data-project-picker]').forEach(initPicker);
          }
        });
      });
    });
    mo.observe(document.body, { childList: true, subtree: true });

  })();
    /* ============================================================
     AUTO-MARK REQUIRED FIELDS
     Wraps the trailing * in every label as <span class="req">*
     so it shows red. Runs on page load and again after every
     modal opens. Zero HTML edits needed.
     ============================================================ */
  function markRequiredLabels(root) {
    var scope = root || document;
    scope.querySelectorAll('label').forEach(function (lbl) {
      if (lbl.querySelector('.req')) return;   // already marked
      var html = lbl.innerHTML;
      // Match a trailing * possibly preceded by whitespace
      if (/\*\s*$/.test(html.trim())) {
        lbl.innerHTML = html.replace(/\*\s*$/, '<span class="req">*</span>');
      }
    });
  }

  // Run on page load
  document.addEventListener('DOMContentLoaded', function () {
    markRequiredLabels(document);
  });

  // Re-run whenever the modal content changes
  var mo = new MutationObserver(function (mutations) {
    mutations.forEach(function (m) {
      m.addedNodes.forEach(function (n) {
        if (n.nodeType === 1) markRequiredLabels(n);
      });
    });
  });
  mo.observe(document.body, { childList: true, subtree: true });
    /* ============================================================
     UNSHARE AGENT FROM LEAD (leads list)
     Admin-only. Removes a co-agent from a lead without leaving
     the page. Removes the pill from the DOM on success.
     ============================================================ */
  document.addEventListener('click', async function (e) {
    var btn = e.target.closest('[data-unshare-agent]');
    if (! btn) return;

    e.preventDefault();
    e.stopPropagation();

    var leadId  = btn.getAttribute('data-lead');
    var agentId = btn.getAttribute('data-agent');

    if (! leadId || ! agentId) return;

    if (! confirm('Remove this agent from the lead?')) return;

    var originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '…';

    try {
      var csrfMeta = document.querySelector('meta[name="csrf-token"]');
      var res = await fetch('/leads/unshare-agent', {
        method: 'POST',
        headers: {
          'Content-Type':  'application/json',
          'Accept':        'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN':  csrfMeta ? csrfMeta.getAttribute('content') : ''
        },
        body: JSON.stringify({ lead_id: leadId, agent_id: agentId })
      });

      var data = await res.json().catch(function () { return {}; });

      if (! res.ok || ! data.success) {
        alert('❌ ' + (data.message || 'Could not remove the agent.'));
        btn.disabled = false;
        btn.textContent = originalText;
        return;
      }

      /* Success — remove the pill from the DOM */
      var pill = btn.closest('.shared-agent-pill');
      var row  = btn.closest('.shared-agents-row');

      if (pill) pill.remove();

      // If the row has no more pills, hide the whole row
      if (row && row.querySelectorAll('.shared-agent-pill').length === 0) {
        row.remove();
      }

    } catch (err) {
      console.error(err);
      alert('❌ Network error. Check console.');
      btn.disabled = false;
      btn.textContent = originalText;
    }
  }, true);
  
    /* ============================================================
     CLICKABLE LEAD ROWS / CARDS
     Any element with [data-lead-url] navigates to that URL
     on click — UNLESS the click landed on an interactive child
     (link, button, form control, unshare ×, modal trigger, etc.).
     ============================================================ */
  document.addEventListener('click', function (e) {
    // 1. If the click landed on (or inside) an interactive element, bail.
    //    This preserves the Log / Task / Agent / View buttons, the
    //    shared-agent × button, project-picker inputs, and any link.
    var interactive = e.target.closest(
      'a, button, input, select, textarea, label, ' +
      '[data-modal], [data-unshare-agent], [data-project-picker], [role="button"]'
    );
    if (interactive) return;

    // 2. Only act if the click is inside a clickable row/card.
    var row = e.target.closest('[data-lead-url]');
    if (!row) return;

    // 3. Don't navigate if the user was selecting text to copy.
    var sel = window.getSelection ? String(window.getSelection()) : '';
    if (sel && sel.length > 0) return;

    window.location.href = row.getAttribute('data-lead-url');
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var row = e.target.closest('[data-lead-url]');
    if (!row || e.target.closest('a, button, input, select, textarea, label, [role="button"]')) return;
    e.preventDefault();
    window.location.href = row.getAttribute('data-lead-url');
  });
    /* ============================================================
     SMART BACK — return to the page the user actually came from
     ------------------------------------------------------------
     Pages should not force people back to the dashboard. Browser history
     is the primary source of truth; each page supplies a safe fallback.
     ============================================================ */
  (function () {
    document.querySelectorAll('.js-smart-back').forEach(function (link) {
      link.addEventListener('click', function (e) {
        if (e.defaultPrevented) return;

        // Same-tab browser history is the correct return path for normal CRM
        // navigation. It preserves search/filter state and the originating page.
        if (window.history && window.history.length > 1 && document.referrer) {
          try {
            var ref = new URL(document.referrer, window.location.href);
            if (ref.origin === window.location.origin) {
              e.preventDefault();
              window.history.back();
              return;
            }
          } catch (err) {}
        }

        // Direct entry / external entry: use the page's explicit safe fallback.
        var fallback = link.getAttribute('data-back-fallback') || link.getAttribute('href');
        if (fallback) {
          e.preventDefault();
          window.location.href = fallback;
        }
      });
    });
  })();

  /* ============================================================
     DOUBLE-BACK TO EXIT — mobile dashboard only
     ------------------------------------------------------------
     On mobile, pressing the browser back button while on the
     dashboard shows a "press back again to exit" toast instead
     of immediately leaving the CRM. The second press within
     2.5 seconds actually exits.

     Only runs:
       - On mobile (pointer: coarse)
       - On the dashboard (path = / )
       - Once per browser tab session (sessionStorage guard)

     Internal navigation between CRM pages is unaffected.
     ============================================================ */
  (function () {
    // Mobile only
    if (! window.matchMedia || ! window.matchMedia('(pointer: coarse)').matches) return;

    // Dashboard only
    var path = location.pathname;
    if (path !== '/' && path !== '') return;

    // Once per tab session
    try {
      if (sessionStorage.getItem('crmExitTrapInstalled')) return;
      sessionStorage.setItem('crmExitTrapInstalled', '1');
    } catch (e) {
      // sessionStorage blocked (private mode) — skip trap
      return;
    }

    var SHIELD_KEY = 'crmShield';
    var ARM_WINDOW = 2500;
    var armed = false;
    var armTimer = null;
    var skipping = false;

    // Push a shield entry so the first back press lands on it
    try {
      history.pushState({ [SHIELD_KEY]: true }, '', location.href);
    } catch (e) {
      return;   // pushState blocked — bail silently
    }

    function showToast() {
      var existing = document.getElementById('crm-back-toast');
      if (existing) existing.remove();

      var toast = document.createElement('div');
      toast.id = 'crm-back-toast';
      toast.textContent = 'Press back again to exit';
      toast.style.cssText =
        'position:fixed;bottom:32px;left:50%;transform:translateX(-50%);' +
        'background:rgba(0,0,0,.85);color:#fff;padding:12px 22px;' +
        'border-radius:24px;font-size:14px;font-weight:600;z-index:9999;' +
        'box-shadow:0 4px 20px rgba(0,0,0,.3);opacity:0;' +
        'transition:opacity .2s;pointer-events:none;white-space:nowrap;';
      document.body.appendChild(toast);

      requestAnimationFrame(function () { toast.style.opacity = '1'; });

      setTimeout(function () {
        toast.style.opacity = '0';
        setTimeout(function () { toast.remove(); }, 250);
      }, 2200);
    }

    window.addEventListener('popstate', function () {
      if (skipping) {
        skipping = false;
        return;
      }

      if (armed) {
        // Second back within window — let the browser exit
        armed = false;
        clearTimeout(armTimer);
        skipping = true;
        history.go(-1);
        return;
      }

      // First back — re-arm the shield and warn the user
      history.pushState({ [SHIELD_KEY]: true }, '', location.href);
      showToast();
      armed = true;
      clearTimeout(armTimer);
      armTimer = setTimeout(function () { armed = false; }, ARM_WINDOW);
    });
  })();
    /* ============================================================
     SESSION CHECK ON BFCACHE RESTORE
     ------------------------------------------------------------
     Mobile browsers restore authenticated pages from bfcache on
     back navigation. That's fast — but if the user logged out or
     their session expired while they were away, they'd see a
     stale dashboard.

     When a page is restored from bfcache (event.persisted = true),
     we ping a lightweight endpoint. If the session is gone, we
     reload the page — the server then redirects to /login.

     If the session is still valid, we do nothing. The page stays
     instant. No network round-trip on normal back navigation.
     ============================================================ */
  window.addEventListener('pageshow', function (event) {
    const navEntry = (window.performance && performance.getEntriesByType)
      ? performance.getEntriesByType('navigation')[0]
      : null;
    const isBackForward = event.persisted || (navEntry && navEntry.type === 'back_forward');
    if (! isBackForward) return;

    // Work queues must never be restored from a stale browser snapshot.
    // A task may have been completed on the lead page since this page was
    // rendered, so force a real server refresh when Back restores the queue.
    const path = window.location.pathname;
    if (path === '/' || path === '/tasks') {
      window.location.reload();
      return;
    }

    fetch('/api/session-check', {
      method: 'GET',
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (res) {
      if (res.status === 401) {
        window.location.reload();
      }
    }).catch(function () {
      // Offline/network error: keep the restored page usable.
    });
  });

  /* ============================================================
     ANDROID DEVICE LEAD CAPTURE — Add Lead mobile actions
     - Phone Contacts: use Chrome Contact Picker when available; otherwise
       hand off to the NPO CRM Android Device Bridge.
     - WhatsApp Contact: open WhatsApp with clear instructions to use
       Android's Share Contact → NPO CRM flow.
     - Recent Calls: hand off to the Device Bridge call-log picker.
     - All paths return to the existing Add Lead form for review/save.
     ============================================================ */
  document.addEventListener('click', function(event){
    const button = event.target.closest('[data-device-contact-picker],[data-device-whatsapp-share],[data-device-recent-calls]');
    if (!button) return;
    event.preventDefault();

    const status = document.querySelector('[data-device-lead-status]');
    const setStatus = function(message){ if (status) status.textContent = message; };
    const openBridge = function(host){
      window.location.href = 'npocrm://' + host;
    };

    if (button.hasAttribute('data-device-contact-picker')) {
      if (navigator.contacts && typeof navigator.contacts.select === 'function') {
        setStatus('Opening your phone contacts…');
        navigator.contacts.select(['name','tel','email'], {multiple:false})
          .then(function(contacts){
            const contact = contacts && contacts[0] ? contacts[0] : null;
            const name = contact && contact.name && contact.name[0] ? contact.name[0] : '';
            const phone = contact && contact.tel && contact.tel[0] ? contact.tel[0] : '';
            const email = contact && contact.email && contact.email[0] ? contact.email[0] : '';
            const nameInput = document.querySelector('[data-device-lead-form] [name="customer_name"]');
            const phoneInput = document.querySelector('[data-device-lead-form] [name="phone"]');
            const emailInput = document.querySelector('[data-device-lead-form] [name="email"]');
            if (nameInput) nameInput.value = name;
            if (phoneInput) phoneInput.value = phone;
            if (emailInput && email) emailInput.value = email;
            setStatus(phone ? 'Contact selected. Please review the details and save the lead.' : 'That contact has no phone number. Please select another contact.');
          })
          .catch(function(){
            setStatus('Phone Contacts was cancelled or is unavailable. Opening the NPO CRM phone contact picker…');
            window.setTimeout(function(){ openBridge('phone-contacts'); }, 120);
          });
        return;
      }
      setStatus('Opening your phone contacts…');
      openBridge('phone-contacts');
      return;
    }

    if (button.hasAttribute('data-device-recent-calls')) {
      setStatus('Opening your recent calls…');
      openBridge('recent-calls');
      return;
    }

    if (button.hasAttribute('data-device-whatsapp-share')) {
      setStatus('Opening WhatsApp. In WhatsApp, open the contact → Share contact → choose NPO CRM.');
      openBridge('whatsapp-contact');
    }
  });

  /* ============================================================
     ANDROID DEVICE LEAD IMPORT
     - Share target returns to /?device_import=1.
     - The modal consumes the one-time server-side payload.
     ============================================================ */
  document.addEventListener('DOMContentLoaded', function(){
    try {
      const params = new URLSearchParams(window.location.search);
      if (params.get('device_import') !== '1') return;
      if (typeof window.NPO_CRM_OPEN_MODAL !== 'function') return;
      window.NPO_CRM_OPEN_MODAL('add-lead', { device_import: '1' });
      params.delete('device_import');
      const clean = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
      window.history.replaceState({}, document.title, clean);
    } catch (e) {}
  });

})();
(function(){

  // When a task was just completed, put keyboard focus on the obvious return-to-work action.
  const returnToWork = document.getElementById('lead-wb-return-work');
  if (returnToWork) {
    window.setTimeout(function () { returnToWork.focus({ preventScroll: true }); }, 80);
  }
})();

/* ============================================================
   CRM FILTERS — shared immediate filter behaviour
   ------------------------------------------------------------
   Select controls marked [data-filter-auto-submit] submit their
   containing GET filter form immediately after a selection.
   Search fields remain manual so typing never causes reloads.
   ============================================================ */
(function () {
  document.addEventListener('change', function (e) {
    var control = e.target.closest('[data-filter-auto-submit]');
    if (!control) return;

    var form = control.closest('[data-crm-filter-form]');
    if (!form) return;

    form.requestSubmit();
  });
})();
