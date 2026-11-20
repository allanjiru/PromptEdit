import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
}));

import Studio from '../../resources/js/pages/studio/Index.vue';

const requestKey = '11111111-1111-4111-8111-111111111111';
const mountedWrappers: VueWrapper[] = [];
type Uuid = ReturnType<Crypto['randomUUID']>;
let randomUuidSpy: { mockReturnValueOnce: (value: Uuid) => unknown };

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function generationStatus(
    status: 'queued' | 'processing' | 'completed' | 'failed',
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        id: 9,
        status,
        submission_status: 'accepted',
        estimated_charge_usd: '0.086268750',
        customer_charge_usd: status === 'completed' ? '0.059450000' : null,
        result_image_urls: [],
        expected_image_count: 1,
        images_ready: false,
        reconciliation_paused: false,
        error_message: status === 'failed' ? 'Provider failed.' : null,
        completed_at:
            status === 'completed' ? '2026-11-19T12:04:14+00:00' : null,
        ...overrides,
    };
}

function submission(
    status = 'queued',
    id = 9,
    key = requestKey,
): Record<string, unknown> {
    return {
        id,
        request_key: key,
        status,
        estimated_charge_units: 86_268_750,
        estimated_charge_usd: '0.086268750',
    };
}

function quote(): Record<string, unknown> {
    return {
        estimated_charge_units: 86_268_750,
        estimated_charge_usd: '0.086268750',
        available_units: 1_000_000_000,
        available_usd: '1.000000000',
        reserved_units: 0,
        reserved_usd: '0.000000000',
        has_sufficient_balance: true,
    };
}

function activeLatest(status: 'queued' | 'processing' | 'completed') {
    return {
        generation: {
            ...generationStatus(status),
            request_key: requestKey,
            prompt: 'Restored prompt',
            image_size: '1024x1024',
            quality: 'medium',
            reference_image_urls: [],
            estimated_charge_units: 86_268_750,
        },
    };
}

function pendingUntilAbort(signal: AbortSignal | null | undefined) {
    return new Promise<Response>((_resolve, reject) => {
        signal?.addEventListener(
            'abort',
            () => reject(new DOMException('Aborted', 'AbortError')),
            { once: true },
        );
    });
}

async function flush(): Promise<void> {
    await flushPromises();
    await nextTick();
}

function mountStudio(): VueWrapper {
    const wrapper = mount(Studio);
    mountedWrappers.push(wrapper);
    return wrapper;
}

async function prepareSubmission(wrapper: VueWrapper): Promise<void> {
    await flush();
    await wrapper.find('textarea').setValue('A reliable generated image');
    await vi.advanceTimersByTimeAsync(450);
    await flush();
}

async function submitForm(wrapper: VueWrapper): Promise<void> {
    await wrapper.find('form').trigger('submit');
    await flush();
}

function fetchUrl(input: RequestInfo | URL): string {
    if (typeof input === 'string') return input;
    if (input instanceof URL) return input.href;
    return input.url;
}

beforeEach(() => {
    vi.useFakeTimers();
    randomUuidSpy = vi
        .spyOn(globalThis.crypto, 'randomUUID')
        .mockReturnValue(
            requestKey as `${string}-${string}-${string}-${string}-${string}`,
        );
});

