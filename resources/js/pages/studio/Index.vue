
<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    ref,
    watch,
} from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    AlertCircle,
    CheckCircle2,
    Download,
    Image as ImageIcon,
    LoaderCircle,
    RefreshCw,
    Sparkles,
    Wallet,
} from '@lucide/vue';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as studioIndex } from '@/routes/studio';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Create Image',
                href: studioIndex(),
            },
        ],
    },
});

type ImageSize =
    | '1024x1024'
    | '1536x1024'
    | '1024x1536';

type Quality = 'low' | 'medium' | 'high';

type ValidationErrors = Record<string, string[]>;

type Quote = {
    estimated_charge_units: number;
    estimated_charge_usd: string;
    available_units: number;
    available_usd: string;
    reserved_units: number;
    reserved_usd: string;
    has_sufficient_balance: boolean;
};

type GenerationResponse = {
    id: number;
    request_key: string;
    status: string;
    estimated_charge_units: number;
    estimated_charge_usd: string;
};

type GenerationStatus = {
    id: number;
    status: string;
    submission_status: string;
    estimated_charge_usd: string;
    customer_charge_usd: string | null;
    result_image_urls: string[];
    error_message: string | null;
    completed_at: string | null;
};

const prompt = ref('');
const imageSize = ref<ImageSize>('1024x1024');
const quality = ref<Quality>('medium');
const referenceUrl = ref('');

const quote = ref<Quote | null>(null);
const isQuoting = ref(false);
const quoteError = ref('');

const isSubmitting = ref(false);
const errors = ref<ValidationErrors>({});
const submissionError = ref('');

const result = ref<GenerationResponse | null>(null);
const generationStatus = ref<GenerationStatus | null>(null);
const pollingError = ref('');

const requestKey = ref<string | null>(null);
const uncertainSubmission = ref(false);

let quoteTimer: ReturnType<typeof setTimeout> | null = null;
let pollTimer: ReturnType<typeof setTimeout> | null = null;

let quoteController: AbortController | null = null;
let statusController: AbortController | null = null;
let submitController: AbortController | null = null;

let quoteVersion = 0;
let formVersion = 0;
let pollVersion = 0;
let disposed = false;

const promptLength = computed(() => prompt.value.length);

const normalizedPrompt = computed(() => prompt.value.trim());
const normalizedReference = computed(
    () => referenceUrl.value.trim(),
);

const referenceUrls = computed(() =>
    normalizedReference.value
        ? [normalizedReference.value]
        : [],
);

const hasValidForm = computed(() =>
    normalizedPrompt.value.length > 0 &&
    promptLength.value <= 5000 &&
    (
        !normalizedReference.value ||
        isValidUrl(normalizedReference.value)
    ),
);

const isTerminal = computed(() =>
    generationStatus.value?.status === 'completed' ||
    generationStatus.value?.status === 'failed',
);

const isProcessing = computed(() =>
    !!result.value && !isTerminal.value,
);

const canSubmit = computed(() =>
    hasValidForm.value &&
    !isSubmitting.value &&
    !result.value &&
    (
        uncertainSubmission.value ||
        (
            !!quote.value &&
            quote.value.has_sufficient_balance &&
            !isQuoting.value &&
            !quoteError.value
        )
    ),
);

const images = computed(
    () => generationStatus.value?.result_image_urls ?? [],
);

const displayStatus = computed(() => {
    if (!result.value) {
        return 'Not started';
    }

    if (generationStatus.value?.status === 'completed') {
        return 'Completed';
    }

    if (generationStatus.value?.status === 'failed') {
        return 'Failed';
    }

    if (generationStatus.value?.status === 'processing') {
        return 'Processing';
    }

    if (
        generationStatus.value?.submission_status ===
        'needs_review'
    ) {
        return 'Needs review';
    }

    return 'Queued';
});

function isValidUrl(value: string): boolean {
    try {
        const url = new URL(value);

        return (
            url.protocol === 'http:' ||
            url.protocol === 'https:'
        );
    } catch {
        return false;
    }
}

function formatUsd(value: string | number): string {
    const amount = Number(value);

    if (!Number.isFinite(amount)) {
        return '—';
    }

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
    }).format(amount);
}

function csrfHeaders(): Record<string, string> {
    const meta = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    );

    const token = meta?.content;

    if (token) {
        return { 'X-CSRF-TOKEN': token };
    }

    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='));

    if (cookie) {
        return {
            'X-XSRF-TOKEN': decodeURIComponent(
                cookie.substring('XSRF-TOKEN='.length),
            ),
        };
    }

    return {};
}

function requestHeaders(): Record<string, string> {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...csrfHeaders(),
    };
}

