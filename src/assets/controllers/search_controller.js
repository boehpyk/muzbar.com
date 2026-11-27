/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * The search page, enhanced by re-requesting itself (search-ui, ADR-0028).
 *
 * The whole state of a search is its URL, and the server already renders the full answer for any
 * URL. So an applied change requests exactly the URL the JS-free form or link would have navigated
 * to, swaps the response's `<main>` into the page and pushes the response's own `data-search-url`
 * (the surviving query, never what was typed). One route, one handler, one limiter token: the
 * server cannot tell the two paths apart and must never be taught to.
 *
 * Request policy (the client half of ADR-0026's rate contract): checkbox/select/number changes are
 * debounced; the text box searches on submit only; nothing ever listens to keystrokes; a newer
 * change aborts the request in flight, whose response is then never applied.
 */
const DEBOUNCE_MS = 400;

export default class extends Controller {
    static targets = ['status'];

    disconnect() {
        clearTimeout(this.timer);
        this.request?.abort();
    }

    /** A checkbox, select or number field committed a value: update after a quiet 400 ms. */
    change(event) {
        const form = event.target.form;
        // The text box searches on submit only (Enter, "Apply filters"), never when it loses focus.
        if (!form?.matches('form.search-facets') || 'search-q' === event.target.id) {
            return;
        }

        this.supersede();
        this.timer = setTimeout(() => this.update(this.urlOf(form), 'form'), DEBOUNCE_MS);
    }

    /** Enter in a field or "Apply filters": update now. */
    submit(event) {
        if (!event.target.matches('form.search-facets')) {
            return;
        }

        event.preventDefault();
        this.update(this.urlOf(event.target), 'form');
    }

    /** A plain left click on a /browse link inside <main>: update in place (the overlay's guard list). */
    follow(event) {
        const link = event.target instanceof Element ? event.target.closest('main.search-browse a[href]') : null;
        if (null === link || event.defaultPrevented || 0 !== event.button
            || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        const url = new URL(link.href);
        // A hash link ("Skip to results") is the browser's; so is anything that is not this page.
        if (url.origin !== location.origin || '/browse' !== url.pathname || '' !== url.hash) {
            return;
        }

        event.preventDefault();
        this.update(url.href, 'link');
    }

    urlOf(form) {
        const url = new URL(form.action);
        url.search = new URLSearchParams(new FormData(form)).toString();

        return url.href;
    }

    /** Cancels a pending debounce and the request in flight: their answer is already stale. */
    supersede() {
        clearTimeout(this.timer);
        this.request?.abort();
        this.main()?.setAttribute('aria-busy', 'true');
    }

    main() {
        return this.element.querySelector('main.search-browse');
    }

    async update(url, cause) {
        this.supersede();
        const request = new AbortController();
        this.request = request;

        let next;
        let title;
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'text/html' },
                signal: request.signal,
            });
            const html = 200 === response.status ? await response.text() : '';
            const page = new DOMParser().parseFromString(html, 'text/html');
            next = page.querySelector('main.search-browse');
            title = page.title;
        } catch (error) {
            if ('AbortError' === error.name) {
                return;
            }
        }

        if (request.signal.aborted) {
            return;
        }

        if (!next) {
            location.assign(url);

            return;
        }

        this.main().replaceWith(next);
        document.title = title;
        history.pushState({ search: true }, '', next.dataset.searchUrl);
        this.announce(next);
    }

    /** The status line sits outside <main>, so it survives the swap and screen readers hear it change. */
    announce(main) {
        this.statusTarget.textContent = [main.querySelector('.search-total'), main.querySelector('.search-similar')]
            .filter((element) => null !== element)
            .map((element) => element.textContent.trim())
            .join(' ');
    }
}
