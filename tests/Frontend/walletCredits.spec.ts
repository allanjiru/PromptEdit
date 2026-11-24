import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import WalletCredits from '../../resources/js/components/WalletCredits.vue';
import Studio from '../../resources/js/pages/studio/Index.vue';
import type { DemoCreditConfig, WalletSummary } from '../../resources/js/types';

const requestKey = '22222222-2222-4222-8222-222222222222';
const wrappers: VueWrapper[] = [];

const emptyWallet: WalletSummary = {
    available_units: 0,
    available_usd: '0.000000000',
    reserved_units: 0,
    reserved_usd: '0.000000000',
};

const demoCredits: DemoCreditConfig = {
    enabled: true,
    packages: [
        {
            id: 'starter',
            label: 'Starter',
            amount_units: 5_000_000_000,
            amount_usd: '5.000000000',
        },
        {
            id: 'standard',
            label: 'Standard',
            amount_units: 10_000_000_000,
            amount_usd: '10.000000000',
        },
        {
            id: 'plus',
            label: 'Plus',
            amount_units: 20_000_000_000,
            amount_usd: '20.000000000',
        },
    ],
};

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function purchasedWallet(): WalletSummary {
    return {
        available_units: 5_000_000_000,
        available_usd: '5.000000000',
        reserved_units: 0,
        reserved_usd: '0.000000000',
    };
}

function purchaseResponse(): Response {
    return json({
        transaction_id: 1,
        package: 'starter',
        credited_units: 5_000_000_000,
        credited_usd: '5.000000000',
        wallet: purchasedWallet(),
    });
}

function fetchUrl(input: RequestInfo | URL): string {
    if (typeof input === 'string') return input;
    if (input instanceof URL) return input.href;

    return input.url;
}

function mountWallet(): VueWrapper {
    const wrapper = mount(WalletCredits, {
        props: { wallet: emptyWallet, demoCredits },
    });
    wrappers.push(wrapper);
    return wrapper;
}

async function flush(): Promise<void> {
    await flushPromises();
    await nextTick();
}

async function selectStarter(wrapper: VueWrapper): Promise<void> {
    await wrapper.get('[data-testid="buy-credits"]').trigger('click');
    await wrapper
        .get('[data-testid="credit-package-starter"]')
        .trigger('click');
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.spyOn(globalThis.crypto, 'randomUUID').mockReturnValue(
        requestKey as `${string}-${string}-${string}-${string}-${string}`,
    );
});

