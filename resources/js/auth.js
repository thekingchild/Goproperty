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
      label.textContent = 'Sign in with a passkey';
    }
  });

  Passkeys.isAutofillSupported().then((ok) => {
    if (!ok) return;
    Passkeys.autofill({ routes }).then((response) => { if (response) go(response); }).catch(() => {});
  });
}
