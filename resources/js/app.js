/**
 * Keeps every tab in this browser agreeing about who is signed in.
 *
 * The problem: a session lives in a cookie, and every tab sends it. Sign in as a
 * CUSTOMER in Tab A, then open Tab B and sign in as ADMIN — Tab A is still
 * rendering a customer dashboard, with working links, until it happens to
 * navigate. Nothing in the page is wrong; the page is just out of date.
 *
 * The fix is two halves, and this is the client half. The server half is in the
 * two session controllers, which log the other guard out on login so only one
 * role is ever active. This half tells the *other tabs* about it:
 *
 *   - every rendered page states its own identity in a `bta-session` meta tag
 *     (`App\Support\SessionIdentity` writes it);
 *   - this page writes that identity to `localStorage`; and
 *   - the `storage` event fires in every *other* tab when one of them writes, so
 *     a tab whose rendered identity no longer matches what it hears knows the
 *     browser has moved on.
 *
 * `localStorage` rather than a cookie because `storage` events are the point:
 * a cookie is invisible to JavaScript in other tabs, which is the whole problem
 * being solved.
 *
 * Deliberately a label, never a token. What is stored is `admin:3` or `web:12`
 * or `guest` — enough to notice a change, useless to anyone forging a session.
 *
 * ---------------------------------------------------------------------------
 * The one rule this module is built around
 * ---------------------------------------------------------------------------
 *
 * `localStorage` is a noticeboard, not an oracle. The `bta-session` meta tag is
 * the only authority on *this* page, because the server rendered it from the
 * session cookie as it stands right now. So on load this module always writes
 * its own identity and never navigates.
 *
 * An earlier version had it the other way round: on load it read the stored
 * value, and if that disagreed with the page it treated the *stored* value as
 * the newer truth, navigated to it, and returned before writing anything back.
 * That is a loop with no exit:
 *
 *   - a tab that has just logged out renders `guest`, finds `web:13` stored, and
 *     is dragged straight back to `/appointments` — so logging out does not appear
 *     to work, and the page flickers;
 *   - `/appointments` then redirects to `/login`, which renders `guest`, finds
 *     `web:13` stored again, and is dragged back to `/appointments`;
 *   - and because the early return also skipped attaching the `storage`
 *     listener, nothing ever repaired the stale value or heard about a real
 *     change afterwards.
 *
 * Announcing unconditionally ends it: every page load writes the current truth,
 * so a page always agrees with the noticeboard by the time anything reads it.
 *
 * Navigation is then driven only by *another tab* — the `storage` event, or a
 * catch-up comparison when this tab is looked at again — and is budgeted to one
 * hop per identity, so no pair of pages can bounce a tab between them.
 */
(function sessionSync() {
    const STORAGE_KEY = 'bta:session';

    /**
     * Per-tab record of the identity we last navigated for.
     *
     * `sessionStorage` rather than `localStorage` because it has to be something
     * another tab cannot reach: this is one tab's own "I have already done this"
     * note, and a shared key would let a sibling tab spend this tab's budget.
     */
    const ATTEMPT_KEY = 'bta:session-sync';

    /** The guards this application has a home and a login page for. */
    const GUARDS = ['admin', 'web'];

    const meta = (name, fallback) =>
        document.querySelector(`meta[name="${name}"]`)?.content || fallback;

    /**
     * Where to send a tab when the browser's identity is not what it rendered.
     *
     * Read from meta tags rather than hardcoded, so the routing stays in
     * routes/web.php and the layouts can each contribute their own home. The
     * fallbacks are the customer side's landing page — the Dashboard that used to
     * be the customer home no longer exists.
     */
    const destinations = {
        admin: meta('bta-home-admin', '/admin'),
        web: meta('bta-home-web', '/'),
    };

    /** The login page to send a signed-out tab to, per guard. */
    const logins = {
        admin: meta('bta-login-admin', '/login'),
        web: meta('bta-login-web', '/login'),
    };

    /**
     * The liveness probe.
     *
     * From a meta tag rather than a literal, because this application is served
     * from a subdirectory (`htdocs/BTA/public`), where a root-absolute
     * `/session-status` is a 404 and the check silently stops working.
     */
    const statusEndpoint = meta('bta-session-status', '/session-status');

    const readMeta = () => meta('bta-session', 'guest');

    const parse = (value) => {
        const [guard, id] = String(value || 'guest').split(':');
        return { guard: guard || 'guest', id: id || null };
    };

    /**
     * Whether this module can act on an announced value at all.
     *
     * Anything else — a corrupted entry, a hand-edited one, or one left by
     * another application sharing this origin — has no destination, and guessing
     * one used to mean reloading the current URL, which reloads it again, for as
     * long as the browser keeps the tab open.
     */
    const isActionable = (value) => {
        const { guard } = parse(value);
        return guard === 'guest' || GUARDS.includes(guard);
    };

    const attempts = {
        read() {
            try {
                return window.sessionStorage.getItem(ATTEMPT_KEY);
            } catch {
                return null;
            }
        },
        write(value) {
            try {
                if (value === null) {
                    window.sessionStorage.removeItem(ATTEMPT_KEY);
                } else {
                    window.sessionStorage.setItem(ATTEMPT_KEY, value);
                }
            } catch {
                // Storage disabled. The loop this guards against needs a working
                // localStorage to get started, so there is nothing to guard.
            }
        },
    };

    function announce(identity) {
        try {
            // Only write on a real change: setting a key to the value it already
            // holds fires no event, but skipping the write outright also saves
            // every sibling tab a pointless wake-up on every single page load.
            if (window.localStorage.getItem(STORAGE_KEY) === identity) return;

            window.localStorage.setItem(STORAGE_KEY, identity);
        } catch {
            // Private browsing, a full quota, or storage disabled outright. The
            // page still works and the other tabs simply will not be told — a
            // degraded sync, not a broken one, so there is nothing to do but
            // carry on.
        }
    }

    /**
     * Send this tab to where an announced identity actually lives.
     *
     * `announced` is a full identity (`admin:3`) when it came from another tab,
     * or a bare guard (`admin`) when it came from the server, which reports the
     * guard without an id — `guardOnly` is how those two are told apart.
     *
     * Returns the URL navigated to, or null when the tab stayed put.
     */
    function goTo(announced, reason, { guardOnly = false } = {}) {
        const mine = readMeta();
        const theirs = parse(announced);
        const current = parse(mine);

        const unchanged = guardOnly
            ? theirs.guard === current.guard
            : theirs.guard === current.guard && theirs.id === current.id;

        if (unchanged) return null;

        // Nothing recognisable to follow, or nowhere to go. Repair the noticeboard
        // and stay on this page.
        if (!isActionable(announced)) {
            announce(mine);
            return null;
        }

        const target = theirs.guard === 'guest'
            ? logins[current.guard] || logins.web
            : destinations[theirs.guard];

        if (!target) {
            announce(mine);
            return null;
        }

        /*
         * One hop per identity per tab.
         *
         * Every redirect target here is itself a page that publishes an identity,
         * so the second hop is the one that can go wrong — the destination
         * redirects back here, this page hears about a change it already
         * followed, and so on. Refusing to spend the budget twice is what turns
         * "might loop" into "cannot".
         */
        if (attempts.read() === announced) return null;

        attempts.write(announced);

        window.location.replace(target);

        return target;
    }

    /**
     * Ask the server who this browser is signed in as, for the one case the
     * `storage` event cannot cover.
     *
     * `pageshow` with `persisted: true` means the browser put this document back
     * from its back/forward cache — the page on screen was never re-requested, so
     * it may still be rendering an authenticated page from before a logout. A
     * `storage` event may or may not reach a bfcache-restored page depending on
     * the browser, so the only dependable answer is to ask.
     *
     * Deliberately a `fetch` of one tiny JSON route rather than a reload: a
     * reload would be correct but throws away the page and shows a flash, whereas
     * the thing being checked for is rare enough that one small request costs
     * nothing and leaves an ordinary Back press completely untouched.
     *
     * Returns `admin`, `web`, `guest`, or null for "no opinion" — offline, the
     * server restarting, or an answer that is not the shape expected. Null is
     * always treated as "leave the page alone".
     */
    async function askServer() {
        try {
            const response = await fetch(statusEndpoint, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) return null;

            const payload = await response.json();

            return payload.authenticated === true ? String(payload.guard) : 'guest';
        } catch {
            return null;
        }
    }

    /**
     * Re-check a page restored from the back/forward cache.
     *
     * A no-op for an ordinary navigation (`persisted` is false) — which is the
     * overwhelming majority, so the common case costs one property read and
     * nothing else.
     */
    function watchRestoredFromCache() {
        window.addEventListener('pageshow', async (event) => {
            if (!event.persisted) return;

            const current = parse(readMeta());

            // A guest page that comes back from the cache is nobody's business.
            if (current.guard === 'guest') return;

            const reported = await askServer();

            if (reported === null) return;

            // The probe reports a guard, not an id, so the two are compared on
            // the guard alone. Comparing the whole identity would report a
            // difference on every single restore — `admin` against `admin:3` — and
            // bounce the tab on every Back press.
            goTo(reported, 'bfcache', { guardOnly: true });
        });
    }

    /**
     * Catch up with a change this tab never heard about.
     *
     * A backgrounded tab can miss the `storage` event outright — browsers are
     * free to freeze one, and a frozen tab is handed nothing. The stored value is
     * still there, though, so when the tab is looked at again one comparison is
     * enough to tell whether it is showing something stale.
     *
     * This is also what covers the tab that *did* hear the event: the browser may
     * have queued it for a tab that was never resumed, in which case the event is
     * simply not delivered and this is the only thing left.
     */
    function catchUp() {
        let stored = null;

        try {
            stored = window.localStorage.getItem(STORAGE_KEY);
        } catch {
            return;
        }

        if (!stored) return;

        goTo(stored, 'catch-up');
    }

    function watchForReturn() {
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) catchUp();
        });
    }

    function start() {
        const mine = readMeta();

        // Announce unconditionally, and never navigate on load. See the note at
        // the top of this file: this single line is what stops the reload loop.
        announce(mine);

        // The identity we navigated for is the one this page turned out to be,
        // which means the navigation landed and the budget can be refilled.
        if (attempts.read() === mine) {
            attempts.write(null);
        }

        window.addEventListener('storage', (event) => {
            if (event.key !== STORAGE_KEY) return;

            // A null `newValue` means another tab cleared the key. There is no
            // identity in that, so there is nothing to react to — and treating it
            // as "signed out" would sign every tab out on a `localStorage.clear()`.
            if (!event.newValue) return;

            // `storage` fires only in *other* tabs, so this can never be this
            // tab reacting to its own login or logout. That is the whole reason
            // this is the trigger rather than a poll.
            goTo(event.newValue, 'storage');
        });

        watchForReturn();
        watchRestoredFromCache();
    }

    // Vite emits a module script, which is deferred, so the meta tags above have
    // been parsed by the time this runs. The readyState check is here anyway:
    // a cached module can execute after `DOMContentLoaded`, and a listener that
    // never fires would silently disable the whole mechanism.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();