afterEach(() => {
    for (const wrapper of wrappers.splice(0)) wrapper.unmount();
    vi.useRealTimers();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('WalletCredits', () => {
    it('shows the balance, Buy Credits action, and all fixed packages', async () => {
        const wrapper = mountWallet();

        expect(wrapper.text()).toContain('$0.00');
        expect(wrapper.text()).toContain('No card is needed');
        expect(wrapper.get('[data-testid="buy-credits"]').text()).toContain(
            'Buy Credits',
        );

        await wrapper.get('[data-testid="buy-credits"]').trigger('click');

        expect(wrapper.text()).toContain('Starter');
        expect(wrapper.text()).toContain('Standard');
        expect(wrapper.text()).toContain('Plus');
        expect(wrapper.text()).toContain('$20.00');
    });

    it('requires package selection and confirmation before purchasing', async () => {
        const fetchMock = vi.fn(async () => purchaseResponse());
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountWallet();

        await wrapper.get('[data-testid="buy-credits"]').trigger('click');
        expect(
            wrapper.find('[data-testid="confirm-credit-purchase"]').exists(),
        ).toBe(false);

        await wrapper
            .get('[data-testid="credit-package-starter"]')
            .trigger('click');
        await wrapper
            .get('[data-testid="confirm-credit-purchase"]')
            .trigger('click');
        await flush();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const submittedBody = fetchMock.mock.calls[0][1]?.body;
        if (typeof submittedBody !== 'string') {
            throw new Error('Expected a JSON request body.');
        }
        expect(JSON.parse(submittedBody)).toEqual({
            package: 'starter',
            request_key: requestKey,
        });
    });

    it('creates a valid request key when randomUUID is unavailable', async () => {
        vi.stubGlobal('crypto', {
            getRandomValues: (bytes: Uint8Array) => bytes.fill(1),
        });
        const fetchMock = vi.fn(async () => purchaseResponse());
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountWallet();

        await selectStarter(wrapper);
        await wrapper
            .get('[data-testid="confirm-credit-purchase"]')
            .trigger('click');
        await flush();

        const submittedBody = fetchMock.mock.calls[0][1]?.body;
        if (typeof submittedBody !== 'string') {
            throw new Error('Expected a JSON request body.');
        }

        expect(JSON.parse(submittedBody).request_key).toBe(
            '01010101-0101-4101-8101-010101010101',
        );
    });

    it('disables purchase controls while loading and prevents double submission', async () => {
        let resolve!: (response: Response) => void;
        vi.stubGlobal(
            'fetch',
            vi.fn(
                () =>
                    new Promise<Response>((done) => {
                        resolve = done;
                    }),
            ),
        );
        const wrapper = mountWallet();
        await selectStarter(wrapper);
        const confirm = wrapper.get('[data-testid="confirm-credit-purchase"]');

        await confirm.trigger('click');
        await confirm.trigger('click');

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(confirm.attributes('disabled')).toBeDefined();
        expect(confirm.text()).toContain('Adding credits...');

        resolve(purchaseResponse());
        await flush();
        expect(wrapper.emitted('purchased')).toHaveLength(1);
    });

    it('emits the updated balance and confirms no real payment', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => purchaseResponse()),
        );
        const wrapper = mountWallet();
        await selectStarter(wrapper);
        await wrapper
            .get('[data-testid="confirm-credit-purchase"]')
            .trigger('click');
        await flush();

        expect(wrapper.emitted('purchased')?.[0]).toEqual([purchasedWallet()]);
        expect(wrapper.text()).toContain('$5.00 in demo credits added');
        expect(wrapper.text()).toContain('No real payment was made');
    });

    it('displays a failed purchase without changing the wallet', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => json({ message: 'Demo limit reached.' }, 422)),
        );
        const wrapper = mountWallet();
        await selectStarter(wrapper);
        await wrapper
            .get('[data-testid="confirm-credit-purchase"]')
            .trigger('click');
        await flush();

        expect(wrapper.get('[role="alert"]').text()).toContain(
            'Demo limit reached.',
        );
        expect(wrapper.emitted('purchased')).toBeUndefined();
        expect(wrapper.text()).toContain('$0.00');
    });

    it('shows server failures and preserves a timed-out request key for retry', async () => {
        let attempts = 0;
        const bodies: Array<Record<string, string>> = [];
        vi.stubGlobal(
            'fetch',
            vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
                if (typeof init?.body !== 'string') {
                    throw new Error('Expected a JSON request body.');
                }
                bodies.push(JSON.parse(init.body));
                attempts++;
                if (attempts === 1) {
                    return new Promise<Response>((_resolve, reject) => {
                        init?.signal?.addEventListener('abort', () => {
                            reject(new DOMException('Aborted', 'AbortError'));
                        });
                    });
                }
                return attempts === 2
                    ? purchaseResponse()
                    : json({ message: 'Demo limit reached.' }, 422);
            }),
        );
        const wrapper = mountWallet();
        await selectStarter(wrapper);
        await wrapper
            .get('[data-testid="confirm-credit-purchase"]')
            .trigger('click');
        await vi.advanceTimersByTimeAsync(10_000);
        await flush();

        expect(wrapper.text()).toContain('confirmation timed out');
        await wrapper
            .get('[data-testid="confirm-credit-purchase"]')
            .trigger('click');
        await flush();

        expect(bodies[0].request_key).toBe(requestKey);
        expect(bodies[1].request_key).toBe(requestKey);
    });

    it('keeps responsive and dark-theme-compatible utility markup', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => purchaseResponse()),
        );
        const wrapper = mountWallet();
        await selectStarter(wrapper);
        await wrapper
            .get('[data-testid="confirm-credit-purchase"]')
            .trigger('click');
        await flush();
        expect(wrapper.get('section').classes()).toContain('bg-muted/20');
        expect(wrapper.get('section').html()).toContain('sm:grid-cols-2');
        expect(wrapper.get('section').html()).toContain('dark:text-green-400');
    });
});

describe('Studio wallet integration', () => {
    it('preserves form state, refreshes affordability, and enables generation after funding', async () => {
        let funded = false;
        vi.stubGlobal(
            'fetch',
            vi.fn(async (input: RequestInfo | URL) => {
                const url = fetchUrl(input);
                if (url === '/generations/latest')
                    return json({ generation: null });
                if (url === '/wallet/demo-credits') {
                    funded = true;
                    return purchaseResponse();
                }
                if (url === '/generations/quote') {
                    return json({
                        estimated_charge_units: 86_268_750,
                        estimated_charge_usd: '0.086268750',
                        available_units: funded ? 5_000_000_000 : 0,
                        available_usd: funded ? '5.000000000' : '0.000000000',
                        reserved_units: 0,
                        reserved_usd: '0.000000000',
                        has_sufficient_balance: funded,
                    });
                }
                throw new Error(`Unexpected request: ${url}`);
            }),
        );
        const wrapper = mount(Studio, {
            props: { wallet: emptyWallet, demoCredits },
        });
        wrappers.push(wrapper);
        await flush();
        await wrapper.get('textarea').setValue('Keep this exact prompt');
        await vi.advanceTimersByTimeAsync(450);
        await flush();

        const generate = wrapper
            .findAll('button')
            .find((button) => button.text().includes('Generate Image'))!;
        expect(generate.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Generate is unavailable');

        await wrapper.get('[data-testid="buy-credits"]').trigger('click');
        await wrapper
            .get('[data-testid="credit-package-starter"]')
            .trigger('click');
        await wrapper
            .get('[data-testid="confirm-credit-purchase"]')
            .trigger('click');
        await flush();

        expect(
            (wrapper.get('textarea').element as HTMLTextAreaElement).value,
        ).toBe('Keep this exact prompt');
        expect(generate.attributes('disabled')).toBeUndefined();
        expect(wrapper.text()).toContain('$5.00');
    });
});