async function readJson(response: Response): Promise<unknown> {
    const contentType =
        response.headers.get('content-type') ?? '';

    if (!contentType.includes('application/json')) {
        throw new Error(
            'The server returned an unexpected response.',
        );
    }

    return response.json();
}

function errorMessage(
    body: unknown,
    fallback: string,
): string {
    if (
        typeof body === 'object' &&
        body !== null &&
        'message' in body &&
        typeof body.message === 'string'
    ) {
        return body.message;
    }

    return fallback;
}

function stopPolling(): void {
    pollVersion++;

    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }

    statusController?.abort();
    statusController = null;
}

function cancelQuote(): void {
    quoteVersion++;

    if (quoteTimer !== null) {
        clearTimeout(quoteTimer);
        quoteTimer = null;
    }

    quoteController?.abort();
    quoteController = null;
    isQuoting.value = false;
}

function getRequestKey(): string {
    if (!requestKey.value) {
        requestKey.value = crypto.randomUUID();
    }

    return requestKey.value;
}

function generationPayload() {
    return {
        request_key: getRequestKey(),
        prompt: normalizedPrompt.value,
        image_size: imageSize.value,
        quality: quality.value,
        reference_image_urls: referenceUrls.value,
    };
}

function onFormChanged(): void {
    formVersion++;
    cancelQuote();

    quote.value = null;
    quoteError.value = '';
    errors.value = {};

    // A submission with an uncertain outcome must not
    // silently become a new request with different inputs.
    if (uncertainSubmission.value || result.value) {
        return;
    }

    requestKey.value = null;
    submissionError.value = '';

    scheduleQuote();
}

function scheduleQuote(): void {
    if (
        disposed ||
        !hasValidForm.value ||
        result.value ||
        uncertainSubmission.value
    ) {
        return;
    }

    quoteTimer = setTimeout(() => {
        quoteTimer = null;
        void fetchQuote();
    }, 450);
}

async function fetchQuote(): Promise<void> {
    if (
        disposed ||
        !hasValidForm.value ||
        result.value ||
        uncertainSubmission.value
    ) {
        return;
    }

    cancelQuote();

    const version = quoteVersion;
    const controller = new AbortController();
    quoteController = controller;

    isQuoting.value = true;
    quoteError.value = '';

    try {
        const response = await fetch('/generations/quote', {
            method: 'POST',
            credentials: 'same-origin',
            headers: requestHeaders(),
            body: JSON.stringify({
                prompt: normalizedPrompt.value,
                image_size: imageSize.value,
                quality: quality.value,
                reference_image_urls: referenceUrls.value,
            }),
            signal: controller.signal,
        });

        const body = await readJson(response);

        if (!response.ok) {
            throw new Error(
                errorMessage(
                    body,
                    'Unable to calculate the price.',
                ),
            );
        }

        if (
            disposed ||
            version !== quoteVersion ||
            controller.signal.aborted
        ) {
            return;
        }

        quote.value = body as Quote;
    } catch (error) {
        if (
            disposed ||
            version !== quoteVersion ||
            controller.signal.aborted
        ) {
            return;
        }

        quote.value = null;
        quoteError.value =
            error instanceof Error
                ? error.message
                : 'Unable to calculate the price.';
    } finally {
        if (
            !disposed &&
            version === quoteVersion
        ) {
            isQuoting.value = false;
            quoteController = null;
        }
    }
}

async function submitGeneration(): Promise<void> {
    if (!canSubmit.value) {
        return;
    }

    cancelQuote();
    isSubmitting.value = true;
    submissionError.value = '';
    errors.value = {};

    const version = formVersion;
    const controller = new AbortController();
    submitController = controller;

    try {
        const response = await fetch('/generations', {
            method: 'POST',
            credentials: 'same-origin',
            headers: requestHeaders(),
            body: JSON.stringify(generationPayload()),
            signal: controller.signal,
        });

        const body = await readJson(response);

        if (
            disposed ||
            version !== formVersion ||
            controller.signal.aborted
        ) {
            return;
        }

        if (response.status === 422) {
            const validation = body as {
                errors?: ValidationErrors;
                message?: string;
            };

            errors.value = validation.errors ?? {};
            submissionError.value =
                validation.message ?? 'Check your inputs.';

            // The server explicitly rejected this request.
            uncertainSubmission.value = false;
            return;
        }

        if (response.status !== 202) {
            submissionError.value = errorMessage(
                body,
                'The request could not be confirmed. Retry using the same request key.',
            );

            // For an ambiguous server error, retain the key.
            uncertainSubmission.value =
                response.status >= 500;

            return;
        }

        result.value = body as GenerationResponse;
        uncertainSubmission.value = false;
        generationStatus.value = null;
        pollingError.value = '';

        startPolling(result.value.id);
    } catch {
        if (
            disposed ||
            version !== formVersion ||
            controller.signal.aborted
        ) {
            return;
        }

        uncertainSubmission.value = true;
        submissionError.value =
            'The submission outcome is uncertain. Retry to check the request using the same request key.';
    } finally {
        if (!disposed) {
            isSubmitting.value = false;
            submitController = null;
        }
    }
}

