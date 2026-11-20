import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    reload: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    router: inertia,
}));

import History from '../../resources/js/pages/generations/History.vue';

type HistoryGeneration = {
    id: number;
    prompt: string;
    status: string;
    submission_status: string;
    image_size: string;
    quality: string;
    images: string[];
    estimated_charge_usd: string | null;
    charge_usd: string | null;
    images_pending: boolean;
    created_at: string;
};

const wrappers: VueWrapper[] = [];

function generation(
    status: string,
    overrides: Partial<HistoryGeneration> = {},
): HistoryGeneration {
    return {
        id: 1,
        prompt: 'History prompt',
        status,
        submission_status: 'accepted',
        image_size: '1024x1024',
        quality: 'medium',
        images: [],
        estimated_charge_usd: '0.086268751',
        charge_usd: null,
        images_pending: false,
        created_at: '2026-11-20T08:00:00Z',
        ...overrides,
    };
}

function page(data: HistoryGeneration[], currentPage = 2) {
    return {
        data,
        links: [
            { url: '/generations?page=1', label: '1', active: false },
            { url: '/generations?page=2', label: '2', active: true },
        ],
        current_page: currentPage,
        last_page: 2,
        total: 13,
    };
}

function mountHistory(data: HistoryGeneration[]): VueWrapper {
    const wrapper = mount(History, {
        props: { generations: page(data) },
    });
    wrappers.push(wrapper);
    return wrapper;
}

async function flush(): Promise<void> {
    await flushPromises();
    await nextTick();
}

beforeEach(() => {
    vi.useFakeTimers();
    inertia.reload.mockReset();
});

afterEach(() => {
    for (const wrapper of wrappers.splice(0)) wrapper.unmount();
    vi.useRealTimers();
});

describe('Generation History', () => {
    it('displays estimated, settled, pending, failed, and review charges', () => {
        const wrapper = mountHistory([
            generation('completed', { charge_usd: '0.059450003' }),
            generation('queued', { id: 2 }),
            generation('failed', { id: 3 }),
            generation('processing', {
                id: 4,
                submission_status: 'needs_review',
            }),
        ]);

        expect(wrapper.text()).toContain('Estimated cost');
        expect(wrapper.text()).toContain('$0.086268751');
        expect(wrapper.text()).toContain('Final charge');
        expect(wrapper.text()).toContain('$0.059450003');
        expect(wrapper.text()).toContain('Pending');
        expect(wrapper.text()).toContain('$0.00 — No charge');
        expect(wrapper.text()).toContain('Needs review');
    });

    it('partially reloads active history without overlapping and preserves state', async () => {
        const wrapper = mountHistory([
            generation('queued'),
            generation('failed', { id: 2 }),
        ]);
        const select = wrapper.find('select');
        await select.setValue('queued');
        let finish!: () => void;

        inertia.reload.mockImplementation((options) => {
            finish = options.onFinish;
        });

        await vi.advanceTimersByTimeAsync(4_000);

        expect(inertia.reload).toHaveBeenCalledTimes(1);
        expect(inertia.reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['generations'],
            }),
        );
        expect((select.element as HTMLSelectElement).value).toBe('queued');

        await vi.advanceTimersByTimeAsync(20_000);
        expect(inertia.reload).toHaveBeenCalledTimes(1);

        finish();
        await flush();
        await vi.advanceTimersByTimeAsync(4_000);
        expect(inertia.reload).toHaveBeenCalledTimes(2);
    });

    it('shows a completed image and stops polling when refreshed data is ready', async () => {
        const wrapper = mountHistory([
            generation('completed', { images_pending: true }),
        ]);

        inertia.reload.mockImplementation(async (options) => {
            await wrapper.setProps({
                generations: page([
                    generation('completed', {
                        images: ['/generations/1/images/0'],
                        charge_usd: '0.059450003',
                        images_pending: false,
                    }),
                ]),
            });
            options.onSuccess();
            options.onFinish();
        });

        await vi.advanceTimersByTimeAsync(4_000);
        await flush();

        expect(wrapper.find('img').attributes('src')).toBe(
            '/generations/1/images/0',
        );
        expect(wrapper.text()).toContain('$0.059450003');

        await vi.advanceTimersByTimeAsync(20_000);
        expect(inertia.reload).toHaveBeenCalledTimes(1);
    });

    it('preserves existing data after a network error and cleans up on unmount', async () => {
        const wrapper = mountHistory([generation('processing')]);

        inertia.reload.mockImplementation((options) => {
            options.onError({ network: 'offline' });
            options.onFinish();
        });

        await vi.advanceTimersByTimeAsync(4_000);
        expect(wrapper.text()).toContain('History prompt');

        wrapper.unmount();
        const callsAtUnmount = inertia.reload.mock.calls.length;
        await vi.advanceTimersByTimeAsync(20_000);
        expect(inertia.reload).toHaveBeenCalledTimes(callsAtUnmount);
    });

    it('does not poll terminal history with no pending images', async () => {
        mountHistory([generation('completed', { charge_usd: '0.01' })]);

        await vi.advanceTimersByTimeAsync(20_000);
        expect(inertia.reload).not.toHaveBeenCalled();
    });
});
