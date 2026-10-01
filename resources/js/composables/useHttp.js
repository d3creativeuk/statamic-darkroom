// Statamic does not export its axios instance to addons, so requests go through
// fetch with the same headers the Control Panel sends.

export class HttpError extends Error {
    constructor(status, data) {
        super(HttpError.describe(status, data));

        this.status = status;
        this.code = data?.code ?? null;
        this.errors = data?.errors ?? {};
    }

    static describe(status, data) {
        // A signed-out or expired session comes back as a bare
        // "Unauthenticated." or "CSRF token mismatch.", which tells nobody
        // what to do, so these always get the plain-English version.
        if ([401, 419].includes(status)) {
            return HttpError.MESSAGES[status];
        }

        // A validation failure carries one message per field. The first is
        // the one worth showing.
        const first = Object.values(data?.errors ?? {})[0];

        if (first) {
            return Array.isArray(first) ? first[0] : first;
        }

        if (data?.message) {
            return data.message;
        }

        return HttpError.MESSAGES[status] ?? __('Something went wrong.');
    }

    static get MESSAGES() {
        return {
            401: __('Your session has expired. Reload the page and log in again.'),
            403: __('You are not allowed to do that.'),
            419: __('Your session has expired. Reload the page and try again.'),
            429: __('Too many requests. Wait a moment and try again.'),
        };
    }
}

export async function http(method, url, body) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (response.status === 204) {
        return null;
    }

    let data = null;

    try {
        data = await response.json();
    } catch (e) {
        // An error page rather than JSON. The status says enough.
    }

    if (!response.ok) {
        throw new HttpError(response.status, data);
    }

    return data;
}