function startPolling(id: number): void {
    stopPolling();

    const version = pollVersion;

    async function poll(): Promise<void> {
        if (disposed || version !== pollVersion) {
            return;
        }

        const controller = new AbortController();
        statusController = controller;

        try {
            const response = await fetch(
                `/generations/${id}`,
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: controller.signal,
                },
            );

            const body = await readJson(response);

            if (!response.ok) {
                throw new Error(
                    errorMessage(
                        body,
                        'Unable to retrieve generation status.',
                    ),
                );
            }

            if (
                disposed ||
                version !== pollVersion ||
                controller.signal.aborted
            ) {
                return;
            }

            generationStatus.value =
                body as GenerationStatus;

            pollingError.value = '';

            if (
                generationStatus.value.status === 'completed' ||
                generationStatus.value.status === 'failed'
            ) {
                // Refresh wallet information on the next
                // generation, when a new quote is requested.
                return;
            }
        } catch (error) {
            if (
                disposed ||
                version !== pollVersion ||
                controller.signal.aborted
            ) {
                return;
            }

            pollingError.value =
                error instanceof Error
                    ? error.message
                    : 'Status update failed.';
        } finally {
            if (
                !disposed &&
                version === pollVersion
            ) {
                statusController = null;

                if (
                    !isTerminal.value &&
                    generationStatus.value
                        ?.submission_status !== 'needs_review'
                ) {
                    pollTimer = setTimeout(
                        () => void poll(),
                        3000,
                    );
                }
            }
        }
    }

    void poll();
}

function retryStatus(): void {
    if (result.value) {
        startPolling(result.value.id);
    }
}

function resetForm(): void {
    if (isSubmitting.value) {
        return;
    }

    formVersion++;
    stopPolling();
    cancelQuote();

    prompt.value = '';
    imageSize.value = '1024x1024';
    quality.value = 'medium';
    referenceUrl.value = '';

    requestKey.value = null;
    uncertainSubmission.value = false;

    quote.value = null;
    quoteError.value = '';
    result.value = null;
    generationStatus.value = null;

    errors.value = {};
    submissionError.value = '';
    pollingError.value = '';
}

watch(
    [prompt, imageSize, quality, referenceUrl],
    onFormChanged,
);

onBeforeUnmount(() => {
    disposed = true;
    formVersion++;

    cancelQuote();
    stopPolling();
    submitController?.abort();
});
</script>