import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

/**
 * Password visibility toggle used by the auth + profile forms.
 *
 * Markup contract — the button is the only thing that carries attributes:
 *
 *   <input id="password" type="password" />
 *   <button type="button" data-password-toggle-for="password">Show</button>
 *
 * Adding `data-password-toggle-icon` switches it to the icon variant, which holds
 * two SVGs and swaps which one is visible through an `is-revealed` class on the
 * button rather than by rewriting markup.
 *
 * The bug this comment exists for: the selector used to be
 * `[data-password-toggle]`, and no button in the app carries that attribute — the
 * component has always rendered `data-password-toggle-for`. So `querySelectorAll`
 * returned an empty set, no listener was ever attached, and the eye did nothing
 * on the admin login, the customer login, or either profile screen. The selector
 * and the attribute are the same string now, and they are both this one.
 *
 * Kept as a tiny vanilla helper (rather than Alpine) so it works identically on
 * pages that do not initialise Alpine, e.g. the standalone admin login screen.
 *
 * Re-run on `alpine:initialized` as well as on DOMContentLoaded, because a field
 * rendered inside an Alpine template arrives after the first pass and would
 * otherwise never be wired.
 */
function initPasswordToggles(root = document) {
    root.querySelectorAll('[data-password-toggle-for]').forEach((button) => {
        if (button.dataset.passwordToggleBound === '1') return;
        button.dataset.passwordToggleBound = '1';

        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.passwordToggleFor);

            if (!input) return;

            const revealed = input.type === 'text';

            input.type = revealed ? 'password' : 'text';

            // Two toggle presentations. The default button carries a text label
            // and swaps it. The icon variant holds SVGs, so assigning
            // textContent would destroy them — that one gets a state class
            // instead, which the stylesheet uses to show the other eye mark and
            // recolour it.
            if ('passwordToggleIcon' in button.dataset) {
                button.classList.toggle('is-revealed', !revealed);
            } else {
                button.textContent = revealed ? 'Show' : 'Hide';
            }

            // `aria-pressed` is the accessible half: with the icon variant there is
            // no visible label to change, so this is the only thing a screen reader
            // has to announce the new state through.
            button.setAttribute('aria-pressed', String(!revealed));
        });
    });
}

document.addEventListener('DOMContentLoaded', () => initPasswordToggles());

// A field rendered by an Alpine template — the profile dialogs — is added to the
// document after DOMContentLoaded. `alpine:initialized` fires once Alpine has
// walked the tree, which is early enough to catch static markup and late enough
// to catch most templated content.
document.addEventListener('alpine:initialized', () => initPasswordToggles());

/**
 * Owns `document.title` for the whole page, so the unread prefix and the
 * section-anchor retitle cannot fight over it.
 *
 * Facebook and Messenger prefix the tab title with the unread count — "(3)
 * Facebook" — because the title is the one thing a user reads when deciding
 * whether to switch to a background tab. This is the same behaviour, driven by
 * the same poll that drives the bell badge rather than by a second request, so
 * the number in the tab can never disagree with the number on the bell.
 *
 * Three writers want to set the title and only one of them may:
 *
 *   - the server, which renders the prefix into the `<title>` so it is right
 *     before the bundle loads;
 *   - the section anchors, which retitle on a `/#offers`-style jump; and
 *   - the poller, which adds or removes the prefix as the count changes.
 *
 * So the *base* title (the page's own, unprefixed) is the state this holds, and
 * the prefix is recomputed from it on every change. Two consequences worth
 * stating: a retitle from an anchor does not drop the count, and the count does
 * not accumulate a second `(3)` in front of an existing one.
 *
 * The prefix is stripped off whatever it is handed, because the title it starts
 * from was server-rendered *with* a prefix.
 */
const tabTitle = {
    base: document.title.replace(/^\(\d+\)\s*/, ''),
    count: 0,

    /** The page's own title, with any existing count prefix removed. */
    setBase(title) {
        this.base = String(title || '').replace(/^\(\d+\)\s*/, '');
        this.render();
    },

    /** 0 removes the prefix, which is why "no unread" is a value and not a no-op. */
    setCount(count) {
        const next = Number(count);

        this.count = Number.isFinite(next) && next > 0 ? Math.min(next, 99) : 0;

        this.render();
    },

    render() {
        document.title = this.count > 0 ? `(${this.count}) ${this.base}` : this.base;
    },
};

// Read by both the anchors below and the bells, which are defined later in this
// file. Assigned rather than imported because they are Alpine components closing
// over module scope, and a `window` hop is what lets a component reach it.
window.btaTabTitle = tabTitle;

/**
 * The customer's unread count, as one number everybody agrees on.
 *
 * Three things show it: the badge on the navbar bell, the badge on the mobile nav
 * row, and the "(2)" in the tab title. They used to be updated by whoever
 * happened to notice — the poller wrote the bell and the title, and marking
 * something read from the notifications page updated neither, because the badge
 * lived in the header and the click happened twenty lines further down the page.
 * The badge and the title would then disagree, with nothing on screen to say
 * which was lying.
 *
 * So the count is stored once, here, and everything reads from it. A subscriber
 * can be any number of components — the desktop bell and the mobile row are two —
 * and each is told the new value rather than polling this.
 *
 * Contract:
 *   window.btaUnread.seed(n)   // server truth, on load
 *   window.btaUnread.set(n)    // a change, from a poll or a write
 *   window.btaUnread.get()
 *   window.btaUnread.subscribe(fn) -> unsubscribe
 *
 * `set()` is a no-op when the number has not moved, so an optimistic update that
 * happens to match the server's answer does not re-render the title twice.
 */
window.btaUnread = (function () {
    let count = 0;
    const subscribers = new Set();

    function render() {
        // The tab title is derived from the same number as the badge, in the same
        // call, for the same reason the bells are: a title that counted
        // separately could disagree with the badge beside it.
        window.btaTabTitle?.setCount(count);

        subscribers.forEach((notify) => {
            try {
                notify(count);
            } catch {
                // One broken subscriber must not stop the others updating.
            }
        });
    }

    function normalise(value) {
        const next = Number(value);

        return Number.isFinite(next) && next > 0 ? Math.min(Math.floor(next), 99) : 0;
    }

    return {
        /** Server truth. Applied even when it matches, so a fresh page resyncs. */
        seed(value) {
            count = normalise(value);
            render();
        },

        set(value) {
            const next = normalise(value);

            if (next === count) return;

            count = next;
            render();
        },

        get() {
            return count;
        },

        subscribe(listener) {
            subscribers.add(listener);
            listener(count);

            return () => subscribers.delete(listener);
        },
    };
})();

/**
 * The admin panel's unread count, on the same terms as `window.btaUnread`.
 *
 * A separate store rather than a shared one because the two are never both live —
 * a customer session and an admin session cannot occupy the same tab — and
 * because the two counts mean different things. The customer's is "unread
 * notifications"; this one is the badge on the admin's one badged work row:
 * bookings the salon has not looked at yet.
 *
 * It deliberately no longer includes unread contact enquiries. The Messages row
 * is not in the admin nav, so those enquiries have no visible notification
 * surface; counting them made the tab title show "(2)" with nothing on screen to
 * explain or clear it. The Messages screen still keeps its own unread pill live
 * through `admin-messages`; it just does not feed this title count.
 *
 * Same contract as the customer's, for the same reason: the tab title and the
 * sidebar's Appointments count via the `admin-pending` event should agree, and
 * when they each kept their own copy they disagreed with nothing on screen to say
 * which was lying.
 */
window.btaAdminUnread = (function () {
    let count = 0;
    const subscribers = new Set();

    function render() {
        window.btaTabTitle?.setCount(count);

        subscribers.forEach((notify) => {
            try {
                notify(count);
            } catch {
                // One broken subscriber must not stop the others updating.
            }
        });
    }

    function normalise(value) {
        const next = Number(value);

        return Number.isFinite(next) && next > 0 ? Math.min(Math.floor(next), 99) : 0;
    }

    return {
        /** Server truth on load, so the tab title is right before any polling. */
        seed(value) {
            count = normalise(value);
            render();
        },

        set(value) {
            const next = normalise(value);

            if (next === count) return;

            count = next;
            render();
        },

        /**
         * Nudge by a delta, for the optimistic step in a mark-as-read.
         *
         * Distinct from `set` because an optimistic change is additive — "one
         * fewer" — and doing that as `get() - 1` at each call site is where an
         * off-by-one gets introduced. `normalise` clamps at zero, so marking the
         * last one read when the count already reads zero settles at zero rather
         * than going negative and rendering "(-1)".
         */
        decrement(by = 1) {
            this.set(Math.max(0, count - Number(by || 0)));
        },

        get() {
            return count;
        },

        subscribe(listener) {
            subscribers.add(listener);
            listener(count);

            return () => subscribers.delete(listener);
        },
    };
})();

/**
 * Retitles the browser tab when the visitor jumps to an in-page section.
 *
 * A link like `/#offers` does not reload the document, so the server-rendered
 * <title> would stay "HOME". Markup contract:
 *   <a href="/#offers" data-tab-title="PROMO | Balai ti Arjud">Promo</a>
 *   <section id="offers" data-tab-title="PROMO | Balai ti Arjud">
 *
 * The section carries the attribute as well, but it may not be rendered at all
 * (the offers block is conditional on an active promo existing), so each link's
 * claim is remembered for its own hash and used as the fallback. Otherwise the
 * click would set the title and the following hash change would immediately
 * reset it back to the page title.
 */
function initSectionTabTitles(root = document) {
    // The page's own title, with any server-rendered count prefix taken off. Read
    // once, at load, for the same reason `tabTitle` strips it: `document.title`
    // reads "(3) HOME | Balai ti Arjud" once the prefix is live, and capturing
    // that here would bake the count into every section title — so following a
    // `/#offers` link would leave the tab reading "(3) (3) PROMO".
    const pageTitle = tabTitle.base;
    const titlesByHash = new Map();

    const titleFor = (hash) => {
        if (!hash || hash === '#') return pageTitle;

        try {
            const claimed = document.querySelector(hash)?.dataset.tabTitle;
            if (claimed) return claimed;
        } catch {
            // A malformed hash is just a normal anchor.
        }

        return titlesByHash.get(hash) || pageTitle;
    };

    root.querySelectorAll('a[data-tab-title]').forEach((link) => {
        const title = link.dataset.tabTitle;

        // Recorded on every pass, not just the first, so a re-init (Alpine
        // re-render, a soft navigation) cannot leave the fallback map empty.
        if (link.hash) titlesByHash.set(link.hash, title);

        if (link.dataset.tabTitleBound === '1') return;
        link.dataset.tabTitleBound = '1';

        // Through `tabTitle` rather than `document.title`, so switching section
        // changes the page the prefix sits on and leaves the count alone.
        link.addEventListener('click', () => {
            tabTitle.setBase(title);
        });
    });

    window.addEventListener('hashchange', () => {
        tabTitle.setBase(titleFor(window.location.hash));
    });

    tabTitle.setBase(titleFor(window.location.hash));
}

