<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

type Generation = {
    id: number;
    prompt: string;
    status: string;
    image_size: string;
    quality: string;
    images: string[];
    submission_status: string;
    estimated_charge_usd: string | null;
    charge_usd: string | null;
    images_pending: boolean;
    created_at: string;
};

type PageLink = {
    url: string | null;
    label: string;
    active: boolean;
};

const props = defineProps<{
    generations: {
        data: Generation[];
        links: PageLink[];
        current_page: number;
        last_page: number;
        total: number;
    };
}>();

const statusFilter = ref('all');
const HISTORY_POLL_INTERVAL_MS = 4_000;
const MAX_IMAGE_READINESS_POLLS = 20;
let pollTimer: ReturnType<typeof setTimeout> | null = null;
let pollInFlight = false;
let imageReadinessPolls = 0;
let disposed = false;

const filtered = computed(() => {
    if (statusFilter.value === 'all') {
        return props.generations.data;
    }

    return props.generations.data.filter(
        (generation) => generation.status === statusFilter.value,
    );
});

function formatDate(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function finalCharge(generation: Generation): string {
    if (generation.status === 'failed') {
        return '$0.00 — No charge';
    }

    if (generation.submission_status === 'needs_review') {
        return 'Needs review';
    }

    if (generation.status !== 'completed' || generation.charge_usd === null) {
        return 'Pending';
    }

    return `$${generation.charge_usd}`;
}

function hasActiveGeneration(): boolean {
    return props.generations.data.some(
        (generation) =>
            (generation.status === 'queued' ||
                generation.status === 'processing') &&
            generation.submission_status !== 'needs_review',
    );
}

function hasImagePendingGeneration(): boolean {
    return props.generations.data.some(
        (generation) => generation.images_pending,
    );
}

function shouldPoll(): boolean {
    return (
        hasActiveGeneration() ||
        (hasImagePendingGeneration() &&
            imageReadinessPolls < MAX_IMAGE_READINESS_POLLS)
    );
}

function schedulePoll(): void {
    if (disposed || pollTimer !== null || pollInFlight || !shouldPoll()) {
        return;
    }

    pollTimer = setTimeout(() => {
        pollTimer = null;
        pollHistory();
    }, HISTORY_POLL_INTERVAL_MS);
}

function pollHistory(): void {
    if (disposed || pollInFlight || !shouldPoll()) {
        return;
    }

    pollInFlight = true;
    router.reload({
        only: ['generations'],
        // Inertia keeps the current props when a background visit fails.
        onError: () => {},
        onSuccess: () => {
            if (hasImagePendingGeneration()) {
                imageReadinessPolls++;
            } else {
                imageReadinessPolls = 0;
            }
        },
        onFinish: () => {
            pollInFlight = false;
            schedulePoll();
        },
    });
}

onMounted(schedulePoll);

onBeforeUnmount(() => {
    disposed = true;

    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
});
</script>

<template>
    <Head title="Generation History" />

    <div class="mx-auto max-w-7xl px-6 py-10">
        <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold tracking-tight">
                    Generation History
                </h1>

                <p class="mt-2 text-sm text-muted-foreground">
                    Your previously generated images and prompts.
                </p>
            </div>

            <Link
                href="/studio"
                class="inline-flex items-center rounded-xl border border-border px-4 py-2 text-sm font-medium hover:bg-muted"
            >
                Create image
            </Link>
        </div>

        <div class="mb-6 flex items-center justify-between gap-4">
            <p class="text-sm text-muted-foreground">
                {{ generations.total }} generations
            </p>

            <select
                v-model="statusFilter"
                class="rounded-lg border border-border bg-background px-3 py-2 text-sm"
            >
                <option value="all">All statuses</option>
                <option value="completed">Completed</option>
                <option value="processing">Processing</option>
                <option value="queued">Queued</option>
                <option value="failed">Failed</option>
            </select>
        </div>

        <div
            v-if="filtered.length === 0"
            class="rounded-2xl border border-dashed border-border px-6 py-20 text-center"
        >
            <h2 class="text-lg font-medium">No generations found</h2>

            <p class="mt-2 text-sm text-muted-foreground">
                Create an image to start building your history.
            </p>
        </div>

        <div v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <article
                v-for="generation in filtered"
                :key="generation.id"
                class="overflow-hidden rounded-2xl border border-border bg-card"
            >
                <div
                    class="flex aspect-square items-center justify-center bg-muted"
                >
                    <a
                        v-if="generation.images.length > 0"
                        :href="generation.images[0]"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="block h-full w-full"
                    >
                        <img
                            :src="generation.images[0]"
                            :alt="generation.prompt"
                            loading="lazy"
                            class="h-full w-full object-cover"
                        />
                    </a>

                    <div
                        v-else
                        class="px-6 text-center text-sm text-muted-foreground"
                    >
                        {{
                            generation.status === 'completed'
                                ? 'Image unavailable'
                                : generation.status === 'failed'
                                  ? 'Generation failed'
                                  : 'Generation in progress'
                        }}
                    </div>
                </div>

                <div class="space-y-4 p-5">
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="rounded-full bg-muted px-3 py-1 text-xs font-medium capitalize"
                        >
                            {{ generation.status }}
                        </span>

                        <span class="text-xs text-muted-foreground">
                            #{{ generation.id }}
                        </span>
                    </div>

                    <p class="line-clamp-3 min-h-15 text-sm leading-6">
                        {{ generation.prompt }}
                    </p>

                    <div
                        class="flex flex-wrap gap-2 text-xs text-muted-foreground"
                    >
                        <span>{{ generation.image_size }}</span>
                        <span>·</span>
                        <span class="capitalize">
                            {{ generation.quality }}
                        </span>
                    </div>

                    <div class="space-y-2 border-t border-border pt-4 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs text-muted-foreground">
                                Estimated cost
                            </span>
                            <span class="font-medium tabular-nums">
                                {{
                                    generation.estimated_charge_usd !== null
                                        ? `$${generation.estimated_charge_usd}`
                                        : '—'
                                }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs text-muted-foreground">
                                Final charge
                            </span>
                            <span class="text-right font-semibold tabular-nums">
                                {{ finalCharge(generation) }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between pt-2">
                            <span class="text-xs text-muted-foreground">
                                {{ formatDate(generation.created_at) }}
                            </span>
                        </div>
                    </div>
                </div>
            </article>
        </div>

        <nav
            v-if="generations.last_page > 1"
            aria-label="Generation history pages"
            class="mt-10 flex flex-wrap justify-center gap-2"
        >
            <template v-for="(link, index) in generations.links" :key="index">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-lg border px-3 py-2 text-sm"
                    :class="
                        link.active
                            ? 'border-blue-600 bg-blue-600 text-white'
                            : 'border-border hover:bg-muted'
                    "
                    v-html="link.label"
                />

                <span
                    v-else
                    class="rounded-lg border border-border px-3 py-2 text-sm opacity-40"
                    v-html="link.label"
                />
            </template>
        </nav>
    </div>
</template>
