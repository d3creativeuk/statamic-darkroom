import { reactive, watch } from 'vue';
import { http } from './useHttp.js';

/**
 * One listing of saved images: History, or the user's favourites. Both come
 * from the same endpoint and page, search and refresh the same way.
 *
 * `initial` is a first page already in hand; without one, nothing is loaded
 * until `refresh()` is first called. `onLoad` hears every answer, which is
 * how the page keeps the Favourites tab's count current.
 */
export function useSavedImages({ url, initial = null, favourites = false, onLoad = null }) {
    const state = reactive({
        items: [],
        total: 0,
        all: 0,
        meta: { current_page: 1, last_page: 1, per_page: 50, from: 0, to: 0, total: 0 },
        ...(initial ?? {}),
        search: '',
        loading: false,
        loaded: initial !== null,
    });

    function listUrl(page, perPage) {
        const query = new URLSearchParams({ page, per_page: perPage });

        if (favourites) {
            query.set('favourites', '1');
        }

        if (state.search.trim()) {
            query.set('search', state.search.trim());
        }

        return `${url}?${query}`;
    }

    // Each search, page change or save landing asks again. Only the latest
    // answer is kept, so a slow early reply cannot overwrite a newer one.
    let latest = 0;

    /**
     * Load a page; by default the one on screen, so a save or an edit
     * refreshes it in place. The server moves back to the last page if this
     * one no longer exists.
     */
    async function refresh({ page = state.meta.current_page, perPage = state.meta.per_page, report = false } = {}) {
        const request = ++latest;

        state.loading = true;

        try {
            const fresh = await http('GET', listUrl(page, perPage));

            if (request === latest) {
                Object.assign(state, fresh, { loaded: true });
                onLoad?.(fresh);
            }
        } catch (e) {
            // Unasked for, the list on screen is only stale, not wrong, and the
            // next save or a reload brings it up to date. Asked for, say so.
            if (report) {
                Statamic.$toast.error(e.message);
            }
        } finally {
            if (request === latest) {
                state.loading = false;
            }
        }
    }

    let searchTimer = null;

    watch(
        () => state.search,
        () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => refresh({ page: 1 }), 300);
        },
    );

    function page(number) {
        refresh({ page: number, report: true });
    }

    // Kept as a user preference, as core's listings keep theirs, so the choice
    // follows the user to the next visit and to other devices. History and
    // Favourites share it.
    function perPage(size) {
        Statamic.$preferences.set('darkroom.history.per_page', size);

        refresh({ page: 1, perPage: size, report: true });
    }

    return { state, refresh, page, perPage };
}
