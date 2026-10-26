// Sign-in pages (UX_DESIGN.md § 3), from the approved design
// docs/design/templates/assets/auth.js. Plain JS: the forms work without it.
import { Passkeys } from '@laravel/passkeys';

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];

const busy = (btn, on) => {
  if (!btn) return;
  if (on) btn.setAttribute('aria-busy', 'true'); else btn.removeAttribute('aria-busy');
  const spinner = $('.spinner', btn);
  if (spinner) spinner.hidden = !on;
};

/* ---------- Show / hide password and the Caps Lock warning ---------- */
$$('[data-eye]').forEach((eye) => {
  const pw = document.getElementById(eye.getAttribute('aria-controls'));
  eye.addEventListener('click', () => {
    const show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    eye.setAttribute('aria-pressed', String(show));
    eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    $('[data-eye-open]', eye).hidden = show;
    $('[data-eye-closed]', eye).hidden = !show;
  });
  const caps = $('[data-caps]');
  if (!caps) return;
  const check = (e) => { if (e.getModifierState) caps.hidden = !e.getModifierState('CapsLock'); };
  pw.addEventListener('keydown', check);
  pw.addEventListener('keyup', check);
  pw.addEventListener('blur', () => { caps.hidden = true; });
});

/* ---------- Loading state on submit (the form posts normally) ---------- */
$$('form[data-busy-form]').forEach((form) => {
  form.addEventListener('submit', () => busy($('[type="submit"]', form), true));
});

/* ---------- Cloudflare Turnstile: the button waits for the check ---------- */
const turnstile = $('[data-turnstile]');
if (turnstile) {
  const submit = $('[data-submit]');
  if (submit) submit.disabled = true;
  window.gpTurnstileDone = () => { if (submit) submit.disabled = false; };
  window.gpTurnstileExpired = () => { if (submit) submit.disabled = true; };
}

/* ---------- Passkeys: button and the browser's autofill in the field ---------- */
const passkeyBlock = $('[data-passkey-block]');
if (passkeyBlock && Passkeys.isSupported()) {
  const routes = { options: passkeyBlock.dataset.options, submit: passkeyBlock.dataset.submit };
  const button = $('[data-passkey]', passkeyBlock);
  const label = $('[data-passkey-label]', passkeyBlock);
  const idle = label.textContent;
  const error = $('[data-passkey-error]');
  const go = (response) => { window.location.assign(response?.redirect || passkeyBlock.dataset.home); };

  passkeyBlock.hidden = false;
  button.addEventListener('click', async () => {
    Passkeys.cancel();
    button.setAttribute('aria-busy', 'true');
    label.textContent = 'Waiting for your device…';
    if (error) error.hidden = true;
    try {
      go(await Passkeys.verify({ routes }));
    } catch (e) {
      if (e?.constructor?.name !== 'UserCancelledError' && error) {
        error.hidden = false;
        $('[data-passkey-error-text]', error).textContent = "That passkey didn't work. Try again, or use your password.";
      }
    } finally {
      button.removeAttribute('aria-busy');
      label.textContent = idle;
    }
  });

  // Autofill needs a field marked autocomplete="… webauthn" (the sign-in page).
  Passkeys.isAutofillSupported().then((ok) => {
    if (!ok || !$('input[autocomplete~="webauthn"]')) return;
    Passkeys.autofill({ routes }).then((response) => { if (response) go(response); }).catch(() => {});
  });
}

/* ---------- 2FA: six boxes, auto-advance, paste, auto-submit ---------- */
const codeForm = $('[data-code-form]');
const otp = $('[data-otp]');
if (codeForm && otp) {
  const field = $('[data-code]', codeForm);
  const boxes = $$('input', otp);
  field.hidden = true;
  field.removeAttribute('autofocus');
  otp.hidden = false;
  if (!codeForm.hidden) boxes[0].focus();

  const sync = () => { field.value = boxes.map((b) => b.value).join(''); };
  const fill = (digits, from = 0) => {
    digits.split('').forEach((d, i) => { if (boxes[from + i]) boxes[from + i].value = d; });
    sync();
    (boxes.find((b) => !b.value) || boxes[5]).focus();
    if (boxes.every((b) => b.value)) codeForm.requestSubmit();
  };
  boxes.forEach((b, i) => {
    b.addEventListener('input', () => {
      const v = b.value.replace(/\D/g, '');
      b.value = '';
      if (v) fill(v.slice(0, 6 - i), i); else sync();
      otp.removeAttribute('aria-invalid');
    });
    b.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !b.value && i > 0) { boxes[i - 1].value = ''; boxes[i - 1].focus(); sync(); e.preventDefault(); }
      if (e.key === 'ArrowLeft' && i > 0) boxes[i - 1].focus();
      if (e.key === 'ArrowRight' && i < 5) boxes[i + 1].focus();
    });
    b.addEventListener('paste', (e) => {
      const v = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
      if (v) { e.preventDefault(); boxes.forEach((x) => { x.value = ''; }); fill(v); }
    });
  });
  codeForm.addEventListener('submit', sync);
}

/* ---------- 2FA: switch between the app code and a recovery code ---------- */
const toggle = $('[data-toggle-recovery]');
const recoveryForm = $('[data-recovery-form]');
if (toggle && codeForm && recoveryForm) {
  toggle.hidden = false;
  toggle.addEventListener('click', () => {
    const recovery = recoveryForm.hidden;
    recoveryForm.hidden = !recovery;
    codeForm.hidden = recovery;
    $('[data-sub-code]').hidden = recovery;
    $('[data-sub-recovery]').hidden = !recovery;
    const error = $('[data-error]');
    if (error) error.hidden = true;
    $('[data-toggle-label]', toggle).textContent = recovery ? 'Use my authenticator app' : 'Use a recovery code';
    (recovery ? $('#recovery') : $('[data-otp] input'))?.focus();
  });
}
