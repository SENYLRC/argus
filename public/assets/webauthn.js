// Security keys (YubiKey etc.) for two-step sign-in — the browser half of
// App\Domain\SecurityKeyService. Two uses:
//   <form data-webauthn-register> on My profile: add a key
//   <button data-webauthn-login> on the sign-in code step: use a key
// The server's options arrive with binary values as "=?BINARY?B?…?=" strings
// (lbuchs/webauthn); responses go back base64-encoded in a form POST, with the
// page's CSRF token. Status and errors are announced via [data-webauthn-status].
(function () {
  'use strict';

  var supported = !!(window.PublicKeyCredential && navigator.credentials);

  // "=?BINARY?B?<base64>?=" → ArrayBuffer, recursively.
  function toBuffers(obj) {
    if (typeof obj === 'string') {
      var m = obj.match(/^=\?BINARY\?B\?(.*)\?=$/);
      if (!m) return obj;
      var bin = atob(m[1]), buf = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
      return buf.buffer;
    }
    if (Array.isArray(obj)) return obj.map(toBuffers);
    if (obj && typeof obj === 'object') {
      var out = {};
      Object.keys(obj).forEach(function (k) { out[k] = toBuffers(obj[k]); });
      return out;
    }
    return obj;
  }

  function b64(buffer) {
    var bytes = new Uint8Array(buffer), s = '';
    for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return btoa(s);
  }

  function post(url, fields) {
    var fd = new FormData();
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        return r.text().then(function (t) {
          try { return JSON.parse(t); } catch (e) { return { ok: false, error: t || ('Error ' + r.status) }; }
        });
      });
  }

  function say(el, msg, isError) {
    if (!el) return;
    el.textContent = msg;
    el.classList.toggle('field-error', !!isError);
  }

  // Browser refusals (cancelled, timed out, wrong key) → plain language.
  // $startedAt is when the browser prompt was requested: a refusal within about
  // a second means nothing was ever offered (no key reachable, no passkey
  // provider), as opposed to the person cancelling or the prompt timing out.
  var rpId = ''; // the host name the server says keys belong to, for the SecurityError message
  function explain(err, startedAt) {
    if (window.console) console.error('WebAuthn:', err);
    if (err && err.name === 'NotAllowedError' && startedAt && (Date.now() - startedAt) < 1500) {
      return 'Your browser refused straight away, so nothing was offered to use. Plug in your security key, or turn on a passkey provider such as Bitwarden (unlocked, with passkeys switched on), then try again. (NotAllowedError)';
    }
    if (err && err.name === 'NotAllowedError') return 'Cancelled or timed out. Try again and touch your key when it blinks. (NotAllowedError)';
    if (err && err.name === 'InvalidStateError') return 'That key is already registered on this account.';
    if (err && err.name === 'SecurityError') {
      if (rpId && rpId !== location.hostname) {
        return 'Keys for this site are tied to the address ' + rpId + ', but you opened ' + location.hostname + '. Open the site at https://' + rpId + ' and try again.';
      }
      return 'Your browser refused the key on this address (' + location.hostname + '). Security keys need a secure https address, and keys are tied to the exact host name they were registered on.';
    }
    return (err && err.message) || 'Something went wrong with the security key.';
  }

  // ── Add a key (My profile) ────────────────────────────────────────────────
  document.querySelectorAll('form[data-webauthn-register]').forEach(function (form) {
    var status = form.querySelector('[data-webauthn-status]');
    if (!supported) {
      say(status, 'This browser can’t use security keys. Try a current Chrome, Edge, Firefox or Safari.', true);
      form.querySelector('button[type=submit]').disabled = true;
      return;
    }
    form.querySelector('button[type=submit]').disabled = false; // enabled only once this handler is attached
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var csrf = form.querySelector('[name=_csrf]').value;
      var name = form.querySelector('[name=name]').value;
      var pw = form.querySelector('[name=current_password]').value;
      var kindEl = form.querySelector('[name=kind]:checked');
      var kind = kindEl ? kindEl.value : 'key';
      var startedAt = 0;
      say(status, 'Checking your password…');
      post('/account/keys/options', { _csrf: csrf, current_password: pw, kind: kind })
        .then(function (r) {
          if (!r.ok) throw new Error(r.error);
          say(status, kind === 'passkey'
            ? 'Follow your browser’s prompt to create the passkey…'
            : 'Insert your security key and touch it when it blinks…');
          rpId = (r.options && r.options.publicKey && r.options.publicKey.rp && r.options.publicKey.rp.id) || '';
          startedAt = Date.now();
          return navigator.credentials.create(toBuffers(r.options));
        })
        .then(function (cred) {
          say(status, 'Saving your key…');
          return post('/account/keys', {
            _csrf: csrf, name: name,
            clientDataJSON: b64(cred.response.clientDataJSON),
            attestationObject: b64(cred.response.attestationObject)
          });
        })
        .then(function (r) {
          if (!r.ok) throw new Error(r.error);
          window.location.assign(r.redirect.replace('#', '?k=' + Date.now() + '#'));
        })
        .catch(function (err) { say(status, explain(err, startedAt), true); });
    });
  });

  // ── Sign in with a key (two-step code step) ───────────────────────────────
  document.querySelectorAll('[data-webauthn-login]').forEach(function (btn) {
    var status = document.querySelector('[data-webauthn-status]');
    if (!supported) {
      btn.disabled = true;
      say(status, 'This browser can’t use security keys — enter a code instead.', true);
      return;
    }
    btn.addEventListener('click', function () {
      var csrf = btn.getAttribute('data-csrf');
      btn.disabled = true;
      var startedAt = 0;
      say(status, 'Insert your security key and touch it when it blinks…');
      post('/login/verify/key/options', { _csrf: csrf })
        .then(function (r) {
          if (!r.ok) { if (r.redirect) window.location.href = r.redirect; throw new Error(r.error); }
          rpId = (r.options && r.options.publicKey && r.options.publicKey.rpId) || '';
          startedAt = Date.now();
          return navigator.credentials.get(toBuffers(r.options));
        })
        .then(function (cred) {
          say(status, 'Checking…');
          return post('/login/verify/key', {
            _csrf: csrf,
            credentialId: b64(cred.rawId),
            clientDataJSON: b64(cred.response.clientDataJSON),
            authenticatorData: b64(cred.response.authenticatorData),
            signature: b64(cred.response.signature)
          });
        })
        .then(function (r) {
          if (r.redirect) { window.location.href = r.redirect; return; }
          if (!r.ok) throw new Error(r.error);
        })
        .catch(function (err) { say(status, explain(err, startedAt), true); btn.disabled = false; });
    });
  });
})();