<template>
    <Head title="Create Image" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="space-y-2">
            <div class="flex items-center gap-2">
                <Sparkles class="size-6 text-primary" />
                <h1 class="text-2xl font-semibold tracking-tight">
                    Create Image
                </h1>
            </div>

            <p class="text-sm text-muted-foreground">
                Turn your ideas into images with Pixelforge.
            </p>
        </div>

        <div
            class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]"
        >
            <div class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Image Generation</CardTitle>
                        <CardDescription>
                            Describe your image and choose
                            your generation settings.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        <form
                            class="space-y-6"
                            @submit.prevent="submitGeneration"
                        >
                            <div class="space-y-2">
                                <div
                                    class="flex items-center justify-between"
                                >
                                    <Label for="prompt">Prompt</Label>
                                    <span
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ promptLength }} / 5000
                                    </span>
                                </div>

                                <textarea
                                    id="prompt"
                                    v-model="prompt"
                                    rows="7"
                                    maxlength="5000"
                                    placeholder="Describe the image you want to create..."
                                    class="flex w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="
                                        isSubmitting ||
                                        !!result ||
                                        uncertainSubmission
                                    "
                                />

                                <p
                                    v-if="errors.prompt?.length"
                                    class="text-sm text-destructive"
                                >
                                    {{ errors.prompt[0] }}
                                </p>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="space-y-2">
                                    <Label for="image-size">
                                        Image Size
                                    </Label>

                                    <select
                                        id="image-size"
                                        v-model="imageSize"
                                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        :disabled="
                                            isSubmitting ||
                                            !!result ||
                                            uncertainSubmission
                                        "
                                    >
                                        <option value="1024x1024">
                                            Square — 1024 × 1024
                                        </option>
                                        <option value="1536x1024">
                                            Landscape — 1536 × 1024
                                        </option>
                                        <option value="1024x1536">
                                            Portrait — 1024 × 1536
                                        </option>
                                    </select>

                                    <p
                                        v-if="errors.image_size?.length"
                                        class="text-sm text-destructive"
                                    >
                                        {{ errors.image_size[0] }}
                                    </p>
                                </div>

                                <div class="space-y-2">
                                    <Label for="quality">
                                        Quality
                                    </Label>

                                    <select
                                        id="quality"
                                        v-model="quality"
                                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        :disabled="
                                            isSubmitting ||
                                            !!result ||
                                            uncertainSubmission
                                        "
                                    >
                                        <option value="low">
                                            Low
                                        </option>
                                        <option value="medium">
                                            Medium
                                        </option>
                                        <option value="high">
                                            High
                                        </option>
                                    </select>

                                    <p
                                        v-if="errors.quality?.length"
                                        class="text-sm text-destructive"
                                    >
                                        {{ errors.quality[0] }}
                                    </p>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Label for="reference-url">
                                    Reference Image URL
                                    <span
                                        class="text-muted-foreground"
                                    >
                                        (optional)
                                    </span>
                                </Label>

                                <Input
                                    id="reference-url"
                                    v-model="referenceUrl"
                                    type="url"
                                    placeholder="https://example.com/reference.png"
                                    :disabled="
                                        isSubmitting ||
                                        !!result ||
                                        uncertainSubmission
                                    "
                                />

                                <p
                                    v-if="
                                        errors['reference_image_urls.0']
                                            ?.length
                                    "
                                    class="text-sm text-destructive"
                                >
                                    {{
                                        errors[
                                            'reference_image_urls.0'
                                        ][0]
                                    }}
                                </p>
                            </div>

                            <div
                                v-if="submissionError"
                                role="alert"
                                class="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive"
                            >
                                {{ submissionError }}
                            </div>

                            <div
                                v-if="result"
                                class="rounded-lg border border-border bg-muted/30 p-4"
                            >
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <div class="space-y-1">
                                        <p class="font-medium">
                                            Generation #{{ result.id }}
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{ displayStatus }}
                                        </p>
                                    </div>

                                    <CheckCircle2
                                        v-if="
                                            generationStatus?.status ===
                                            'completed'
                                        "
                                        class="size-5 text-green-600"
                                    />

                                    <AlertCircle
                                        v-else-if="
                                            generationStatus?.status ===
                                            'failed'
                                        "
                                        class="size-5 text-destructive"
                                    />

                                    <LoaderCircle
                                        v-else-if="isProcessing"
                                        class="size-5 animate-spin text-primary"
                                    />
                                </div>

                                <p
                                    v-if="
                                        generationStatus?.error_message
                                    "
                                    class="mt-3 text-sm text-destructive"
                                >
                                    {{
                                        generationStatus.error_message
                                    }}
                                </p>

                                <p
                                    v-if="pollingError"
                                    class="mt-3 text-sm text-destructive"
                                >
                                    {{ pollingError }}
                                </p>

                                <Button
                                    v-if="
                                        pollingError ||
                                        generationStatus
                                            ?.submission_status ===
                                            'needs_review'
                                    "
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="mt-3"
                                    @click="retryStatus"
                                >
                                    <RefreshCw class="mr-2 size-4" />
                                    Refresh status
                                </Button>
                            </div>

                            <div class="flex flex-wrap gap-3">
                                <Button
                                    type="submit"
                                    :disabled="!canSubmit"
                                >
                                    <LoaderCircle
                                        v-if="isSubmitting"
                                        class="mr-2 size-4 animate-spin"
                                    />
                                    <Sparkles
                                        v-else
                                        class="mr-2 size-4"
                                    />

                                    {{
                                        isSubmitting
                                            ? 'Submitting...'
                                            : uncertainSubmission
                                              ? 'Retry Submission'
                                              : 'Generate Image'
                                    }}
                                </Button>

                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="isSubmitting"
                                    @click="resetForm"
                                >
                                    New Generation
                                </Button>
                            </div>

                            <p
                                v-if="uncertainSubmission"
                                class="text-xs text-muted-foreground"
                            >
                                Retrying uses the original
                                request key to avoid creating
                                a duplicate generation.
                            </p>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle
                            class="flex items-center gap-2"
                        >
                            <Wallet class="size-5" />
                            Pricing & Wallet
                        </CardTitle>
                        <CardDescription>
                            Estimates include the 25% service markup.
                            Final charges depend on actual usage.
                        </CardDescription>
                    </CardHeader>

                    <CardContent class="space-y-4">
                        <div
                            v-if="isQuoting"
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <LoaderCircle
                                class="size-4 animate-spin"
                            />
                            Calculating estimate...
                        </div>

                        <p
                            v-else-if="quoteError"
                            class="text-sm text-destructive"
                        >
                            {{ quoteError }}
                        </p>

                        <div
                            v-else-if="quote"
                            class="space-y-4"
                        >
                            <div class="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <p
                                        class="text-xs text-muted-foreground"
                                    >
                                        Estimated charge
                                    </p>
                                    <p class="text-xl font-semibold">
                                        {{
                                            formatUsd(
                                                quote.estimated_charge_usd,
                                            )
                                        }}
                                    </p>
                                </div>

                                <div>
                                    <p
                                        class="text-xs text-muted-foreground"
                                    >
                                        Available credits
                                    </p>
                                    <p class="text-xl font-semibold">
                                        {{
                                            formatUsd(
                                                quote.available_usd,
                                            )
                                        }}
                                    </p>
                                </div>

                                <div>
                                    <p
                                        class="text-xs text-muted-foreground"
                                    >
                                        Reserved credits
                                    </p>
                                    <p class="text-xl font-semibold">
                                        {{
                                            formatUsd(
                                                quote.reserved_usd,
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="!quote.has_sufficient_balance"
                                role="alert"
                                class="flex gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                            >
                                <AlertCircle
                                    class="mt-0.5 size-4 shrink-0"
                                />
                                Insufficient credits for this
                                estimated reservation.
                            </div>
                        </div>

                        <div
                            v-else-if="result"
                            class="space-y-2 text-sm"
                        >
                            <div
                                class="flex justify-between gap-3"
                            >
                                <span class="text-muted-foreground">
                                    Estimated charge
                                </span>
                                <span class="font-medium">
                                    {{
                                        formatUsd(
                                            result.estimated_charge_usd,
                                        )
                                    }}
                                </span>
                            </div>

                            <div
                                v-if="
                                    generationStatus
                                        ?.customer_charge_usd !== null &&
                                    generationStatus
                                        ?.customer_charge_usd !== undefined
                                "
                                class="flex justify-between gap-3"
                            >
                                <span class="text-muted-foreground">
                                    Final charge
                                </span>
                                <span class="font-medium">
                                    {{
                                        formatUsd(
                                            generationStatus.customer_charge_usd,
                                        )
                                    }}
                                </span>
                            </div>
                        </div>

                        <p
                            v-else
                            class="text-sm text-muted-foreground"
                        >
                            Enter a prompt to see the
                            estimated price and wallet balance.
                        </p>
                    </CardContent>
                </Card>
            </div>

            <Card class="h-fit">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <ImageIcon class="size-5" />
                        Preview
                    </CardTitle>
                    <CardDescription>
                        Your generated images appear here.
                    </CardDescription>
                </CardHeader>

                <CardContent class="space-y-4">
                    <div
                        v-if="images.length"
                        class="space-y-4"
                    >
                        <div
                            v-for="(url, index) in images"
                            :key="`${index}-${url}`"
                            class="overflow-hidden rounded-lg border border-border"
                        >
                            <img
                                :src="url"
                                :alt="`Generated image ${index + 1}`"
                                class="h-auto w-full object-contain"
                            />

                            <div
                                class="flex items-center justify-between gap-2 border-t border-border p-3"
                            >
                                <span
                                    class="text-xs text-muted-foreground"
                                >
                                    Image {{ index + 1 }}
                                </span>

                                <Button
                                    as-child
                                    size="sm"
                                    variant="outline"
                                >
                                    <a
                                        :href="url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <Download
                                            class="mr-2 size-4"
                                        />
                                        Open image
                                    </a>
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else
                        class="flex aspect-square items-center justify-center rounded-lg border border-dashed border-border bg-muted/30 p-6 text-center"
                    >
                        <div class="space-y-3">
                            <LoaderCircle
                                v-if="isProcessing"
                                class="mx-auto size-10 animate-spin text-primary"
                            />

                            <ImageIcon
                                v-else
                                class="mx-auto size-10 text-muted-foreground"
                            />

                            <p
                                class="text-sm text-muted-foreground"
                            >
                                {{
                                    isProcessing
                                        ? 'Generating your image...'
                                        : 'No image to preview yet.'
                                }}
                            </p>
                        </div>
                    </div>

                    <p v-if="generationStatus?.status === 'completed'" class="text-sm text-muted-foreground">
                        Generation completed successfully.
                    </p>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