document.addEventListener('DOMContentLoaded', () => initSectionTabTitles());

document.addEventListener('alpine:init', () => {
    Alpine.data('dropdown', () => ({
        open: false,
        toggle() {
            this.open = !this.open;
        },
        close() {
            this.open = false;
        },
    }));

    /**
     * The admin catalogue tables: Services, Categories and Promo.
     *
     * Search is live and paging is client-side, mirroring the reference admin,
     * so the whole row set is rendered once and this component decides what is
     * on screen. Markup contract:
     *   <div x-data="adminTable(@js(['search' => $search]))">
     *       <input type="search" x-model="search" />
     *       <select x-model.number="perPage" />
     *       <tr data-row x-show="isShown({{ $loop->index }})">
     *       <span x-text="total" /> / <template x-for="n in pageNumbers">
     *
     * Row order is the server's: the sort is a real link that reloads, which
     * keeps it deep-linkable and keeps the ordering in one place for every
     * column the database sorts differently from JavaScript would.
     */
    Alpine.data('adminTable', (config = {}) => ({
        search: config.search || '',
        perPage: Number(config.perPage) || 10,
        // Held apart from `perPage` for the option list alone: a screen that
        // starts at a size the standard list does not have should still be able
        // to come back to it after the admin has tried another one.
        initialPerPage: Number(config.perPage) || 10,
        standardPerPageOptions: [10, 15, 25, 50, 100],
        page: 1,
        haystacks: [],
        keep: [],
        onPage: [],
        total: 0,

        /**
         * What the page-size select offers — the standard list plus the sizes
         * this screen has actually been asked for.
         *
         * `x-ui.admin-table` takes a `perPage` prop, and the Reports screen
         * passes 12 (one row per month). Alpine's `<select>` is populated from
         * this array, and a select whose value matches none of its options
         * displays *nothing* while the table underneath keeps paging at the
         * real size — an entries-per-page control that looks dead on arrival
         * and silently disagrees with the list below it.
         *
         * Deriving the list rather than widening the shared one is the point:
         * any screen can name its own page size and the control stays honest
         * without this list and that prop having to be kept in step by hand.
         * The live value is included as well as the initial one so the select
         * can never end up blank, however `perPage` came to be what it is.
         * Sorted so the option order does not depend on where a size came from,
         * and de-duplicated so a screen asking for 25 does not get it twice.
         */
        get perPageOptions() {
            const options = new Set(this.standardPerPageOptions);
            options.add(Number(this.initialPerPage) || 10);
            options.add(Number(this.perPage) || 10);

            return [...options].sort((a, b) => a - b);
        },

        get pages() {
            return Math.max(1, Math.ceil(this.total / this.perPage));
        },

        get hasPages() {
            return this.pages > 1;
        },

        /**
         * A three-wide window around the current page, the same shape the
         * reference pager produces. Recomputed rather than tracked so a click
         * on "3" shifts the window instead of keeping a stale set of buttons.
         */
        get pageNumbers() {
            let start = Math.max(1, this.page - 1);
            const end = Math.min(this.pages, start + 2);
            start = Math.max(1, end - 2);

            return Array.from({ length: end - start + 1 }, (_, i) => start + i);
        },

        get isFirstPage() {
            return this.page <= 1;
        },

        get isLastPage() {
            return this.page >= this.pages;
        },

        init() {
            // Read each row's text once, up front. `isShown()` re-runs on every
            // keystroke, and going back to the DOM for textContent each time is
            // what makes a long catalogue feel laggy.
            this.haystacks = Array.from(this.$root.querySelectorAll('tbody tr[data-row]')).map((row) =>
                (row.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase(),
            );

            this.filter();

            this.$watch('search', () => {
                this.page = 1;
                this.filter();
            });

            this.$watch('perPage', () => {
                this.page = 1;
                this.filter();
            });
        },

        filter() {
            const term = this.search.trim().toLowerCase();

            this.keep = this.haystacks.map((haystack) => term === '' || haystack.includes(term));
            this.total = this.keep.filter(Boolean).length;
            this.page = Math.min(Math.max(1, this.page), this.pages);
            this.pageSlice();
        },

        /**
         * Which of the surviving rows belong on the current page.
         *
         * `keep` alone is only the search filter, so gating rows on it shows every
         * match regardless of `perPage` — the pager buttons would move and the row
         * count would not, which is exactly the symptom of the entries-per-page
         * select appearing to do nothing. Paging is therefore a second, separate
         * decision, and it is computed here rather than inside `isShown()` so a row
         * costs one array read instead of re-walking `keep` on every render.
         *
         * The window walks the *matching* rows in document order, so page 2 starts
         * at the 11th match rather than at row 11 of the table. That is the
         * difference between paging a filtered list and slicing an unfiltered one,
         * and it is why this cannot be a plain index range.
         */
        pageSlice() {
            const start = (this.page - 1) * this.perPage;
            const end = start + this.perPage;

            this.onPage = this.keep.map(() => false);

            let seen = 0;

            for (let index = 0; index < this.keep.length; index++) {
                if (!this.keep[index]) {
                    continue;
                }

                if (seen >= start && seen < end) {
                    this.onPage[index] = true;
                }

                seen++;
            }
        },

        /**
         * Unknown indices answer `true`, so rows are visible before `init()`
         // fills `onPage` and there is no flash of an empty table on load.
         */
        isShown(index) {
            return this.onPage[index] !== false;
        },

        isCurrentPage(page) {
            return page === this.page;
        },

        /**
         * Move to another page — and re-slice the window.
         *
         * The `pageSlice()` call is the whole point of this method. It used to set
         * `this.page` and stop there, so every getter that reads `page` (the pager
         * window, `hasPages`, the current-page highlight) moved while `onPage` —
         * the array the rows actually ask `isShown()` about — was left describing
         * the previous page. Pressing "2" therefore restyled the pager and changed
         * nothing on screen.
         *
         * That is also what made the entries-per-page select look broken. The two
         * controls are the same mechanism seen from two ends: an admin who picked
         * 25 rows per page, clicked through to page 2, saw no change, and concluded
         * the page size had not taken. Every other caller (`$watch` on `perPage`,
         * `$watch` on `search`, `filter()`) already re-sliced; this one did not.
         *
         * Clamping first matters: at the last page a narrower `perPage` can leave
         * `page` past the end, and slicing that page would render an empty table.
         */
        go(page) {
            this.page = Math.min(Math.max(1, page), this.pages);
            this.pageSlice();
        },
    }));

    /**
     * One appointment row's live state: its status dropdown and its row actions.
     *
     * Markup contract:
     *   <tr x-data="appointmentStatus({ id, endpoint, current, label, badge,
     *                                  locked, choices, canArchive, canDelete })"
     *       x-on:admin-appointment-row.window="if ($event.detail.id === id) apply($event.detail)">
     *       <select x-ref="select" @change="update($event.target.value, $event.target)">
     *       <button x-show="canArchive">…</button>
     *
     * The select still sits inside a real PATCH form, so the row works with
     * JavaScript switched off; this component only intercepts `change` to get
     * a refused transition reported in place instead of as a redirect back to
     * a page full of validation errors.
     *
     * It lives on the row rather than on the status cell because the two halves
     * have to agree: Archive and Delete are rendered from the same status as the
     * dropdown, and the moment an admin completes a booking the dropdown greys
     * out and those two buttons appear. A scope per cell would leave the pair to
     * be kept in step by hand; a scope per row is the only place they are the
     * same fact.
     */
    Alpine.data('appointmentStatus', (config = {}) => ({
        id: Number(config.id) || 0,
        endpoint: config.endpoint || '',
        current: config.current || '',
        label: config.label || '',
        badge: config.badge || '',
        locked: Boolean(config.locked),
        choices: Array.isArray(config.choices) ? config.choices : [],
        canArchive: Boolean(config.canArchive),
        canDelete: Boolean(config.canDelete),
        select: null,
        busy: false,

        update(value, select) {
            this.select = select;

            // Re-selecting the option that is already current fires no change
            // event in most browsers, but a keyboard user tabbing back onto it
            // can — and there is nothing to save either way.
            if (this.busy || !this.endpoint || value === this.current) {
                select.value = this.current;
                return;
            }

            this.busy = true;
            select.disabled = true;

            this.send(value).catch(() => {
                this.reset('Could not reach the server. The status was not changed.');
            });
        },

        async send(value) {
            const response = await fetch(this.endpoint, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ status: value }),
            });

            if (!response.ok) {
                // 422 carries the refusal as `message`; anything else is a
                // server fault, which is worth the same treatment.
                const payload = await response.json().catch(() => ({}));

                this.reset(
                    payload.message ||
                        payload.errors?.status?.[0] ||
                        'That status change could not be saved.',
                );

                return;
            }

            const payload = await response.json().catch(() => ({}));

            /*
             * Repaint the row from the server's answer rather than reloading.
             *
             * A full page reload used to be the end of a successful save, and it
             * is why completing a booking did not put Archive and Delete on the
             * row until the page came back: the buttons are rendered from the
             * status, so they only exist in a response built after it changed.
             * A reload is a way of getting that response, at the cost of the page
             * the admin was reading — the filters, the page they were on, and the
             * row they were looking at all thrown away to move two buttons.
             *
             * The save now answers with the row's new state, so the same result
             * costs one repaint. The toast is raised from this payload for the
             * same reason the controller stopped flashing it: nothing reloads to
             * render it, and a flash left in the session would surface on whatever
             * page the admin happened to open next.
             */
            this.apply(payload.row, true);

            if (payload.message) {
                window.btaToast?.show(payload.type || 'success', payload.message);
            }

            // The tab counts are page furniture rather than row furniture, so the
            // page hands them on rather than this component writing into markup it
            // does not own.
            if (payload.counts) {
                window.dispatchEvent(
                    new CustomEvent('admin-appointment-counts', { detail: payload.counts }),
                );
            }
        },

        /**
         * Put the row back the way the server has just described it.
         *
         * Two callers, and it has to be safe to run twice: this tab's own save
         * answers with the new state, and the poller answers with the same state
         * a moment later. Applying it a second time costs a repaint and changes
         * nothing.
         *
         * Every field is taken from the payload rather than worked out here —
         * particularly the menu, which is the server's own list rebuilt into the
         * select rather than filtered client-side. That is what keeps
         * `AppointmentStatus::MENUS` the only copy of the transition rules.
         *
         * `endSave` is the one thing not taken from the payload, because a poll
         * has no save to end. It is cleared before the repaint rather than after,
         * since the repaint reads it: a save that has just landed must not leave
         * its own dropdown greyed out. Leaving it alone the other way round is
         * deliberate — a poll arriving mid-save must not un-disable the control,
         * or a second change could be posted behind the first.
         */
        apply(state, endSave = false) {
            if (!state) return;

            if (state.status) this.current = state.status;
            if (state.label) this.label = state.label;
            if (state.badge) this.badge = state.badge;
            if (Array.isArray(state.choices)) this.choices = state.choices;

            this.locked = state.locked ?? this.locked;
            this.canArchive = Boolean(state.can_archive ?? state.canArchive);
            this.canDelete = Boolean(state.can_delete ?? state.canDelete);

            if (endSave) this.busy = false;

            this.repaint();
        },

        /**
         * Push the state onto the elements that show it.
         *
         * The options are rebuilt rather than filtered in place: a completed
         * row's menu is empty, and a select still offering "Start / In Progress"
         * would be offering a transition the route has already decided to refuse.
         */
        repaint() {
            const select = this.$refs.select ?? this.select;

            if (!select) return;

            select.textContent = '';

            this.choices.forEach((choice) => {
                const option = document.createElement('option');

                option.value = choice.value;
                option.textContent = choice.label;

                // The current status is the first option and is not selectable,
                // exactly as Blade renders it: it is where the row is, not a move.
                if (!choice.selectable) {
                    option.selected = true;
                    option.disabled = true;
                }

                select.appendChild(option);
            });

            select.value = this.current;

            // Left disabled while this row's own save is in flight, so a second
            // change cannot be posted behind the first.
            select.disabled = this.locked || this.busy;

            // The tint belongs to the status and the status was just decided by
            // the server. Written wholesale rather than toggled, so the previous
            // status's tint cannot survive the change.
            select.className = [
                'status-select',
                `status-select-${this.badge}`,
                ...(this.locked ? ['status-select-locked'] : []),
            ].join(' ');
        },

        reset(message) {
            this.busy = false;

            if (this.select) {
                // Back to what this status says about the control, not simply
                // enabled: a completed row's dropdown is disabled *because* it is
                // completed, and un-disabling it on a refused save would offer
                // the very control that cannot be used.
                this.select.disabled = this.locked;
                this.select.value = this.current;
            }

            window.btaToast?.show('error', message);
        },
    }));

    /**
     * The working list's poller: the rows' current state, on a timer.
     *
     * Markup contract:
     *   <div x-data="appointmentRows({ endpoint, counts })"
     *        x-on:admin-appointment-counts.window="counts = $event.detail">
     *       <tr data-appointment-row="{{ $id }}" x-data="appointmentStatus(…)">
     *
     * The same arrangement as `notificationBell` and `adminLiveNotifications` —
     * a `setInterval` tick, a poll on tab focus, a `busy` flag so two ticks
     * cannot overlap, and a `destroy()` that takes the listener back off — for
     * the same reason: there is no WebSocket server here, and a `fetch` every
     * few seconds is the whole of the real-time behaviour.
     *
     * What it is for is the half of "live" the row's own save cannot cover. An
     * admin completing a booking gets Archive and Delete from their own write; an
     * admin looking at a list somebody else is working on does not, and without
     * this tick they would be looking at rows that no longer exist in that form
     * until they refreshed. The ids come from the DOM rather than from a second
     * copy of the page's query, so the poller can only ever be asked about rows
     * that are actually on screen.
     */
    Alpine.data('appointmentRows', (config = {}) => ({
        endpoint: config.endpoint || '',
        /**
         * Bookings per status, for the tabs.
         *
         * Named to say what it counts. The layout's polling component has a
         * `pending` key too, and that one is about unseen bookings — a different
         * number that happens to share a name with a status.
         */
        statusCounts: config.statusCounts || {},
        timer: null,
        busy: false,
        onVisible: null,

        /** How often to ask. Long enough not to hammer, short enough to feel live. */
        interval: 15000,

        init() {
            if (!this.endpoint) return;

            this.timer = setInterval(() => this.poll(), this.interval);

            // Same reasoning as the bell's: a hidden tab is not being looked at,
            // and a browser throttles background timers but not to nothing.
            this.onVisible = () => {
                if (!document.hidden) this.poll();
            };

            document.addEventListener('visibilitychange', this.onVisible);
        },

        destroy() {
            if (this.timer) clearInterval(this.timer);

            if (this.onVisible) {
                document.removeEventListener('visibilitychange', this.onVisible);
                this.onVisible = null;
            }
        },

        async poll() {
            if (!this.endpoint || this.busy) return;

            const ids = Array.from(this.$root.querySelectorAll('[data-appointment-row]')).map(
                (row) => row.dataset.appointmentRow,
            );

            if (ids.length === 0) return;

            this.busy = true;

            try {
                const url = new URL(this.endpoint, window.location.origin);

                ids.forEach((id) => url.searchParams.append('ids[]', id));

                const response = await fetch(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!response.ok) return;

                this.apply(await response.json().catch(() => ({})));
            } catch {
                // A failed poll changes nothing.
            } finally {
                this.busy = false;
            }
        },

        /**
         * Hand every row's answer to the row it belongs to.
         *
         * Broadcast on the window rather than dispatched from this element,
         * because the rows are its descendants and a dispatched event travels
         * upward. Each row listens for its own id and ignores the rest — fifteen
         * rows each comparing one number is cheaper than a shared store, and it
         * means a row can be repainted by its own save or by a poll without
         * either knowing the other exists.
         */
        apply(payload) {
            if (payload?.counts) this.statusCounts = payload.counts;

            Object.entries(payload?.rows ?? {}).forEach(([id, state]) => {
                window.dispatchEvent(
                    new CustomEvent('admin-appointment-row', {
                        detail: { ...state, id: Number(id) },
                    }),
                );
            });
        },
    }));

    /*
     * Tick-to-select on a paginated list, used by three screens: the customer's
     * notifications, the customer's My Appointments list, and the admin's
     * Archived Appointments.
     *
     * Markup contract:
     *   <div x-data="notificationBulk()">
     *       <input name="ids[]" value="…" x-model="selected" />
     *
     * Counting only — the confirmation itself belongs to `x-ui.confirm-dialog`,
     * which reads `selected` when the trigger dispatches to it. This component
     * used to ask the question itself with `window.confirm()`; that is the one
     * thing it no longer does.
     *
     * The checkboxes keep `name="ids[]"` but no form owns them any more: the
     * confirmation dialog carries the real form and renders its own hidden
     * `ids[]` inputs from `selected` when Yes is pressed. So these are labels
     * for the boxes rather than submitted fields, and `boxes()` can still find
     * them by that name.
     *
     * Select-all is scoped to what is on screen — the header toggle reads the
     * boxes rather than a server-side total, and the label says "on this page",
     * because the list is paginated and only the rendered boxes were ticked.
     *
     * A named factory rather than an inline object literal, because it is
     * registered under two names and an alias has to point at something that
     * exists. See the note on the second registration for what it was pointing
     * at instead.
     */
    const bulkSelection = () => ({
        selected: [],

        /**
         * Read from the DOM rather than held in a second array: the markup is
         * the only source of truth for what exists on this page.
         */
        boxes() {
            return Array.from(this.$root.querySelectorAll('input[name="ids[]"]'));
        },

        get total() {
            return this.boxes().length;
        },

        get allSelected() {
            return this.total > 0 && this.selected.length === this.total;
        },

        /**
         * Some but not all — which is the state a "select all" box is supposed to
         * sit in when only a few rows are ticked.
         *
         * Exists because a header checkbox that is neither ticked nor unticked
         * gives the customer no way to tell "nothing selected" from "some
         * selected", and both of those look identical without it. The My
         * Appointments list binds `x-bind:indeterminate` to this.
         *
         * `allSelected` is excluded deliberately: when everything *is* selected
         * the box should read as fully ticked, not as a half-ticked one.
         */
        get someSelected() {
            return this.selected.length > 0 && !this.allSelected;
        },

        toggleAll(checked) {
            this.selected = checked ? this.boxes().map((box) => box.value) : [];
        },

        /**
         * Drop the selection.
         *
         * For the "Clear selection" affordance next to a bulk action. Assigning
         * the array rather than emptying it in place is what lets Alpine's
         * `x-model` bindings re-evaluate; clearing it in place would leave every
         * row still ticking.
         *
         * Note the header checkbox follows on its own, because it is bound to
         * `allSelected`, which is derived from the same array.
         */
        clear() {
            this.selected = [];
        },
    });

    Alpine.data('notificationBulk', () => bulkSelection());

    /**
     * Reading a notification, from the notifications page.
     *
     * The navbar bell used to be the read receipt — opening it marked everything
     * read — so this page carried no read controls at all. The bell is now a
     * plain link, which means reading happens here, and the badge it used to
     * clear lives in the header, twenty lines and one Alpine scope away.
     *
     * That gap is why the count was moved into `window.btaUnread`: this component
     * has no reference to the header's bell and does not need one. It changes the
     * shared count, and every badge and the tab title follow.
     *
     * Two steps on purpose. The count moves *first*, before the request is sent,
     * so the badge and the "(2)" drop the instant the row is clicked rather than
     * whenever the network happens to answer. Then the server's own count
     * replaces it. The optimistic step is only ever a guess — a second tab, a poll
     * landing mid-flight or a double click all make it wrong — so the response
     * is treated as the truth and a failure rolls the guess back rather than
     * leaving a number nobody can account for.
     *
     * Without JavaScript every action here is still a form post that redirects
     * back here, so the page is not a JavaScript-only feature.
     *
     * Markup contract:
     *   <div x-data="notificationRead({ readAll, readUrl, unreadIds })">
     *       <form action="{{ readAll }}">…</form>
     *       <li><button x-on:click="markRead('uuid')">Mark as read</button></li>
     */
    Alpine.data('notificationRead', (config = {}) => ({
        readAll: config?.readAll || '',
        readUrl: config?.readUrl || '',

        /**
         * The ids of the unread rows on this page, as the server rendered them.
         *
         * Only used by "Mark all as read", to hide the per-row buttons in the
         * same tick the count drops. Scoped to this page deliberately: an unread
         * notification on page two is not on screen to settle, and the server's
         * count comes back corrected regardless.
         */
        unreadIds: config?.unreadIds || [],

        /** Per-row in-flight flags, so a double click cannot double-send. */
        pending: {},

        /**
         * Rows this tab has marked read, by id.
         *
         * Separate from `pending`, which is about the request in flight. This is
         * about what the page should *show*: the row's read button hides the
         * moment it is clicked rather than when the response lands, so the row
         * settles together with the badge instead of a moment later.
         */
        marked: {},

        marked(id) {
            return this.marked[id] === true;
        },

        get unread() {
            return window.btaUnread?.get() ?? 0;
        },

        urlFor(id) {
            return this.readUrl.replace('__ID__', encodeURIComponent(id));
        },

        csrf() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },

        async markRead(id) {
            if (!this.readUrl || this.pending[id] || this.marked[id]) return;

            this.pending[id] = true;
            this.marked[id] = true;

            const before = this.unread;

            // Optimistic. `normalise()` clamps at zero, so marking one read while
            // the count already reads zero is a no-op rather than a negative.
            window.btaUnread?.set(before - 1);

            try {
                const response = await fetch(this.urlFor(id), {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': this.csrf(),
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) throw new Error(response.status);

                const payload = await response.json();

                if (typeof payload.unread === 'number') {
                    window.btaUnread?.set(payload.unread);
                }
            } catch {
                // The request did not land, so the row is still unread. Put both
                // the count and the row back rather than leaving a badge and a
                // page that are quietly wrong; the poller corrects the count
                // either way, and nothing corrects a row that is still showing
                // its read button as gone.
                window.btaUnread?.set(before);
                delete this.marked[id];
            } finally {
                delete this.pending[id];
            }
        },

        async markAllRead() {
            if (!this.readAll) return;

            const before = this.unread;

            // Optimistic, as `markRead` is: the whole page settles at once. The
            // per-row buttons hide on the same flag, so the list and the badge
            // move together rather than one row at a time.
            //
            // The ids come from the server-rendered page rather than from reading
            // the DOM: they are already in the config, and guessing them back out
            // of form action URLs would couple this to the URL shape.
            this.unreadIds.forEach((id) => {
                this.marked[id] = true;
            });

            window.btaUnread?.set(0);

            try {
                const response = await fetch(this.readAll, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': this.csrf(),
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) throw new Error(response.status);

                const payload = await response.json();

                if (typeof payload.unread === 'number') {
                    window.btaUnread?.set(payload.unread);
                }
            } catch {
                // Roll both back together. A badge reading zero while every row
                // still shows its read button would be worse than being late.
                window.btaUnread?.set(before);
                Object.keys(this.marked).forEach((id) => delete this.marked[id]);
            }
        },
    }));

    /**
     * Tick-to-delete on the My Appointments list.
     *
     * Deliberately the same component as `notificationBulk` rather than a second
     * implementation of it. The two lists are the same interaction — tick a
     * settled row, tick a page of them, clear the set from one dialog — and
     * there is no behavioural difference between them: the appointments list
     * simply renders boxes only on rows the delete routes will accept, which is
     * a view decision and nothing the component has to know about.
     *
     * Counting only. The confirmation belongs to `x-ui.confirm-dialog`, which
     * reads `selected` when a trigger dispatches to it and carries the one real
     * form. The checkboxes keep `name="ids[]"` but no form owns them; the
     * dialog renders its own hidden `ids[]` inputs from `selected` on Yes.
     *
     * Select-all is scoped to what is on screen — the header toggle reads the
     * rendered boxes rather than a server-side total, and the label says "on
     * this page", because the list is paginated and only the boxes that were
     * drawn are ticked.
     *
     * Markup contract:
     *   <div x-data="appointmentBulk()">
     *       <input type="checkbox" name="ids[]" value="…" x-model="selected" />
     *
     * THIS ALIAS WAS BROKEN, AND SILENTLY SO
     *
     * It used to read `Alpine.data('appointmentBulk', () => notificationBulk())`.
     * `notificationBulk` is not a variable: `Alpine.data('notificationBulk', …)`
     * registers a *name*, and does not bind one. So the arrow function threw
     * `ReferenceError: notificationBulk is not defined` the moment either screen
     * mounted it, and the scope it produced was empty.
     *
     * Nothing on the page said so, because an empty scope is not a broken
     * element. The header checkbox still toggled itself — it is a real `<input>`
     * and the browser ticks it regardless of whether anything is listening — so
     * the customer saw a select-all box that filled in precisely nothing. The only
     * symptom was in the console, which is exactly the kind of bug that gets
     * reported as "the button does nothing" and then debugged in the wrong place.
     *
     * It affects both screens that used this name: the admin's Archived
     * Appointments list and the customer's My Appointments list. The notifications
     * list was fine, because it asks for `notificationBulk()` by name and that
     * registration always worked.
     *
     * `bulkSelection` is the factory both names now call, so the two cannot be
     * separate objects and this cannot drift again.
     */
    Alpine.data('appointmentBulk', () => bulkSelection());

    /**
     * Cropping a profile picture before it is uploaded.
     *
     * The camera badge on the profile page used to send the chosen file straight
     * to the server, which meant a customer framing a group photo got their face
     * in the bottom corner of a circle, and the only way to fix it was to crop in
     * a separate app and start again.
     *
     * Cropper.js (v1.6.2, vendored at `public/vendor/cropper/`) is loaded by the
     * profile page itself rather than bundled here: 37 KB for a screen most
     * customers never open is not worth carrying on every page. This component
     * assumes `window.Cropper` and does nothing until a file is chosen — if the
     * library is missing, `mount()` quietly declines and the file still uploads
     * uncropped rather than the picker breaking.
     *
     * Square, because every surface this picture appears on is a circle —
     * cropping to 1:1 here means the navbar avatar, the profile card and the
     * booking form all show the same framing with nothing cropped off again.
     *
     * The cropper's canvas is written back into the original file input with
     * `DataTransfer`, so the upload itself is unchanged: the form still posts
     * `profile_photo` and the user still presses Save. Nothing is sent until they
     * do, which also means Cancel genuinely cancels.
     *
     * Markup contract:
     *   <div x-data="profilePhotoCrop({ input, preview, fallback, crop })">
     *       <input type="file" id="profile-photo-input">
     *       <img id="profile-photo-preview">
     *       <span id="profile-photo-fallback">RO</span>
     *       <img id="profile-photo-crop">
     *       <button x-on:click="apply()">…</button>
     */
    Alpine.data('profilePhotoCrop', (config = {}) => ({
        // Selectors rather than ids baked in, so a second instance could exist.
        inputSelector: config?.input || '#profile-photo-input',
        previewSelector: config?.preview || '#profile-photo-preview',
        fallbackSelector: config?.fallback || '#profile-photo-fallback',
        cropSelector: config?.crop || '#profile-photo-crop',

        /** Output size. 500×500 is comfortably larger than any avatar here. */
        width: 500,
        height: 500,

        open: false,
        busy: false,

        /**
         * Whether this dialog is closing because the crop was applied.
         *
         * The dialog's own close handler tears the cropper down and empties the
         * input, because that is what Cancel and the ✕ and Escape all mean. Run
         * unguarded it would also throw away the file the user had just chosen,
         * the instant after they chose it.
         */
        confirmed: false,

        cropper: null,

        get input() {
            return this.$root.querySelector(this.inputSelector);
        },

        init() {
            const input = this.input;

            if (!input) return;

            input.addEventListener('change', () => {
                const file = input.files?.[0];

                if (!file) return;

                this.read(file);
            });

            /*
             * A second file after a Cancel needs the input cleared first, or the
             * `change` event does not fire at all when the same file is picked
             * twice in a row — which is exactly what somebody does after deciding
             * the first crop was wrong.
             *
             * Done through a DOM event rather than by reaching for the input
             * again, because the two elements are wired by id and not by
             * reference, and this keeps that wiring in one place.
             */
            this.$root.addEventListener('cropper:cancelled', () => {
                input.value = '';
            });
        },

        read(file) {
            const reader = new FileReader();

            reader.addEventListener('load', (event) => {
                const image = this.$root.querySelector(this.cropSelector);

                if (!image) return;

                // Wait for the image to decode before opening, or the cropper is
                // built against an element with no intrinsic size yet.
                image.addEventListener('load', () => this.showDialog(), { once: true });

                image.src = event.target.result;
            });

            reader.readAsDataURL(file);
        },

        /**
         * Open the dialog and build the cropper once it has a size.
         *
         * The order matters: Cropper measures the element it is attached to, and
         * the dialog is `x-show`-driven, so while it is closed the element has no
         * layout and the cropper would come up with a zero-sized canvas.
         */
        showDialog() {
            this.confirmed = false;
            this.open = true;

            this.$nextTick(() => {
                requestAnimationFrame(() => this.mount());
            });
        },

        mount() {
            const image = this.$root.querySelector(this.cropSelector);

            if (!image || typeof window.Cropper !== 'function') return;

            this.destroy();

            this.cropper = new window.Cropper(image, {
                aspectRatio: 1,
                viewMode: 1,
                autoCropArea: 1,
                guides: true,
                center: true,
                background: false,
            });
        },

        /**
         * Apply the crop: replace the chosen file with the cropped one.
         *
         * `input.files` cannot be assigned directly, so the cropped blob goes in
         * through a `DataTransfer`.
         */
        apply() {
            if (!this.cropper) return;

            this.busy = true;

            const canvas = this.cropper.getCroppedCanvas({
                width: this.width,
                height: this.height,
            });

            canvas.toBlob((blob) => {
                this.busy = false;

                if (!blob) return;

                const input = this.input;

                if (!input) return;

                const transfer = new DataTransfer();
                transfer.items.add(new File([blob], 'profile-photo.png', { type: 'image/png' }));

                input.files = transfer.files;

                this.showPreview(canvas.toDataURL('image/png'));

                this.confirmed = true;
                this.close();
            }, 'image/png');
        },

        /**
         * Put the cropped image in the avatar, hiding the initials if they were
         * standing in for one.
         *
         * The preview is shown rather than saved on its own — the file is only
         * written once the user presses Save — so this is a lie until then, and
         * a refresh puts the initials back. That is deliberate: the alternative
         * is uploading on crop, which loses the point of having a Cancel.
         */
        showPreview(source) {
            const preview = this.$root.querySelector(this.previewSelector);
            const fallback = this.$root.querySelector(this.fallbackSelector);

            if (preview) {
                preview.src = source;
                preview.classList.remove('hidden');
            }

            if (fallback) {
                fallback.classList.add('hidden');
            }
        },

        /** Cancel, ✕ and Escape all land here. */
        close() {
            this.open = false;
            this.teardown();
        },

        /**
         * The one place a dialog close can wipe the file input, and it checks
         * `confirmed` first so it only ever does so for an abandoned crop.
         */
        teardown() {
            this.destroy();

            if (this.confirmed) return;

            this.$root.dispatchEvent(new CustomEvent('cropper:cancelled'));
        },

        destroy() {
            if (!this.cropper) return;

            this.cropper.destroy();
            this.cropper = null;
        },
    }));

    /**
     * "See More" on a Services category section.
     *
     * A category shows four services and hides the rest behind this. Expanded
     * in place rather than by navigating, because the layout's whole argument is
     * that a category and its full price list read together — a link out would
     * split them across two pages.
     *
     * Markup contract:
     *   <section x-data="serviceCategory()">
     *       <li x-show="expanded" x-cloak>…</li>
     *       <button x-on:click="toggle()" :aria-expanded="expanded">
     *
     * One instance per section, so expanding Nail Art & Pedicure leaves Brow &
     * Lash Extension exactly as the customer left it.
     */
    /**
     * Category card disclosure + multi-select checkout on the Services page.
     *
     * Supports both:
     *   - Expanding/collapsing categories with more than 4 services (`expanded`, `toggle()`)
     *   - Selecting individual available services (`selected` array of slugs) or
     *     "Select All" (`toggleAll`, `allSelected`, `someSelected`), skipping
     *     services that are unavailable/sold out or non-bookable, and proceeding
     *     to checkout/booking with all selected items in one step.
     *
     * Registered under both `serviceCategory` (for categories with > 4 items) and
     * `categoryMultiSelect` (for categories with <= 4 items, which have no
     * "See More" disclosure toggle).
     */
    const serviceCategoryCard = () => ({
        expanded: false,
        selected: [],

        init() {
            // Returning here with the Back button can restore the previous
            // Alpine state from the browser's back/forward cache. Clear it so a
            // customer who has just completed an order can select items again.
            window.addEventListener('pageshow', () => {
                this.selected = [];
            });
        },

        toggle() {
            this.expanded = !this.expanded;
        },

        boxes() {
            return Array.from(
                this.$root.querySelectorAll('input[type="checkbox"][data-service-checkbox]:not(:disabled)'),
            );
        },

        get total() {
            return this.boxes().length;
        },

        get allSelected() {
            return this.total > 0 && this.selected.length === this.total;
        },

        get someSelected() {
            return this.selected.length > 0 && !this.allSelected;
        },

        get selectedTotal() {
            return this.boxes()
                .filter((box) => this.selected.includes(box.value))
                .reduce((sum, box) => sum + Number(box.dataset.servicePrice || 0), 0);
        },

        get selectedTotalLabel() {
            return new Intl.NumberFormat('en-PH', {
                style: 'currency',
                currency: 'PHP',
                minimumFractionDigits: 2,
            }).format(this.selectedTotal);
        },

        toggleAll(checked) {
            this.selected = checked ? this.boxes().map((box) => box.value) : [];
        },

        clearSelection() {
            this.selected = [];
        },

        proceedToBooking(baseUrl) {
            if (!baseUrl || this.selected.length <= 1) return;

            const url = new URL(baseUrl, window.location.origin);
            this.selected.forEach((slug) => {
                url.searchParams.append('services[]', slug);
            });

            // Reset selection state once items are dispatched to the order/booking.
            this.selected = [];

            window.location.assign(url.toString());
        },
    });

    Alpine.data('serviceCategory', () => serviceCategoryCard());
    Alpine.data('categoryMultiSelect', () => serviceCategoryCard());

    /**
     * The navbar notification bell: a live badge on a link to the notifications.
     *
     * There used to be a dropdown here, and the bell used to be the read
     * receipt — opening it cleared the badge. That arrangement is gone: the bell
     * is an ordinary link to the notifications page, and reading happens there.
     * Two reasons it did not survive:
     *
     *   - the read receipt was invisible. Nothing on screen said that opening the
     *     bell had marked eight things read, so a customer who opened it to
     *     check something had also silently cleared their badge.
     *   - the badge was the only place the count lived. Marking something read
     *     anywhere else could not move it, because the badge was twenty lines
     *     away in the header and had no idea.
     *
     * What is left is the part that was genuinely live: the count, kept current
     * by polling, and shared through `window.btaUnread` so the badge, the mobile
     * nav row and the tab title cannot disagree.
     *
     * Polling, not pushing. There is no WebSocket server in this project — no
     * Reverb, no Pusher — and standing one up to animate a badge would be a
     * second always-on process for a page that is otherwise plain PHP. A `fetch`
     * every few seconds is the whole of the real-time behaviour, and it degrades
     * to a static badge rather than to a broken socket.
     *
     * Markup contract:
     *   <div x-data="notificationBell({ endpoint, unread, readAll })">
     *       <a href="/notifications" x-on:click="markAllRead()">
     *           <span x-text="unread"></span>
     *       </a>
     *
     * Its state is spread into a larger `x-data` object on the customer header
     * rather than owning the element, because the desktop bell and the mobile nav
     * row both show the count and must not each start a poller.
     */
    Alpine.data('notificationBell', (config = {}) => ({
        // A guest gets a null config, and this is a no-op scope rather than an
        // error: the header is shared by signed-out visitors.
        endpoint: config?.endpoint || '',
        unread: Number(config?.unread) || 0,

        /**
         * The write that marks everything read, handed over from the navbar.
         *
         * The same URL the notifications page's own "Mark all as read" button
         * posts to, rather than a second way of expressing it. The bell fires it
         * on click so the badge goes off the moment it is touched, and the page
         * still fires it from its own button — one endpoint, two entry points,
         * and neither has to know the other's markup.
         *
         * Empty for a guest, which is what makes the click handler below a no-op
         * rather than a request that 401s on a page every visitor loads.
         */
        readAll: config?.readAll || '',

        /**
         * The poller's cursor: the newest notification this tab has seen.
         *
         * Sent as `since` so an idle tab's tick costs an indexed lookup rather
         * than a re-read of the inbox. The dropdown that used to be fed from this
         * is gone — the bell is a link to the notifications page and the page
         * renders its own list — so the cursor no longer has to survive a list of
         * rows, only a count.
         */
        cursor: '',

        timer: null,
        busy: false,

        /*
         * A read-all is on the wire.
         *
         * Not a count of requests: a flag that says "a poll landing now would be
         * stale". A tick answered before the write reaches the database still
         * reports the unread rows it started with, so honouring it mid-clear would
         * put the badge straight back up after the click took it down.
         */
        clearing: false,

        unsubscribe: null,

        /**
         * Kept so `destroy()` can take it back off.
         *
         * The listener is on `document`, not on the element, so it outlives the
         * Alpine scope that added it. `clearInterval` alone therefore stops the
         * ticking but not the catch-up: every visibilitychange would keep calling
         * `poll()` on a scope that no longer exists, and each of those is a real
         * request. Harmless while every page load replaces the document — which is
         * why it was never noticed — and a slow request leak the moment the scope
         * is torn down and rebuilt in place.
         */
        onVisible: null,

        /** How often to ask. Long enough not to hammer, short enough to feel live. */
        interval: 15000,

        init() {
            // Seed the shared store from what the server rendered. Done even with
            // no endpoint, because a signed-out visitor still mounts this and a
            // stale title from a previous page would be worse than none.
            window.btaUnread?.seed(this.unread);

            if (!this.endpoint) return;

            // The badge is a view of the shared count, not its own copy. That is
            // what lets marking something read on the notifications page — a
            // different part of the document entirely — move this badge and the
            // tab title without either of them knowing who changed it.
            this.unsubscribe = window.btaUnread?.subscribe((count) => {
                this.unread = count;
            });

            this.timer = setInterval(() => this.poll(), this.interval);

            // A hidden tab is not being looked at, and a browser already throttles
            // background timers — but not to nothing, and there is no reason to
            // spend a request per minute on a tab in the background.
            this.onVisible = () => {
                if (!document.hidden) this.poll();
            };

            document.addEventListener('visibilitychange', this.onVisible);
        },

        destroy() {
            if (this.timer) clearInterval(this.timer);

            if (this.onVisible) {
                document.removeEventListener('visibilitychange', this.onVisible);
                this.onVisible = null;
            }

            this.unsubscribe?.();
        },

        async poll() {
            if (!this.endpoint || this.busy) return;

            this.busy = true;

            try {
                const url = new URL(this.endpoint, window.location.origin);

                if (this.cursor) {
                    url.searchParams.set('since', this.cursor);
                }

                const response = await fetch(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!response.ok) return;

                this.apply(await response.json());
            } catch {
                // Offline, or the server restarting. The next tick tries again —
                // a failed poll must not clear the badge the customer can see.
            } finally {
                this.busy = false;
            }
        },

        /**
         * A poll is a correction, not an increment.
         *
         * The feed answers with the authoritative unread count, so this hands it
         * straight to the shared store rather than adjusting the local number —
         * two tabs, a read that landed elsewhere and a clock that jumped all
         * make arithmetic wrong, and only the server's number is worth keeping.
         *
         * Except while a read-all is in flight: that poll was answered before the
         * write landed, so its number is older than what the customer has just
         * been told. Taking it would put a badge back on the bell they just
         * clicked to clear it, which reads as a failure rather than as a race.
         */
        apply(payload) {
            if (payload.unread !== undefined && payload.unread !== null) {
                if (this.clearing) {
                    window.btaUnread?.set(0);
                } else {
                    window.btaUnread?.set(payload.unread);
                }
            }

            if (payload.since) {
                this.cursor = payload.since;
            }
        },

        /**
         * Clear the badge because the bell was clicked.
         *
         * The bell is a link, so this is not a substitute for navigating — it is
         * what happens on the way out. Two steps, in this order and for these
         * reasons:
         *
         *   1. zero the shared count now. The badge is the customer's evidence
         *      that they have not seen something, so it has to go the moment they
         *      ask to see it. Making that wait on a round trip is the whole
         *      behaviour this is meant to remove, and on a link that navigates
         *      away it would be invisible anyway: the page would change before
         *      the response arrived.
         *   2. fire the write behind the navigation. `keepalive` is what lets it
         *      finish — the browser does not cancel an in-flight keepalive request
         *      just because the document it was made from is going away, which is
         *      the difference between clearing the badge in the database and
         *      merely hiding it.
         *
         * A failure rolls the count back, because a badge reading zero over rows
         * that are still unread is the one state nobody can account for. It
         * usually cannot be seen at all — the navigation has replaced the page by
         * then, and the page renders from the database — but the next poll on a
         * tab that stayed put would be the thing that has to be true.
         */
        async markAllRead() {
            if (!this.readAll || this.clearing) return;

            const before = window.btaUnread?.get() ?? 0;

            this.clearing = true;
            window.btaUnread?.set(0);

            try {
                const response = await fetch(this.readAll, {
                    method: 'PATCH',
                    // Survives the navigation this click is causing.
                    keepalive: true,
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) throw new Error(response.status);

                const payload = await response.json();

                if (typeof payload.unread === 'number') {
                    window.btaUnread?.set(payload.unread);
                }
            } catch {
                window.btaUnread?.set(before);
            } finally {
                this.clearing = false;
            }
        },
    }));


    /**
     * The admin panel's live work queue — and nothing else.
     *
     * This used to be the topbar's notification bell: a `<div>` with a bell icon,
     * a corner badge and a dropdown. The bell and the dropdown are gone, and the
     * count they showed is on the sidebar instead, beside the rows that act on
     * it. What is left is the part that had nothing to do with the icon — the
     * single poll that keeps those badges and the tab title in step.
     *
     * So it renders nothing and lives in the layout. Mounted once per page rather
     * than in the sidebar because the sidebar is the thing being updated: a
     * component inside the element it broadcasts to would be a component whose
     * lifetime is tied to markup it is only supposed to be observing.
     *
     * Three consumers, one request, which is the whole point. `btaAdminUnread`
     * drives the tab title, and `admin-pending` / `admin-messages` each carry one
     * half to the sidebar row that displays it — so a count can never appear on
     * two rows with different numbers, and the title can never disagree with the
     * badges beside it.
     *
     * Also the single place the work queue is *changed*. It listens on the window
     * for `admin-read-all` and `admin-read-message` so the Messages screen can
     * mark an enquiry read without a second poller and without knowing anything
     * about the feed.
     *
     * The badged Messages row in the sidebar dispatches `admin-read-all` too, for
     * the same reason it exists at all rather than only the screen's button: a
     * badge that outlives the click that dismissed it is the behaviour the
     * removed topbar bell was criticised for. The `keepalive` on the write is what
     * makes that safe — a click on a link cannot be relied on to finish a request
     * the navigation interrupts.
     *
     * Markup contract (see `layouts.admin`):
     *   <div x-data="adminLiveNotifications({ endpoint, pending, unread, readUrl, readAllUrl })"
     *        x-on:admin-read-all.window="markAllRead()"
     *        x-on:admin-read-message.window="read($event.detail)" hidden></div>
     */
    Alpine.data('adminLiveNotifications', (config = {}) => ({
        endpoint: config?.endpoint || '',
        pending: Number(config?.pending) || 0,
        messages: Number(config?.unread) || 0,
        readUrl: config?.readUrl || '',
        readAllUrl: config?.readAllUrl || '',
        timer: null,
        busy: false,
        /** Ids already sent, so a double click cannot double-submit a read. */
        reading: {},

        /**
         * A read-all is on the wire.
         *
         * Its own flag rather than the poll's `busy`, because the two are not
         * exclusive and the guard that used to be shared had it backwards: an
         * admin who clicked the badged Messages row while a poll was in flight
         * would be refused the clear and keep a badge they had just clicked to
         * remove. A poll in flight is not a reason to ignore a read-all.
         *
         * What it is a reason for is the other direction, handled in `apply`.
         */
        clearing: false,

        interval: 15000,

        /*
         * Kept so `destroy()` can take it back off.
         *
         * Same reasoning as the customer bell's: the listener is on `document`, so
         * it outlives the scope that registered it, and `clearInterval` stops the
         * tick without stopping the catch-up poll it triggers on every tab switch
         * back. See `notificationBell.onVisible`.
         */
        onVisible: null,

        init() {
            // Server truth first, so the title and the badge agree before the
            // first poll rather than after it. Seeded from the number the server
            // rendered, because on this first render the store has never been
            // told anything.
            window.btaAdminUnread?.seed(this.total);

            if (!this.endpoint) return;

            this.timer = setInterval(() => this.poll(), this.interval);

            this.onVisible = () => {
                if (!document.hidden) this.poll();
            };

            document.addEventListener('visibilitychange', this.onVisible);
        },

        destroy() {
            if (this.timer) clearInterval(this.timer);

            if (this.onVisible) {
                document.removeEventListener('visibilitychange', this.onVisible);
                this.onVisible = null;
            }
        },

        /**
         * Bookings the salon has not looked at yet.
         *
         * This is the number the tab title and the Appointments badge show.
         * Enquiries deliberately do not contribute: the Messages row is not in
         * the admin nav, so they have no visible notification surface and only
         * produced a count nobody could account for. They still travel in
         * `this.messages` for the Messages screen's own unread pill.
         */
        get total() {
            return this.pending;
        },

        csrf() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },

        urlFor(id) {
            return this.readUrl.replace('__ID__', encodeURIComponent(id));
        },

        /**
         * Take the server's answer as the truth and push it everywhere at once.
         *
         * Both halves matter. The store re-renders the tab title, and the two
         * events carry each count to the sidebar row that displays it. Neither
         * should be derived by the caller, or a future caller will remember one
         * and forget the other — which is how the title and a badge come to
         * disagree with nothing on screen to say which is lying.
         */
        apply(payload) {
            const pending = Number(payload?.pending);
            const messages = Number(payload?.messages);

            if (Number.isNaN(pending)) return;
            if (Number.isNaN(messages)) return;

            this.pending = pending;

            // A read-all just outranks a poll. The tick was answered before the
            // write landed, so its message count is older than the badge the admin
            // has already been shown as cleared — honouring it would put the badge
            // back on the row they clicked, which reads as a failure and not as a
            // race. The write's own response corrects this a moment later.
            this.messages = this.clearing ? 0 : messages;

            window.btaAdminUnread?.set(this.total);
            this.dispatch('admin-pending', pending);
            this.dispatch('admin-messages', this.messages);
        },

        /**
         * Mark one enquiry read, on behalf of the Messages screen.
         *
         * The screen dispatches rather than calling this directly so there is
         * one implementation of "read an enquiry" in the panel, and so the
         * screen needs no knowledge of the feed, the store or the badges.
         */
        async read(id) {
            if (!this.readUrl || this.reading[id]) return;

            this.reading[id] = true;

            // Optimistic: only the Messages screen's own unread pill comes off
            // on the click. The tab title and Appointments badge count unseen
            // bookings, so reading an enquiry must not move them. The server's
            // numbers replace these a moment later, and a failure puts them
            // back.
            this.dispatch('admin-messages', Math.max(0, this.messages - 1));

            try {
                const response = await fetch(this.urlFor(id), {
                    method: 'PATCH',
                    // Survives a navigation, so marking-as-read is not lost when
                    // reading an enquiry leads somewhere else.
                    keepalive: true,
                    headers: {
                        'X-CSRF-TOKEN': this.csrf(),
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) throw new Error(response.status);

                this.apply(await response.json());
            } catch {
                // Rolled back from the halves, not from the store: the store is
                // holding the optimistic value right now, so reading the total
                // back out of it would restore the wrong number.
                window.btaAdminUnread?.seed(this.total);
                this.dispatch('admin-messages', this.messages);
            } finally {
                delete this.reading[id];
            }
        },

        /**
         * Clear every enquiry.
         *
         * Asked for by the Messages screen's own "Mark all as read" button, and
         * by the badged Messages row in the sidebar — the same write from two
         * places, which is why this stays the only implementation of it.
         *
         * The optimistic step is what the click is for: the badge and the tab
         * title come off immediately rather than after a round trip, and the
         * pending half is untouched, because a booking is cleared by deciding on
         * it, not by looking at the count.
         *
         * `keepalive` because the sidebar row that triggers this is also a link,
         * and the browser cancels an ordinary in-flight request when the document
         * it was made from starts unloading. Without it the badge would clear and
         * the enquiries would stay unread — the exact state this exists to end.
         */
        async markAllRead() {
            if (!this.readAllUrl || this.clearing) return;

            this.clearing = true;

            window.btaAdminUnread?.seed(this.pending);
            this.dispatch('admin-messages', 0);

            try {
                const response = await fetch(this.readAllUrl, {
                    method: 'POST',
                    keepalive: true,
                    headers: {
                        'X-CSRF-TOKEN': this.csrf(),
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) throw new Error(response.status);

                this.apply(await response.json());
            } catch {
                window.btaAdminUnread?.seed(this.total);
                this.dispatch('admin-messages', this.messages);
            } finally {
                this.clearing = false;
            }
        },

        async poll() {
            if (!this.endpoint || this.busy) return;

            this.busy = true;

            try {
                const response = await fetch(this.endpoint, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!response.ok) return;

                this.apply(await response.json());
            } catch {
                // A failed poll changes nothing.
            } finally {
                this.busy = false;
            }
        },

        dispatch(name, detail) {
            window.dispatchEvent(new CustomEvent(name, { detail }));
        },
    }));

    /**
     * The driver's counterpart, behind `x-ui.confirm-dialog`.
     *
     * Owns the prompt's state so the dialog component stays presentational:
     * what is being deleted, how many, which URL, and the focus bookkeeping.
     * Markup contract:
     *   <div x-data="confirmDialog({ action, subject })"
     *        x-on:confirm-thing.window="ask($event.detail)"
     *        x-show="open" x-on:keydown.escape.window="if (open) cancel()">
     *       <input name="ids[]" />  (rendered from `ids` by the component)
     *       <button type="button" x-on:click="cancel()">
     *       <button type="submit" x-ref="confirm">
     *
     * Focus handling is the reason this is a component rather than an inline
     * `x-data` literal. On open the confirming button takes focus, so Enter
     * submits immediately and Tab walks forward through the dialog instead of
     * back into the page behind it; on close focus returns to whatever opened
     * the dialog, so a keyboard user is not dropped at the top of the document.
     *
     * Note there is no focus *trap* — that needs `@alpinejs/focus`, which this
     * project does not depend on, and none of the admin dialogs have one either.
     * Tab can still walk out of the dialog into the page behind it.
     */
    /**
     * The Terms & Conditions dialog, shared by the customer site and the admin
     * panel.
     *
     * One dialog rather than one per category, with the title and body swapped
     * on open. That is what MCA Café does, and it is also why the content
     * arrives in the config instead of being fetched: three short documents, on
     * every page, for a dialog that opens in no time at all and needs no spinner
     * and no error path.
     *
     * `show()` / `open` / `close()` is the same shape as `appointmentViewer` and
     * `confirmDialog`, so a caller wires it the way every other dialog in the
     * project is wired and this one does not need its own idiom.
     *
     * The content is the admin's own HTML, pre-rendered into a numbered list by
     * `App\Support\TermsRenderer` on the server. It is injected with `x-html`
     * because escaping it would show the customer literal `<h3>` tags instead of
     * the terms — which is safe here, and only here, because every string in
     * `config` came out of a column only `admin.terms.manage` can write. The same
     * is not true of arbitrary HTML, so this must not become the pattern for
     * anything user-supplied.
     *
     * Markup contract:
     *   <div x-data="termsModal({ booking: { title, updated, html }, … })"
     *        x-on:terms-modal.window="show($event.detail)">
     *       <h2 x-text="title"></h2>
     *       <div x-html="current?.html"></div>
     *   <a x-on:click.prevent="$dispatch('terms-modal', { category })">
     */
    Alpine.data('termsModal', (config = {}) => ({
        documents: config || {},
        open: false,
        key: null,

        /**
         * Open a category.
         *
         * An unknown key still opens the dialog, showing the "not published yet"
         * message. Refusing to open would leave a trigger that appears to do
         * nothing, and a visible explanation beats a dead click.
         */
        show(detail) {
            this.key = detail?.category || null;
            this.open = true;
        },

        close() {
            this.open = false;
        },

        get current() {
            return this.key ? this.documents[this.key] || null : null;
        },

        get title() {
            // Falls back to the general heading rather than leaving an empty
            // title bar, which is what an unknown category would otherwise do.
            return this.current?.title || 'General Terms & Conditions';
        },

        /** The "last updated" line, or empty when the policy carries no date. */
        get meta() {
            const doc = this.current;

            if (!doc || !doc.updated) return '';

            return `Last updated ${doc.updated}`;
        },
    }));

    Alpine.data('confirmDialog', (config = {}) => ({
        open: false,
        /*
         * The configured action, kept verbatim.
         *
         * `{id}` has to be resolved into a real URL before the form submits, and
         * that resolution must not be written back over the template. `String
         * .replace()` consumes what it matches, so a template that is resolved in
         * place still holds the first row's id on the next open — the second row's
         * "Yes" then posts the first row's URL. On Services that was a DELETE for
         * a service already removed, and route model binding answering 404 for a
         * soft-deleted row. Every open therefore starts from this copy, so the
         * dialog is correct for the second row as well as the first, and stays
         * correct whether the admin answered Yes or No to the row before.
         */
        template: config.action || '',
        action: config.action || '',
        count: 0,
        ids: [],
        subject: config.subject || '',
        /**
         * An optional name for the thing being confirmed, used instead of the
         * count when the caller supplies one.
         *
         * "Delete 1 service?" answers which screen you are on; it does not
         * answer which service, and on a list of eighty rows an admin deleting
         * the wrong one is not a recoverable mistake. So a caller that knows the
         * row's name passes it as `detail.name` and the sentence becomes
         * "Delete Glow Foot Spa?". Absent — every bulk caller — the counted
         * sentence is used exactly as before, so no existing dialog changes.
         */
        label: null,
        opener: null,

        /**
         * Naive pluralisation: appends an `s` for anything but one. Fine for
         * the nouns this prompt is used with, and it keeps the count and the
         * noun from being able to disagree the way two server-rendered strings
         * would.
         */
        get subjectPhrase() {
            if (!this.subject) return '';
            return this.count === 1 ? this.subject : `${this.subject}s`;
        },

        ask(detail = {}) {
            // Captured before the dialog is shown, so it can be handed back on
            // close. If it is lost, close() falls through and leaves focus be.
            this.opener = document.activeElement;

            if (detail.action) {
                this.action = detail.action;
            } else {
                /*
                 * Back to the configured action, not whatever the last row left.
                 *
                 * `action` is reactive precisely so the form's `x-bind:action`
                 * picks the new URL up, which also makes it the *resolved* URL from
                 * the previous open. Starting from it is what had every row after
                 * the first posting the first row's endpoint — a DELETE for a
                 * service that was already soft-deleted, so route model binding
                 * answered 404 instead of deleting anything.
                 */
                this.action = this.template;
            }

            /*
             * `{id}` in the configured action is filled in from the event.
             *
             * A single-row dialog cannot be given its URL at render time — there
             * is one dialog and many rows, and the row that opened it is not known
             * until it is clicked. Blade cannot help either, because a `:action`
             * attribute is evaluated when the page is rendered rather than when the
             * event fires. So the caller passes a template
             * (`/admin/appointments/7/archive`) and the placeholder is resolved
             * here, which keeps the "one real form" property: still no form per row.
             */
            this.action = this.action.replace('{id}', detail.id ?? '{id}');

            // A caller that names a single row rather than a ticked set passes an
            // id and no count. Defaulting to zero there would render "Delete 0
            // bookings?", so an id on its own is read as one subject — which is
            // the only case a single-row dialog ever describes.
            this.count = Number(detail.count) || (detail.id ? 1 : 0);
            this.ids = Array.isArray(detail.ids) ? detail.ids : [];

            // Reset on every open, not just when a name is supplied: the dialog
            // is shared, so a leftover name from the row before would otherwise
            // greet the next caller with the wrong service.
            this.label = detail.name ?? null;

            this.open = true;

            this.$nextTick(() => this.$refs.confirm?.focus());
        },

        cancel() {
            this.close();
        },

        close() {
            if (!this.open) return;

            this.open = false;
            this.label = null;

            const opener = this.opener;
            this.opener = null;

            if (opener && opener.isConnected) {
                this.$nextTick(() => opener.focus());
            }
        },
    }));

    /**
     * The customer's "Cancel Appointment" dialog.
     *
     * The dialog holds one real form that posts to
     * `appointments.reschedule`'s sibling route, `appointments.cancel.update`,
     * with exactly the fields `CancelAppointmentController@update` validates:
     * `reason_preset`, `reason`, `agree_cancellation_policy`. Nothing here
     * decides anything — the summary is read out of the event so the customer
     * can see what they are cancelling before they commit to it, and the
     * server still refuses anything invalid.
     *
     * Markup contract:
     *   <div x-data="appointmentCancelPanel()">
     *       <x-ui.dialog …>  <button x-on:click="$dispatch('cancel-appointment', {…})">
     *
     * `agree_cancellation_policy` is `accepted` server-side, so the checkbox is
     * not merely decorative: submitting without it comes back as a validation
     * error, which is why the dialog re-opens on errors rather than closing.
     */
    Alpine.data('appointmentCancelPanel', () => ({
        open: false,
        appointment: null,

        ask(detail = {}) {
            this.appointment = detail;
            this.open = true;

            this.$nextTick(() => this.$refs.confirm?.focus());
        },

        close() {
            this.open = false;
        },
    }));

    /**
     * The customer's "Reschedule Appointment" dialog.
     *
     * Posts `preferred_date`, `preferred_time` and `reason` to
     * `appointments.reschedule.update`, which re-validates them through
     * `RescheduleRequest`. The time dropdown is not a fixed list: it is the
     * slots the server says are free for the chosen date, fetched from the same
     * `appointments.slots` endpoint the full-page reschedule form uses, so the
     * dialog cannot offer a slot the booking rules would reject — closed days,
     * taken times, the one-day minimum notice and blocked dates all come back
     * already applied.
     *
     * It queries that endpoint exactly as the full-page form does, `service_id`
     * included, rather than a variant of it. That means the appointment's own
     * booked time reads as taken if the customer keeps the same date, so it will
     * not be offered back to them. That is the existing form's behaviour too;
     * teaching the endpoint to exclude an appointment is a change to
     * `AppointmentController::slots`, and this is meant to leave the underlying
     * logic alone.
     *
     * Markup contract:
     *   <div x-data="appointmentReschedulePanel({ endpoint, hours, serviceId })">
     *       <input type="date" x-model="date" x-on:change="loadSlots()" />
     *       <select name="preferred_time"> … </select>
     *       <button x-on:click="$dispatch('reschedule-appointment', {…})">
     */
    Alpine.data('appointmentReschedulePanel', (config = {}) => ({
        open: false,
        appointment: null,

        endpoint: config.endpoint || '',
        hours: config.hours || {},
        serviceId: config.serviceId || null,

        // Defaults come from the server so the dialog opens on a bookable date
        // rather than today, which is never bookable here.
        date: config.date || '',
        minDate: config.minDate || '',
        maxDate: config.maxDate || '',

        slots: [],
        loadingSlots: false,
        slotsError: '',

        async ask(detail = {}) {
            this.appointment = detail;

            // Reopening on a validation error must not silently discard what the
            // customer already typed, so a prefilled date wins.
            if (!detail.date) {
                this.date = this.appointment?.currentDate ?? '';
            }

            this.open = true;

            await this.loadSlots();

            this.$nextTick(() => this.$refs.confirm?.focus());
        },

        close() {
            this.open = false;
        },

        async loadSlots() {
            if (!this.date || !this.endpoint) {
                this.slots = [];
                return;
            }

            this.loadingSlots = true;
            this.slotsError = '';

            const url = new URL(this.endpoint, window.location.origin);
            url.searchParams.set('date', this.date);

            if (this.serviceId) {
                url.searchParams.set('service_id', this.serviceId);
            }

            try {
                const response = await fetch(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!response.ok) {
                    throw new Error('unreachable');
                }

                const payload = await response.json();

                this.slots = payload.slots || [];
                this.minDate = payload.minDate || this.minDate;
                this.maxDate = payload.maxDate || this.maxDate;
            } catch {
                this.slots = [];
                this.slotsError = 'Could not load the available times. Try again.';
            } finally {
                this.loadingSlots = false;
            }
        },

        /**
         * The salon's hours for the chosen day, for the hint under the picker.
         *
         * Read from the same `operating_hours` map the booking rules use, keyed
         * by lowercase day name, rather than a fixed string — a hint claiming
         * one window on a day the salon opens another is worse than no hint.
         * A closed day has no entry, which is why that reads as "closed" rather
         * than falling through to empty output.
         */
        hoursFor(date) {
            if (!date) return '';

            const day = new Date(`${date}T00:00:00`).toLocaleDateString('en-US', { weekday: 'long' }).toLowerCase();
            const window = this.hours[day];

            if (!window) return 'The salon is closed on this day.';

            return `${this.formatTime(window[0])} – ${this.formatTime(window[1])} only`;
        },

        formatTime(value) {
            const [hours, minutes] = String(value).split(':').map(Number);
            const suffix = hours >= 12 ? 'PM' : 'AM';
            const hour12 = hours % 12 === 0 ? 12 : hours % 12;

            // Zero-padded, so "09:00 AM – 05:00 PM" matches the Operating Hours
            // card on the contact page, which formats the same values through
            // `SalonSetting::formatTimeForDisplay()`. Two spellings of the same
            // schedule on one site reads as a bug.
            return `${String(hour12).padStart(2, '0')}:${String(minutes).padStart(2, '0')} ${suffix}`;
        },
    }));

    /**
     * A read-only dialog fed a map of payloads keyed by id, so opening it costs
     * no request — the page already renders what the dialog shows. Field names
     * come from the payload rather than being hard-coded here, so a caller can
     * map its own rows; the admin Appointments list is the one that uses it.
     *
     * The second argument is an id to open on load. The retired
     * `/appointments/{id}` page now redirects to My Appointments with
     * `?view=ID`, so a bookmark or a notification link arrives here and the
     * dialog has to come up already showing that booking — otherwise the
     * redirect lands the customer on the list with nothing open and no
     * explanation of what happened to the link they followed.
     *
     * Resolved in `init()` rather than at construction: `rows` is available
     * immediately, but the id may legitimately name a booking that is not on
     * this page (a status filter, a different page number), in which case
     * `show()` finds nothing and the dialog correctly stays closed rather than
     * opening empty.
     *
     * Markup contract:
     *   <div x-data="appointmentViewer(rows, openId, bookAgainUrlTemplate)">
     *       <x-ui.dialog …>  <button x-on:click="$dispatch('view-appointment', {id})">
     *
     * `bookAgainUrlTemplate` is a URL with `__ID__` where the appointment id goes.
     * Optional, because the admin Appointments list uses this same component for
     * the same dialog and has no Book Again — an admin repeating a customer's
     * booking is not a thing the panel offers. Null there, so `bookAgainUrl` is
     * null and the customer-side button stays hidden rather than rendering a link
     * to a route the admin cannot use.
     */
    Alpine.data('appointmentViewer', (rows = {}, openId = null, bookAgainUrlTemplate = null) => ({
        rows: rows || {},
        open: false,
        detail: null,
        bookAgainUrlTemplate: bookAgainUrlTemplate,

        /**
         * The Book Again link for whatever the dialog is showing, or null.
         *
         * Three things have to line up, and each is checked here rather than in the
         * markup: the caller supplied a template at all, the dialog is open on a
         * row, and that row is Completed. The status is read from the payload
         * rather than re-derived, so the button and the row's own icon in the
         * Actions column are reading the same value and cannot disagree about which
         * visits are repeatable.
         */
        get bookAgainUrl() {
            if (! this.bookAgainUrlTemplate || ! this.detail) {
                return null;
            }

            if (this.detail.status !== 'completed') {
                return null;
            }

            return this.bookAgainUrlTemplate.replace('__ID__', this.detail.id);
        },

        init() {
            if (openId) {
                this.show(openId);
            }
        },

        show(id) {
            this.detail = this.rows[id] || null;
            this.open = Boolean(this.detail);
        },

        close() {
            this.open = false;
        },
    }));
});

// Alpine's npm build does not auto-start.
window.Alpine = Alpine;
Alpine.start();
