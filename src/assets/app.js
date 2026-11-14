import './styles/app.css';

/*
 * The login overlay (ADR-0015): `/login`'s auth panel shown in a native modal <dialog>.
 *
 * A trigger is any `a[data-auth-overlay]` whose href is `/login?_target_path=<intended action>`.
 * Without JS it is an ordinary link to the full login page; here it fetches that same URL — which
 * is also what stashes the target in the session — lifts `[data-auth-panel]` out of the response
 * and shows it. The panel's forms do ordinary full-page submits through the firewall, so nothing
 * about CSRF, throttling or error messages differs from the full page. Plain JS, no library: the
 * dialog gives focus handling, Esc and an inert background for free.
 *
 * Any failure navigates to the href instead, so a click never does nothing.
 *
 * Caveat: the fetched page is rendered with the layout, which pops flash messages, and the layout
 * is then discarded. Harmless today — every flash is displayed by the page a redirect lands on,
 * before a trigger can be clicked — but a flash set by some future background request would vanish
 * here unseen.
 */
let dialog = null;

function authDialog() {
    if (null === dialog) {
        dialog = document.createElement('dialog');
        dialog.setAttribute('aria-labelledby', 'auth-panel-title');

        const close = document.createElement('form');
        close.method = 'dialog';
        const button = document.createElement('button');
        button.textContent = 'Close';
        close.append(button);

        dialog.append(close);
        document.body.append(dialog);
    }

    return dialog;
}

document.addEventListener('click', async (event) => {
    const link = event.target instanceof Element ? event.target.closest('a[data-auth-overlay]') : null;

    // Leave new-tab, new-window and download clicks to the browser: they are ordinary links.
    if (null === link || event.defaultPrevented || 0 !== event.button
        || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
        return;
    }

    event.preventDefault();

    try {
        // Re-fetched on every open, so the CSRF token and the stashed target are always fresh.
        const response = await fetch(link.href, { credentials: 'same-origin' });
        const html = response.ok ? await response.text() : '';
        const panel = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-auth-panel]');

        if (null === panel) {
            location.assign(link.href);

            return;
        }

        const target = authDialog();
        target.querySelector('[data-auth-panel]')?.remove();
        target.append(document.importNode(panel, true));
        target.showModal();
    } catch {
        location.assign(link.href);
    }
});