afterEach(() => {
    for (const wrapper of mountedWrappers.splice(0)) {
        wrapper.unmount();
    }
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('Studio generation request lifecycle', () => {
    it('renders queued, processing, then completed and stops both spinners', async () => {
        const statuses = [
            generationStatus('queued'),
            generationStatus('processing'),
            generationStatus('completed'),
            generationStatus('completed', {
                result_image_urls: ['/generations/9/images/0'],
                expected_image_count: 1,
                images_ready: true,
            }),
        ];
        const fetchMock = vi.fn(
            async (input: RequestInfo | URL, init?: RequestInit) => {
                const url = fetchUrl(input);
                if (url === '/generations/latest')
                    return json({ generation: null });
                if (url === '/generations/quote') return json(quote());
                if (url === '/generations' && init?.method === 'POST') {
                    return json(submission(), 202);
                }
                if (url === '/generations/9') {
                    return json(statuses.shift());
                }
                throw new Error(`Unexpected request: ${url}`);
            },
        );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountStudio();

        await prepareSubmission(wrapper);
        await submitForm(wrapper);
        expect(wrapper.text()).toContain('Queued');
        expect(wrapper.findAll('.animate-spin')).toHaveLength(2);

        await vi.advanceTimersByTimeAsync(3_000);
        await flush();
        expect(wrapper.text()).toContain('Processing');

        await vi.advanceTimersByTimeAsync(3_000);
        await flush();
        expect(wrapper.text()).toContain('Completed');
        expect(wrapper.findAll('.animate-spin')).toHaveLength(1);
        expect(wrapper.text()).toContain('Preparing your image...');

        await vi.advanceTimersByTimeAsync(3_000);
        await flush();
        expect(wrapper.findAll('.animate-spin')).toHaveLength(0);
        expect(wrapper.find('img').attributes('src')).toBe(
            '/generations/9/images/0',
        );

        const callsAtCompletion = fetchMock.mock.calls.length;
        await vi.advanceTimersByTimeAsync(30_000);
        expect(fetchMock).toHaveBeenCalledTimes(callsAtCompletion);
    });

    it('times out submission, clears submitting, and recovers with the same key', async () => {
        const submittedBodies: Array<Record<string, unknown>> = [];
        let submissionAttempts = 0;
        const fetchMock = vi.fn(
            async (input: RequestInfo | URL, init?: RequestInit) => {
                const url = fetchUrl(input);
                if (url === '/generations/latest')
                    return json({ generation: null });
                if (url === '/generations/quote') return json(quote());
                if (url === '/generations' && init?.method === 'POST') {
                    if (typeof init.body !== 'string') {
                        throw new Error('Expected a JSON request body.');
                    }
                    submittedBodies.push(JSON.parse(init.body));
                    submissionAttempts++;
                    if (submissionAttempts === 1) {
                        return pendingUntilAbort(init.signal);
                    }
                    return json(submission('completed'), 202);
                }
                if (url === '/generations/9') {
                    return json(generationStatus('completed'));
                }
                throw new Error(`Unexpected request: ${url}`);
            },
        );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountStudio();

        await prepareSubmission(wrapper);
        await submitForm(wrapper);
        expect(wrapper.text()).toContain('Submitting...');

        await vi.advanceTimersByTimeAsync(30_000);
        await flush();
        expect(wrapper.text()).not.toContain('Submitting...');
        expect(wrapper.text()).toContain('Submission confirmation timed out');
        expect(wrapper.text()).toContain('Retry Submission');

        await submitForm(wrapper);
        expect(wrapper.text()).toContain('Completed');
        expect(submittedBodies).toHaveLength(2);
        expect(submittedBodies[0]).toEqual(submittedBodies[1]);
        expect(submittedBodies[1].request_key).toBe(requestKey);
    });

    it('treats a malformed accepted submission response as uncertain', async () => {
        let submittedRequestKey: string | undefined;
        const fetchMock = vi.fn(
            async (input: RequestInfo | URL, init?: RequestInit) => {
                const url = fetchUrl(input);
                if (url === '/generations/latest') {
                    return json({ generation: null });
                }
                if (url === '/generations/quote') return json(quote());
                if (url === '/generations' && init?.method === 'POST') {
                    if (typeof init.body !== 'string') {
                        throw new Error('Expected a JSON request body.');
                    }
                    submittedRequestKey = JSON.parse(init.body).request_key;
                    return json({ data: submission() }, 202);
                }
                throw new Error(`Unexpected request: ${url}`);
            },
        );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountStudio();

        await prepareSubmission(wrapper);
        await submitForm(wrapper);

        expect(wrapper.text()).toContain('invalid generation');
        expect(wrapper.text()).toContain('Retry Submission');
        expect(wrapper.text()).not.toContain('Submitting...');
        expect(submittedRequestKey).toBe(requestKey);
    });

    it('retries after a status timeout and accepts a later completed response', async () => {
        let statusAttempts = 0;
        const fetchMock = vi.fn(
            async (input: RequestInfo | URL, init?: RequestInit) => {
                const url = fetchUrl(input);
                if (url === '/generations/latest')
                    return json({ generation: null });
                if (url === '/generations/quote') return json(quote());
                if (url === '/generations' && init?.method === 'POST') {
                    return json(submission(), 202);
                }
                if (url === '/generations/9') {
                    statusAttempts++;
                    return statusAttempts === 1
                        ? pendingUntilAbort(init?.signal)
                        : json(
                              generationStatus('completed', {
                                  result_image_urls: [
                                      '/generations/9/images/0',
                                  ],
                                  expected_image_count: 1,
                                  images_ready: true,
                              }),
                          );
                }
                throw new Error(`Unexpected request: ${url}`);
            },
        );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountStudio();

        await prepareSubmission(wrapper);
        await submitForm(wrapper);
        await vi.advanceTimersByTimeAsync(10_000);
        await flush();
        expect(wrapper.text()).toContain('status request timed out');
        expect(wrapper.text()).toContain('Generating your image...');

        await vi.advanceTimersByTimeAsync(3_000);
        await flush();
        expect(statusAttempts).toBe(2);
        expect(wrapper.text()).toContain('Completed');
        expect(wrapper.text()).not.toContain('status request timed out');
        expect(wrapper.findAll('.animate-spin')).toHaveLength(0);
    });

    it.each([
        [
            'malformed JSON',
            new Response('{', {
                headers: { 'Content-Type': 'application/json' },
            }),
        ],
        [
            'HTML login response',
            new Response('<html>login</html>', {
                headers: { 'Content-Type': 'text/html' },
            }),
        ],
        ['wrapped payload', json({ data: generationStatus('completed') })],
    ])(
        'rejects an invalid %s status response and preserves the generation',
        async (_label, invalidResponse) => {
            const fetchMock = vi.fn(
                async (input: RequestInfo | URL, init?: RequestInit) => {
                    const url = fetchUrl(input);
                    if (url === '/generations/latest')
                        return json({ generation: null });
                    if (url === '/generations/quote') return json(quote());
                    if (url === '/generations' && init?.method === 'POST') {
                        return json(submission(), 202);
                    }
                    if (url === '/generations/9') return invalidResponse;
                    throw new Error(`Unexpected request: ${url}`);
                },
            );
            vi.stubGlobal('fetch', fetchMock);
            const wrapper = mountStudio();

            await prepareSubmission(wrapper);
            await submitForm(wrapper);
            expect(wrapper.text()).toContain('Generation #9');
            expect(wrapper.text()).toMatch(
                /invalid JSON|unexpected response|invalid generation/i,
            );
            expect(wrapper.text()).toContain('Generating your image...');
            wrapper.unmount();
        },
    );

    it('reset aborts a pending poll and prevents stale updates', async () => {
        let pollAborted = false;
        const fetchMock = vi.fn(
            async (input: RequestInfo | URL, init?: RequestInit) => {
                const url = fetchUrl(input);
                if (url === '/generations/latest')
                    return json({ generation: null });
                if (url === '/generations/quote') return json(quote());
                if (url === '/generations' && init?.method === 'POST') {
                    return json(submission(), 202);
                }
                if (url === '/generations/9') {
                    init?.signal?.addEventListener('abort', () => {
                        pollAborted = true;
                    });
                    return pendingUntilAbort(init?.signal);
                }
                throw new Error(`Unexpected request: ${url}`);
            },
        );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountStudio();

        await prepareSubmission(wrapper);
        await submitForm(wrapper);
        const newGeneration = wrapper
            .findAll('button')
            .find((button) => button.text().includes('New Generation'));
        await newGeneration?.trigger('click');
        await flush();

        expect(pollAborted).toBe(true);
        expect(wrapper.text()).not.toContain('Generation #9');
        const callsAfterReset = fetchMock.mock.calls.length;
        await vi.advanceTimersByTimeAsync(20_000);
        expect(fetchMock).toHaveBeenCalledTimes(callsAfterReset);
    });

    it('unmount aborts polling and schedules no further status request', async () => {
        let pollAborted = false;
        const fetchMock = vi.fn(
            async (input: RequestInfo | URL, init?: RequestInit) => {
                const url = fetchUrl(input);
                if (url === '/generations/latest')
                    return json({ generation: null });
                if (url === '/generations/quote') return json(quote());
                if (url === '/generations' && init?.method === 'POST') {
                    return json(submission(), 202);
                }
                if (url === '/generations/9') {
                    init?.signal?.addEventListener('abort', () => {
                        pollAborted = true;
                    });
                    return pendingUntilAbort(init?.signal);
                }
                throw new Error(`Unexpected request: ${url}`);
            },
        );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountStudio();

        await prepareSubmission(wrapper);
        await submitForm(wrapper);
        wrapper.unmount();
        await flush();

        expect(pollAborted).toBe(true);
        const callsAfterUnmount = fetchMock.mock.calls.length;
        await vi.advanceTimersByTimeAsync(20_000);
        expect(fetchMock).toHaveBeenCalledTimes(callsAfterUnmount);
    });

    it('bounds image-readiness checks and offers a local persistence retry', async () => {
        let statusAttempts = 0;
        let persistenceRetries = 0;
        const fetchMock = vi.fn(
            async (input: RequestInfo | URL, init?: RequestInit) => {
                const url = fetchUrl(input);
                if (url === '/generations/latest')
                    return json({ generation: null });
                if (url === '/generations/quote') return json(quote());
                if (url === '/generations' && init?.method === 'POST') {
                    return json(submission(), 202);
                }
                if (
                    url === '/generations/9/images/retry' &&
                    init?.method === 'POST'
                ) {
                    persistenceRetries++;
                    return json(generationStatus('completed'));
                }
                if (url === '/generations/9') {
                    statusAttempts++;
                    return json(generationStatus('completed'));
                }
                throw new Error(`Unexpected request: ${url}`);
            },
        );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountStudio();

        await prepareSubmission(wrapper);
        await submitForm(wrapper);
        await vi.advanceTimersByTimeAsync(60_000);
        await flush();

        expect(statusAttempts).toBe(20);
        expect(wrapper.text()).toContain(
            'Image preparation is taking longer than expected',
        );
        const callsAtBound = fetchMock.mock.calls.length;
        await vi.advanceTimersByTimeAsync(30_000);
        expect(fetchMock).toHaveBeenCalledTimes(callsAtBound);

        const retry = wrapper
            .findAll('button')
            .find((button) =>
                button.text().includes('Retry image preparation'),
            );
        await retry?.trigger('click');
        await flush();

        expect(persistenceRetries).toBe(1);
        expect(statusAttempts).toBe(21);
    });

    it('stops the active spinner when provider checking is paused', async () => {
        const fetchMock = vi.fn(
            async (input: RequestInfo | URL, init?: RequestInit) => {
                const url = fetchUrl(input);
                if (url === '/generations/latest')
                    return json({ generation: null });
                if (url === '/generations/quote') return json(quote());
                if (url === '/generations' && init?.method === 'POST') {
                    return json(submission(), 202);
                }
                if (url === '/generations/9') {
                    return json(
                        generationStatus('processing', {
                            reconciliation_paused: true,
                        }),
                    );
                }
                throw new Error(`Unexpected request: ${url}`);
            },
        );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountStudio();

        await prepareSubmission(wrapper);
        await submitForm(wrapper);

        expect(wrapper.text()).toContain('Checking paused');
        expect(wrapper.text()).toContain('The generation remains accepted');
        expect(wrapper.text()).toContain('Refresh status');
        expect(wrapper.findAll('.animate-spin')).toHaveLength(0);
    });

    it('reset and unmount cancel image-readiness polling', async () => {
        const fetchMock = vi.fn(
            async (input: RequestInfo | URL, init?: RequestInit) => {
                const url = fetchUrl(input);
                if (url === '/generations/latest')
                    return json({ generation: null });
                if (url === '/generations/quote') return json(quote());
                if (url === '/generations' && init?.method === 'POST') {
                    return json(submission(), 202);
                }
                if (url === '/generations/9') {
                    return json(generationStatus('completed'));
                }
                throw new Error(`Unexpected request: ${url}`);
            },
        );
        vi.stubGlobal('fetch', fetchMock);
        const resetWrapper = mountStudio();

        await prepareSubmission(resetWrapper);
        await submitForm(resetWrapper);
        expect(resetWrapper.text()).toContain('Preparing your image...');
        const newGeneration = resetWrapper
            .findAll('button')
            .find((button) => button.text().includes('New Generation'));
        await newGeneration?.trigger('click');
        const callsAfterReset = fetchMock.mock.calls.length;
        await vi.advanceTimersByTimeAsync(10_000);
        expect(fetchMock).toHaveBeenCalledTimes(callsAfterReset);

        const unmountWrapper = mountStudio();
        await prepareSubmission(unmountWrapper);
        await submitForm(unmountWrapper);
        expect(unmountWrapper.text()).toContain('Preparing your image...');
        unmountWrapper.unmount();
        const callsAfterUnmount = fetchMock.mock.calls.length;
        await vi.advanceTimersByTimeAsync(10_000);
        expect(fetchMock).toHaveBeenCalledTimes(callsAfterUnmount);
    });

    it('does not let an old polling response overwrite a newer generation', async () => {
        const secondRequestKey = '22222222-2222-4222-8222-222222222222';
        let resolveOldPoll!: (response: Response) => void;
        const oldPoll = new Promise<Response>((resolve) => {
            resolveOldPoll = resolve;
        });
        let postAttempts = 0;
        const fetchMock = vi.fn(
            async (input: RequestInfo | URL, init?: RequestInit) => {
                const url = fetchUrl(input);
                if (url === '/generations/latest')
                    return json({ generation: null });
                if (url === '/generations/quote') return json(quote());
                if (url === '/generations' && init?.method === 'POST') {
                    postAttempts++;
                    return postAttempts === 1
                        ? json(submission(), 202)
                        : json(submission('queued', 10, secondRequestKey), 202);
                }
                if (url === '/generations/9') return oldPoll;
                if (url === '/generations/10') {
                    return json(generationStatus('processing', { id: 10 }));
                }
                throw new Error(`Unexpected request: ${url}`);
            },
        );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountStudio();

        await prepareSubmission(wrapper);
        await submitForm(wrapper);
        const newGeneration = wrapper
            .findAll('button')
            .find((button) => button.text().includes('New Generation'));
        await newGeneration?.trigger('click');
        randomUuidSpy.mockReturnValueOnce(
            secondRequestKey as `${string}-${string}-${string}-${string}-${string}`,
        );
        await wrapper.find('textarea').setValue('A newer generation');
        await vi.advanceTimersByTimeAsync(450);
        await flush();
        await submitForm(wrapper);
        expect(wrapper.text()).toContain('Generation #10');
        expect(wrapper.text()).toContain('Processing');

        resolveOldPoll(json(generationStatus('completed')));
        await flush();
        expect(wrapper.text()).toContain('Generation #10');
        expect(wrapper.text()).toContain('Processing');
        expect(wrapper.text()).not.toContain('Completed');
    });
});

describe('Studio restoration behavior', () => {
    it('restores and polls an active generation', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const url = fetchUrl(input);
            if (url === '/generations/latest')
                return json(activeLatest('processing'));
            if (url === '/generations/9') {
                return json(generationStatus('completed'));
            }
            throw new Error(`Unexpected request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountStudio();

        await flush();
        expect(wrapper.text()).toContain('Generation #9');
        expect(wrapper.text()).toContain('Completed');
    });

    it('does not automatically restore a completed generation', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => json(activeLatest('completed'))),
        );
        const wrapper = mountStudio();

        await flush();
        expect(wrapper.text()).not.toContain('Generation #9');
        expect(wrapper.text()).toContain('No image to preview yet.');
    });

    it('preserves edits made while restoration is pending', async () => {
        let resolveLatest!: (response: Response) => void;
        const latest = new Promise<Response>((resolve) => {
            resolveLatest = resolve;
        });
        vi.stubGlobal(
            'fetch',
            vi.fn(async (input: RequestInfo | URL) => {
                if (fetchUrl(input) === '/generations/latest') return latest;
                if (fetchUrl(input) === '/generations/quote')
                    return json(quote());
                throw new Error(`Unexpected request: ${fetchUrl(input)}`);
            }),
        );
        const wrapper = mountStudio();

        await wrapper.find('textarea').setValue('My newer edit');
        resolveLatest(json(activeLatest('processing')));
        await flush();

        expect(
            (wrapper.find('textarea').element as HTMLTextAreaElement).value,
        ).toBe('My newer edit');
        expect(wrapper.text()).not.toContain('Generation #9');
    });

    it('restores every reference URL for an active generation', async () => {
        const restored = activeLatest('processing');
        restored.generation.reference_image_urls = [
            'https://example.com/one.png',
            'https://example.com/two.png',
        ];
        vi.stubGlobal(
            'fetch',
            vi.fn(async (input: RequestInfo | URL) => {
                const url = fetchUrl(input);
                if (url === '/generations/latest') return json(restored);
                if (url === '/generations/9') {
                    return json(generationStatus('processing'));
                }
                throw new Error(`Unexpected request: ${url}`);
            }),
        );
        const wrapper = mountStudio();

        await flush();

        const referenceInputs = wrapper.findAll('input[type="url"]');
        expect(referenceInputs).toHaveLength(2);
        expect(
            referenceInputs.map(
                (input) => (input.element as HTMLInputElement).value,
            ),
        ).toEqual([
            'https://example.com/one.png',
            'https://example.com/two.png',
        ]);
    });
});

describe('Studio reference images', () => {
    it('adds, removes, quotes, and submits multiple reference URLs', async () => {
        const quoteBodies: Array<Record<string, unknown>> = [];
        let submissionBody: Record<string, unknown> | null = null;
        const fetchMock = vi.fn(
            async (input: RequestInfo | URL, init?: RequestInit) => {
                const url = fetchUrl(input);
                if (url === '/generations/latest') {
                    return json({ generation: null });
                }
                if (url === '/generations/quote') {
                    if (typeof init?.body !== 'string') {
                        throw new Error('Expected a JSON quote body.');
                    }
                    quoteBodies.push(JSON.parse(init.body));
                    return json(quote());
                }
                if (url === '/generations' && init?.method === 'POST') {
                    if (typeof init.body !== 'string') {
                        throw new Error('Expected a JSON submission body.');
                    }
                    submissionBody = JSON.parse(init.body);
                    return json(submission(), 202);
                }
                if (url === '/generations/9') {
                    return json(generationStatus('failed'));
                }
                throw new Error(`Unexpected request: ${url}`);
            },
        );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountStudio();

        await flush();
        await wrapper.find('textarea').setValue('Multiple references');
        let inputs = wrapper.findAll('input[type="url"]');
        await inputs[0].setValue('https://example.com/one.png');

        const addReference = wrapper
            .findAll('button')
            .find((button) => button.text().includes('Add reference'));
        await addReference?.trigger('click');
        inputs = wrapper.findAll('input[type="url"]');
        expect(inputs).toHaveLength(2);
        await inputs[1].setValue('https://example.com/two.png');

        await vi.advanceTimersByTimeAsync(450);
        await flush();
        expect(quoteBodies.at(-1)?.reference_image_urls).toEqual([
            'https://example.com/one.png',
            'https://example.com/two.png',
        ]);

        await submitForm(wrapper);
        expect(submissionBody?.reference_image_urls).toEqual(
            quoteBodies.at(-1)?.reference_image_urls,
        );

        const newGeneration = wrapper
            .findAll('button')
            .find((button) => button.text().includes('New Generation'));
        await newGeneration?.trigger('click');
        await flush();
        expect(wrapper.findAll('input[type="url"]')).toHaveLength(1);

        await wrapper
            .find('input[type="url"]')
            .setValue('https://example.com/remove.png');
        const removeReference = wrapper
            .findAll('button')
            .find((button) =>
                button.attributes('aria-label')?.includes('Remove reference'),
            );
        await removeReference?.trigger('click');
        expect(
            (wrapper.find('input[type="url"]').element as HTMLInputElement)
                .value,
        ).toBe('');
    });

    it('enforces the documented maximum of four reference fields', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async (input: RequestInfo | URL) => {
                if (fetchUrl(input) === '/generations/latest') {
                    return json({ generation: null });
                }
                throw new Error(`Unexpected request: ${fetchUrl(input)}`);
            }),
        );
        const wrapper = mountStudio();
        await flush();

        const addReference = () =>
            wrapper
                .findAll('button')
                .find((button) => button.text().includes('Add reference'));

        await addReference()?.trigger('click');
        await addReference()?.trigger('click');
        await addReference()?.trigger('click');

        expect(wrapper.findAll('input[type="url"]')).toHaveLength(4);
        expect(addReference()?.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('up to 4 URLs');
    });
});
