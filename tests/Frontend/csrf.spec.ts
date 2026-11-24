import { afterEach, describe, expect, it } from 'vitest';

import { csrfHeaders } from '../../resources/js/lib/csrf';

afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/';
    document.head.innerHTML = '';
});

describe('CSRF request headers', () => {
    it('prefers the refreshed cookie after an Inertia login', () => {
        document.head.innerHTML =
            '<meta name="csrf-token" content="stale-session-token">';
        document.cookie = 'XSRF-TOKEN=fresh%20session%20token; path=/';

        expect(csrfHeaders()).toEqual({
            'X-XSRF-TOKEN': 'fresh session token',
        });
    });

    it('uses the page token when no XSRF cookie is available', () => {
        document.head.innerHTML =
            '<meta name="csrf-token" content="page-token">';

        expect(csrfHeaders()).toEqual({
            'X-CSRF-TOKEN': 'page-token',
        });
    });
});
