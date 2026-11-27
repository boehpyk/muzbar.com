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
 *
 * Failure (the overlay's rule, ADR-0015 — a click never does nothing): a 429 stays and says so,
 * because navigating would be a 429 too and lose the visitor's selection; anything else navigates
 * to the same URL, so the visitor sees what the JS-free path shows.
 */
const DEBOUNCE_MS = 400;

export default class extends Controller {
    static targets = ['status'];

    /** Marks the entry this page was loaded into as ours, so Back to it re-renders it (D5). */
    connect() {
        history.replaceState({ search: true }, '');
    }

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

    /** Back/Forward to an entry this controller wrote; any other entry (a hash link) is the browser's. */
    restore(event) {
        if (!event.state?.search) {
            return;
        }

        this.update(location.href, 'history');
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

    /** @param {'form'|'link'|'history'} cause */
    async update(url, cause) {
        this.supersede();
        const request = new AbortController();
        this.request = request;
        const typedFrom = this.fieldValues();

        let response = null;
        let page = null;
        try {
            response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'text/html' },
                signal: request.signal,
            });
            if (200 === response.status) {
                page = new DOMParser().parseFromString(await response.text(), 'text/html');
            }
        } catch (error) {
            if ('AbortError' === error.name) {
                return;
            }
        }

        if (request.signal.aborted) {
            return;
        }

        if (429 === response?.status) {
            this.main().removeAttribute('aria-busy');
            const seconds = Math.max(1, Number.parseInt(response.headers.get('Retry-After') ?? '', 10) || 1);
            this.statusTarget.textContent = `Too many searches from this address. Try again in ${seconds} seconds.`;

            return;
        }

        const next = page?.querySelector('main.search-browse');
        if (!next) {
            location.assign(url);

            return;
        }

        const focused = this.focused();
        this.main().replaceWith(next);
        document.title = page.title;
        if ('history' !== cause) {
            history.pushState({ search: true }, '', next.dataset.searchUrl);
        }
        this.announce(next);

        if ('form' === cause) {
            this.refocus(focused, typedFrom);
        } else {
            this.heading()?.focus({ preventScroll: true });
            if ('link' === cause) {
                next.scrollIntoView({ block: 'start' });
            }
        }
    }

    heading() {
        return document.getElementById('search-heading');
    }

    /** The values of the fields a visitor can type into, keyed by id, when the request started. */
    fieldValues() {
        const fields = this.main()?.querySelectorAll('input[type="search"], input[type="number"]') ?? [];

        return new Map([...fields].map((field) => [field.id, field.value]));
    }

    /** The control inside <main> that has focus, as it is right before the swap. */
    focused() {
        const element = document.activeElement;
        if (!element?.id || !this.main()?.contains(element)) {
            return null;
        }

        return { id: element.id, value: element.value, start: element.selectionStart ?? null, end: element.selectionEnd ?? null };
    }

    /**
     * Swapping <main> re-creates every control, so focus goes back to the control with the same id
     * (ids are built from meaning, AC-5) — or to the heading when that control left with its facet.
     * Text typed while the request was in flight is carried over, caret and all.
     */
    refocus(focused, typedFrom) {
        if (null === focused) {
            return;
        }

        const target = document.getElementById(focused.id);
        if (null === target) {
            this.heading()?.focus({ preventScroll: true });

            return;
        }

        if (typedFrom.has(focused.id) && typedFrom.get(focused.id) !== focused.value) {
            target.value = focused.value;
            if (null !== focused.start) {
                target.setSelectionRange(focused.start, focused.end);
            }
        }
        target.focus({ preventScroll: true });
    }

    /** The status line sits outside <main>, so it survives the swap and screen readers hear it change. */
    announce(main) {
        this.statusTarget.textContent = [main.querySelector('.search-total'), main.querySelector('.search-similar')]
            .filter((element) => null !== element)
            .map((element) => element.textContent.trim())
            .join('. ');
    }
}
