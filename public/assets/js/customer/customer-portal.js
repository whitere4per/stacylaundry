document.addEventListener('DOMContentLoaded', function () {
  // Shared customer sidebar: desktop collapse and mobile drawer
  const menuBtn = document.querySelector('.cp-menu-btn');
  const collapseBtn = document.querySelector('.cp-sidebar-collapse');
  const overlay = document.querySelector('.cp-sidebar-overlay');
  if (menuBtn) {
    menuBtn.addEventListener('click', function () {
      const isOpen = document.body.classList.toggle('cp-nav-open');
      menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
  }
  if (collapseBtn) {
    collapseBtn.addEventListener('click', function () {
      const isCollapsed = document.body.classList.toggle('cp-sidebar-collapsed');
      collapseBtn.setAttribute('aria-pressed', isCollapsed ? 'true' : 'false');
      collapseBtn.setAttribute('aria-label', isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
      collapseBtn.textContent = isCollapsed ? '›' : '‹';
    });
  }
  if (overlay) {
    overlay.addEventListener('click', function () {
      document.body.classList.remove('cp-nav-open');
      if (menuBtn) menuBtn.setAttribute('aria-expanded', 'false');
    });
  }
  document.querySelectorAll('.cp-sidebar .cp-subnav a').forEach(function (link) {
    link.addEventListener('click', function () {
      document.body.classList.remove('cp-nav-open');
      if (menuBtn) menuBtn.setAttribute('aria-expanded', 'false');
    });

    // Shared customer confirmation dialog
    const confirmOverlay = document.querySelector('.cp-confirm-overlay');
    let pendingCustomerForm = null;
    let pendingCustomerLink = null;
    let previousCustomerFocus = null;
    if (confirmOverlay) {
      const cancelButton = confirmOverlay.querySelector('.cp-confirm-cancel');
      const acceptButton = confirmOverlay.querySelector('.cp-confirm-accept');
      const closeConfirm = function (restoreFocus) {
        confirmOverlay.hidden = true;
        pendingCustomerForm = null;
        pendingCustomerLink = null;
        acceptButton.disabled = false;
        acceptButton.textContent = 'Confirm';
        if (restoreFocus !== false && previousCustomerFocus) previousCustomerFocus.focus();
      };
      const openConfirm = function (trigger, config) {
        previousCustomerFocus = trigger;
        confirmOverlay.querySelector('#cp-confirm-title').textContent = config.title;
        confirmOverlay.querySelector('#cp-confirm-message').textContent = config.message;
        acceptButton.textContent = config.label;
        acceptButton.dataset.variant = config.variant;
        confirmOverlay.querySelector('.cp-confirm-icon').textContent = config.variant === 'destructive' ? '!' : config.variant === 'financial' ? '₱' : '✓';
        confirmOverlay.hidden = false;
        cancelButton.focus();
      };

      document.querySelectorAll('.cp-confirm-link').forEach(function (link) {
        link.addEventListener('click', function (event) {
          event.preventDefault();
          const dirtyForms = Array.from(document.querySelectorAll('form')).some(function (form) {
            return form.dataset.dirty === 'true' && form.dataset.submitting !== 'true';
          });
          pendingCustomerLink = link;
          openConfirm(link, {
            title: dirtyForms ? 'Unsaved Changes' : (link.dataset.confirmTitle || 'Confirm Action'),
            message: dirtyForms ? 'You have unsaved changes. Do you want to leave without saving?' : (link.dataset.confirm || 'Continue with this action?'),
            label: dirtyForms ? 'Discard Changes' : (link.dataset.confirmLabel || 'Confirm'),
            variant: link.dataset.confirmType || 'informational'
          });
        });
      });

      document.querySelectorAll('.cp-confirm-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
          if (form.dataset.confirmed === 'true') return;
          event.preventDefault();
          pendingCustomerForm = form;
          openConfirm(form.querySelector('[type="submit"]'), {
            title: form.dataset.confirmTitle || 'Confirm Action',
            message: form.dataset.confirm || 'Continue with this action?',
            label: form.dataset.confirmLabel || 'Confirm',
            variant: form.dataset.confirmType || 'informational'
          });
        });
      });

      cancelButton.addEventListener('click', function () { closeConfirm(true); });
      confirmOverlay.addEventListener('click', function (event) {
        if (event.target === confirmOverlay) closeConfirm(true);
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !confirmOverlay.hidden) closeConfirm(true);
        if (event.key === 'Tab' && !confirmOverlay.hidden) {
          if (event.shiftKey && document.activeElement === cancelButton) {
            event.preventDefault();
            acceptButton.focus();
          } else if (!event.shiftKey && document.activeElement === acceptButton) {
            event.preventDefault();
            cancelButton.focus();
          }
        }
      });
      acceptButton.addEventListener('click', function () {
        acceptButton.disabled = true;
        acceptButton.textContent = 'Processing...';
        if (pendingCustomerForm) {
          const form = pendingCustomerForm;
          form.dataset.confirmed = 'true';
          closeConfirm(false);
          form.requestSubmit();
        } else if (pendingCustomerLink) {
          const href = pendingCustomerLink.href;
          closeConfirm(false);
          window.location.assign(href);
        }
      });
    }

    document.querySelectorAll('form').forEach(function (form) {
      form.addEventListener('input', function () {
        if (!form.classList.contains('cp-prevent-double') || form.dataset.confirmed !== 'true') {
          form.dataset.dirty = 'true';
        }
      });
      form.addEventListener('change', function () { form.dataset.dirty = 'true'; });
    });

    document.querySelectorAll('a[href]').forEach(function (link) {
      if (link.classList.contains('cp-confirm-link')) return;
      link.addEventListener('click', function (event) {
        if (link.protocol !== window.location.protocol || link.host !== window.location.host) return;
        if (!Array.from(document.querySelectorAll('form')).some(function (form) { return form.dataset.dirty === 'true' && form.dataset.submitting !== 'true'; })) return;
        event.preventDefault();
        pendingCustomerLink = link;
        openConfirm(link, {
          title: 'Unsaved Changes',
          message: 'You have unsaved changes. Do you want to leave without saving?',
          label: 'Discard Changes',
          variant: 'warning'
        });
      });
    });

    window.addEventListener('beforeunload', function (event) {
      if (Array.from(document.querySelectorAll('form')).some(function (form) { return form.dataset.dirty === 'true' && form.dataset.submitting !== 'true'; })) {
        event.preventDefault();
        event.returnValue = '';
      }
    });
  });

  // Toast notifications
  document.querySelectorAll('.cp-toast').forEach(function (toast) {
    const closeBtn = toast.querySelector('.cp-toast-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', function () { toast.remove(); });
    }
    if (toast.dataset.critical !== 'true') {
      window.setTimeout(function () { toast.remove(); }, 5000);
    }
  });

  // Printable receipts
  document.querySelectorAll('[data-print-receipt]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      window.print();
    });
  });

  // Prevent double form submission
  document.querySelectorAll('form.cp-prevent-double').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (form.dataset.submitting === 'true') {
        e.preventDefault();
        return;
      }
      form.dataset.submitting = 'true';
      const submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = true;
        if (submitBtn.dataset.loadingLabel) {
          submitBtn.textContent = submitBtn.dataset.loadingLabel;
        }
      }
    });
  });

  // Show/hide password eye toggle
  const eyeOpenSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  const eyeOffSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
  document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const target = document.getElementById(btn.dataset.togglePassword);
      if (!target) return;
      const showing = target.type === 'text';
      target.type = showing ? 'password' : 'text';
      btn.innerHTML = showing ? eyeOpenSvg : eyeOffSvg;
      btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
      btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    });
  });
});
