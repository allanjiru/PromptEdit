export function csrfHeaders(): Record<string, string> {
    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='));

    if (cookie) {
        try {
            return {
                'X-XSRF-TOKEN': decodeURIComponent(
                    cookie.substring('XSRF-TOKEN='.length),
                ),
            };
        } catch {
            // Fall through to the page token when a cookie is malformed.
        }
    }

    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    return token ? { 'X-CSRF-TOKEN': token } : {};
}
