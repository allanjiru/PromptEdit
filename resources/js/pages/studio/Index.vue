<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
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
    Plus,
    RefreshCw,
    Sparkles,
    Wallet,
} from '@lucide/vue';
import { Button } from '@/components/ui/button';
import WalletCredits from '@/components/WalletCredits.vue';
import { csrfHeaders } from '@/lib/csrf';
import { createRequestKey } from '@/lib/requestKey';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { index as studioIndex } from '@/routes/studio';
import { Link } from '@inertiajs/vue3';
import type { DemoCreditConfig, WalletSummary } from '@/types';
import ReferenceUrlField from './ReferenceUrlField.vue';
import {
    GENERATION_STATUS_TIMEOUT_MS,
    GENERATION_SUBMISSION_TIMEOUT_MS,
    MAX_REFERENCE_IMAGES,
    parseGenerationStatusResponse,
    parseGenerationSubmissionResponse,
    parseLatestGenerationResponse,
    startRequestTimeout,
    type GenerationSubmissionResponse,
    type RequestTimeout,
} from './generationApi';
import {
    generationDisplayStatus,
    isGenerationProcessing,
    shouldAutomaticallyRestoreGeneration,
    shouldPollImageReadiness,
    shouldPollGeneration,
    type GenerationStatus,
} from './generationState';
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
type ImageSize = '1024x1024' | '1536x1024' | '1024x1536';
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
const props = withDefaults(
    defineProps<{
        wallet?: WalletSummary;
        demoCredits?: DemoCreditConfig;
    }>(),
    {
        wallet: () => ({
            available_units: 0,
            available_usd: '0.000000000',
            reserved_units: 0,
            reserved_usd: '0.000000000',
        }),
        demoCredits: () => ({ enabled: false, packages: [] }),
    },
);
const prompt = ref('');
const imageSize = ref<ImageSize>('1024x1024');
const quality = ref<Quality>('medium');
const referenceUrlInputs = ref<string[]>(['']);
const quote = ref<Quote | null>(null);
const isQuoting = ref(false);
const quoteError = ref('');
const isSubmitting = ref(false);
const errors = ref<ValidationErrors>({});
const submissionError = ref('');
const result = ref<GenerationSubmissionResponse | null>(null);
const generationStatus = ref<GenerationStatus | null>(null);
const pollingError = ref('');
const requestKey = ref<string | null>(null);
const uncertainSubmission = ref(false);
const wallet = ref<WalletSummary>({ ...props.wallet });
const walletCreditsRef = ref<InstanceType<typeof WalletCredits> | null>(null);
let quoteTimer: ReturnType<typeof setTimeout> | null = null;
let pollTimer: ReturnType<typeof setTimeout> | null = null;
let quoteController: AbortController | null = null;
let statusController: AbortController | null = null;
let submitController: AbortController | null = null;
let readinessRetryController: AbortController | null = null;
let statusTimeout: RequestTimeout | null = null;
let submitTimeout: RequestTimeout | null = null;
let quoteVersion = 0;
let formVersion = 0;
let pollVersion = 0;
let readinessPollAttempts = 0;
const MAX_READINESS_POLL_ATTEMPTS = 20;
let disposed = false;
let restoreController: AbortController | null = null;
let restoreVersion = 0;
let applyingRestoration = false;
const promptLength = computed(() => prompt.value.length);
const normalizedPrompt = computed(() => prompt.value.trim());
const normalizedReferences = computed(() =>
    referenceUrlInputs.value.map((value) => value.trim()),
);
const referenceUrls = computed(() =>
    normalizedReferences.value.filter((value) => value !== ''),
);
const invalidReferenceIndexes = computed(() =>
    normalizedReferences.value
        .map((value, index) =>
            value !== '' && !isValidUrl(value) ? index : null,
        )
        .filter((index): index is number => index !== null),
);
const hasValidForm = computed(
    () =>
        normalizedPrompt.value.length > 0 &&
        promptLength.value <= 4000 &&
        referenceUrls.value.length <= MAX_REFERENCE_IMAGES &&
        invalidReferenceIndexes.value.length === 0,
);
const isProcessing = computed(() =>
    isGenerationProcessing(!!result.value, generationStatus.value),
);
const canSubmit = computed(
    () =>
        hasValidForm.value &&
        !isSubmitting.value &&
        !result.value &&
        (uncertainSubmission.value ||
            (!!quote.value &&
                quote.value.has_sufficient_balance &&
                !isQuoting.value &&
                !quoteError.value)),
);
const images = computed(() => generationStatus.value?.result_image_urls ?? []);
const completedWithoutImages = computed(
    () =>
        generationStatus.value?.status === 'completed' &&
        images.value.length === 0,
);
const isPreparingImage = computed(() =>
    shouldPollImageReadiness(generationStatus.value),
);
const displayStatus = computed(() =>
    generationDisplayStatus(!!result.value, generationStatus.value),
);
const referenceInputsDisabled = computed(
    () => isSubmitting.value || !!result.value || uncertainSubmission.value,
);
function isValidUrl(value: string): boolean {
    try {
        const url = new URL(value);
        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}
function addReferenceUrl(): void {
    if (
        referenceUrlInputs.value.length >= MAX_REFERENCE_IMAGES ||
        isSubmitting.value ||
        result.value ||
        uncertainSubmission.value
    ) {
        return;
    }

    referenceUrlInputs.value.push('');
}
function updateReferenceUrl(index: number, value: string | number): void {
    referenceUrlInputs.value[index] = String(value);
}
function referenceError(index: number): string | undefined {
    if (invalidReferenceIndexes.value.includes(index)) {
        return 'Enter a valid HTTP or HTTPS URL.';
    }

    return errors.value[`reference_image_urls.${index}`]?.[0];
}
function canRemoveReference(index: number): boolean {
    return (
        referenceUrlInputs.value.length > 1 ||
        referenceUrlInputs.value[index] !== ''
    );
}
function removeReferenceUrl(index: number): void {
    if (isSubmitting.value || result.value || uncertainSubmission.value) {
        return;
    }

    referenceUrlInputs.value.splice(index, 1);

    if (referenceUrlInputs.value.length === 0) {
        referenceUrlInputs.value.push('');
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
function requestHeaders(): Record<string, string> {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...csrfHeaders(),
    };
}
async function readJson(response: Response): Promise<unknown> {
    const contentType = response.headers.get('content-type') ?? '';
    if (!contentType.includes('application/json')) {
        throw new Error('The server returned an unexpected response.');
    }

    try {
        return await response.json();
    } catch {
        throw new Error('The server returned invalid JSON.');
    }
}
function errorMessage(body: unknown, fallback: string): string {
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
function onCreditsPurchased(updatedWallet: WalletSummary): void {
    wallet.value = updatedWallet;

    if (quote.value) {
        quote.value = {
            ...quote.value,
            available_units: updatedWallet.available_units,
            available_usd: updatedWallet.available_usd,
            reserved_units: updatedWallet.reserved_units,
            reserved_usd: updatedWallet.reserved_usd,
            has_sufficient_balance:
                updatedWallet.available_units >=
                quote.value.estimated_charge_units,
        };
    }

    if (hasValidForm.value && !result.value && !uncertainSubmission.value) {
        void fetchQuote();
    }
}
function stopPolling(): void {
    pollVersion++;
    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
    statusTimeout?.clear();
    statusTimeout = null;
    statusController?.abort();
    statusController = null;
    readinessRetryController?.abort();
    readinessRetryController = null;
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
        requestKey.value = createRequestKey();
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
    if (applyingRestoration) {
        return;
    }
    restoreVersion++;
    restoreController?.abort();
    restoreController = null;
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
                errorMessage(body, 'Unable to calculate the price.'),
            );
        }
        if (disposed || version !== quoteVersion || controller.signal.aborted) {
            return;
        }
        quote.value = body as Quote;
        wallet.value = {
            available_units: quote.value.available_units,
            available_usd: quote.value.available_usd,
            reserved_units: quote.value.reserved_units,
            reserved_usd: quote.value.reserved_usd,
        };
    } catch (error) {
        if (disposed || version !== quoteVersion || controller.signal.aborted) {
            return;
        }
        quote.value = null;
        quoteError.value =
            error instanceof Error
                ? error.message
                : 'Unable to calculate the price.';
    } finally {
        if (!disposed && version === quoteVersion) {
            isQuoting.value = false;
            quoteController = null;
        }
    }
}
async function submitGeneration(): Promise<void> {
    if (!canSubmit.value) {
        return;
    }
    restoreVersion++;
    restoreController?.abort();
    restoreController = null;
    cancelQuote();
    isSubmitting.value = true;
    submissionError.value = '';
    errors.value = {};
    const version = formVersion;
    const controller = new AbortController();
    const timeout = startRequestTimeout(
        controller,
        GENERATION_SUBMISSION_TIMEOUT_MS,
    );
    submitController = controller;
    submitTimeout = timeout;
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
            (controller.signal.aborted && !timeout.didTimeout())
        ) {
            return;
        }
        if (timeout.didTimeout()) {
            throw new Error('Submission confirmation timed out.');
        }
        if (response.status === 422) {
            const validation = body as {
                errors?: ValidationErrors;
                message?: string;
            };
            errors.value = validation.errors ?? {};
            submissionError.value = validation.message ?? 'Check your inputs.';
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
            uncertainSubmission.value = response.status >= 500;
            return;
        }
        const submissionBody = body as { wallet?: WalletSummary };
        if (submissionBody.wallet) {
            wallet.value = submissionBody.wallet;
        }
        result.value = parseGenerationSubmissionResponse(body, getRequestKey());
        uncertainSubmission.value = false;
        generationStatus.value = null;
        pollingError.value = '';
        startPolling(result.value.id);
    } catch (error) {
        if (
            disposed ||
            version !== formVersion ||
            (controller.signal.aborted && !timeout.didTimeout())
        ) {
            return;
        }
        uncertainSubmission.value = true;
        submissionError.value = timeout.didTimeout()
            ? 'Submission confirmation timed out. The generation may still have been accepted. Retry with the same request key to recover its status safely.'
            : error instanceof Error
              ? `${error.message} Retry with the same request key to recover its status safely.`
              : 'The submission outcome is uncertain. Retry with the same request key to recover its status safely.';
    } finally {
        timeout.clear();
        if (submitTimeout === timeout) {
            submitTimeout = null;
        }
        if (!disposed) {
            isSubmitting.value = false;
            submitController = null;
        }
    }
}
function startPolling(id: number): void {
    stopPolling();
    readinessPollAttempts = 0;
    const version = pollVersion;
    async function poll(): Promise<void> {
        if (disposed || version !== pollVersion) {
            return;
        }
        const controller = new AbortController();
        const timeout = startRequestTimeout(
            controller,
            GENERATION_STATUS_TIMEOUT_MS,
        );
        statusController = controller;
        statusTimeout = timeout;
        try {
            const response = await fetch(`/generations/${id}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            });
            const body = await readJson(response);
            if (!response.ok) {
                throw new Error(
                    errorMessage(body, 'Unable to retrieve generation status.'),
                );
            }
            if (
                disposed ||
                version !== pollVersion ||
                (controller.signal.aborted && !timeout.didTimeout())
            ) {
                return;
            }
            if (timeout.didTimeout()) {
                throw new Error('The status request timed out.');
            }
            generationStatus.value = parseGenerationStatusResponse(body, id);
            pollingError.value = '';
            if (shouldPollImageReadiness(generationStatus.value)) {
                readinessPollAttempts++;

                if (readinessPollAttempts >= MAX_READINESS_POLL_ATTEMPTS) {
                    pollingError.value =
                        'Image preparation is taking longer than expected. Retry image preparation.';

                    return;
                }
            }
            if (generationStatus.value.status === 'failed') {
                // Refresh wallet information on the next
                // generation, when a new quote is requested.
                return;
            }
        } catch (error) {
            if (
                disposed ||
                version !== pollVersion ||
                (controller.signal.aborted && !timeout.didTimeout())
            ) {
                return;
            }
            pollingError.value = timeout.didTimeout()
                ? 'The status request timed out. Studio will try again automatically.'
                : error instanceof Error
                  ? error.message
                  : 'Status update failed.';
        } finally {
            timeout.clear();
            if (statusTimeout === timeout) {
                statusTimeout = null;
            }
            if (!disposed && version === pollVersion) {
                statusController = null;
                if (
                    shouldPollGeneration(generationStatus.value) ||
                    (shouldPollImageReadiness(generationStatus.value) &&
                        readinessPollAttempts < MAX_READINESS_POLL_ATTEMPTS)
                ) {
                    pollTimer = setTimeout(() => void poll(), 3000);
                }
            }
        }
    }
    void poll();
}
async function retryStatus(): Promise<void> {
    if (!result.value) {
        return;
    }

    const id = result.value.id;
    const version = pollVersion;

    if (shouldPollImageReadiness(generationStatus.value)) {
        pollingError.value = '';
        const controller = new AbortController();
        readinessRetryController?.abort();
        readinessRetryController = controller;

        try {
            const response = await fetch(`/generations/${id}/images/retry`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: requestHeaders(),
                body: JSON.stringify({}),
                signal: controller.signal,
            });

            if (
                disposed ||
                controller.signal.aborted ||
                version !== pollVersion ||
                result.value?.id !== id
            ) {
                return;
            }

            if (!response.ok) {
                const body = await readJson(response);
                throw new Error(
                    errorMessage(body, 'Unable to retry image preparation.'),
                );
            }
        } catch (error) {
            if (
                disposed ||
                controller.signal.aborted ||
                version !== pollVersion ||
                result.value?.id !== id
            ) {
                return;
            }

            pollingError.value =
                error instanceof Error
                    ? error.message
                    : 'Unable to retry image preparation.';
        } finally {
            if (readinessRetryController === controller) {
                readinessRetryController = null;
            }
        }
    }

    if (!disposed && version === pollVersion && result.value?.id === id) {
        startPolling(id);
    }
}
function resetForm(): void {
    if (isSubmitting.value) {
        return;
    }
    restoreVersion++;
    restoreController?.abort();
    restoreController = null;
    formVersion++;
    stopPolling();
    cancelQuote();
    prompt.value = '';
    imageSize.value = '1024x1024';
    quality.value = 'medium';
    referenceUrlInputs.value = [''];
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
function openCreditPackages(): void {
    walletCreditsRef.value?.open();

    void nextTick(() => {
        document
            .querySelector<HTMLElement>('[data-testid="wallet-credits"]')
            ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
}
watch([prompt, imageSize, quality, referenceUrlInputs], onFormChanged, {
    deep: true,
    flush: 'sync',
});
onMounted(() => {
    void restoreLatestGeneration();

    if (
        props.demoCredits.enabled &&
        new URLSearchParams(window.location.search).get('credits') === 'buy'
    ) {
        void nextTick(() => walletCreditsRef.value?.open());
    }
});
onBeforeUnmount(() => {
    restoreVersion++;
    restoreController?.abort();
    restoreController = null;
    disposed = true;
    formVersion++;
    cancelQuote();
    stopPolling();
    submitTimeout?.clear();
    submitTimeout = null;
    submitController?.abort();
});
async function restoreLatestGeneration(): Promise<void> {
    const version = ++restoreVersion;
    restoreController?.abort();
    const controller = new AbortController();
    restoreController = controller;
    try {
        const response = await fetch('/generations/latest', {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: controller.signal,
        });
        const body = await readJson(response);
        if (!response.ok) {
            throw new Error(
                errorMessage(body, 'Unable to restore your latest generation.'),
            );
        }
        if (
            disposed ||
            controller.signal.aborted ||
            version !== restoreVersion
        ) {
            return;
        }
        const generation = parseLatestGenerationResponse(body);
        const studioIsPristine =
            !result.value &&
            !isSubmitting.value &&
            !uncertainSubmission.value &&
            requestKey.value === null &&
            prompt.value === '' &&
            referenceUrlInputs.value.length === 1 &&
            referenceUrlInputs.value[0] === '' &&
            imageSize.value === '1024x1024' &&
            quality.value === 'medium';
        if (
            !generation ||
            !shouldAutomaticallyRestoreGeneration(generation, studioIsPristine)
        ) {
            return;
        }
        applyingRestoration = true;
        try {
            prompt.value = generation.prompt;
            imageSize.value = generation.image_size;
            quality.value = generation.quality;
            referenceUrlInputs.value = generation.reference_image_urls.length
                ? [...generation.reference_image_urls]
                : [''];
        } finally {
            applyingRestoration = false;
        }
        requestKey.value = generation.request_key;
        result.value = {
            id: generation.id,
            request_key: generation.request_key,
            status: generation.status,
            estimated_charge_units: generation.estimated_charge_units,
            estimated_charge_usd: generation.estimated_charge_usd,
        };
        generationStatus.value = generation;
        uncertainSubmission.value = false;
        submissionError.value = '';
        pollingError.value = '';
        errors.value = {};
        cancelQuote();
        quote.value = null;
        if (shouldPollGeneration(generationStatus.value)) {
            startPolling(generation.id);
        }
    } catch (error) {
        if (
            disposed ||
            controller.signal.aborted ||
            version !== restoreVersion
        ) {
            return;
        }
        console.error('Generation restoration failed:', error);
    } finally {
        if (restoreController === controller) {
            restoreController = null;
        }
    }
}
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
            <div class="flex items-center justify-between gap-4">
                <p class="text-sm text-muted-foreground">
                    Turn your ideas into images with Pixelforge.
                </p>
                <div class="flex shrink-0 items-center gap-2">
                    <Button
                        v-if="demoCredits.enabled"
                        type="button"
                        variant="outline"
                        class="rounded-xl"
                        @click="openCreditPackages"
                    >
                        Buy credits
                    </Button>
                    <Link
                        href="/generations"
                        class="inline-flex items-center rounded-xl border border-border px-4 py-2 text-sm font-medium hover:bg-muted"
                    >
                        View history
                    </Link>
                </div>
            </div>
        </div>
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
            <div class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Image Generation</CardTitle>
                        <CardDescription>
                            Describe your image and choose your generation
                            settings.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            class="space-y-6"
                            @submit.prevent="submitGeneration"
                        >
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label for="prompt">Prompt</Label>
                                    <span class="text-xs text-muted-foreground">
                                        {{ promptLength }} / 4000
                                    </span>
                                </div>
                                <textarea
                                    id="prompt"
                                    v-model="prompt"
                                    rows="7"
                                    maxlength="4000"
                                    placeholder="Describe the image you want to create..."
                                    class="flex w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
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
                                    <Label for="image-size"> Image Size </Label>
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
                                    <Label for="quality"> Quality </Label>
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
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                    </select>
                                    <p
                                        v-if="errors.quality?.length"
                                        class="text-sm text-destructive"
                                    >
                                        {{ errors.quality[0] }}
                                    </p>
                                </div>
                            </div>
                            <fieldset class="space-y-3">
                                <div
                                    class="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <div>
                                        <Label>Reference Image URLs</Label>
                                        <p
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            Optional · up to
                                            {{ MAX_REFERENCE_IMAGES }} URLs
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        :disabled="
                                            referenceUrlInputs.length >=
                                                MAX_REFERENCE_IMAGES ||
                                            isSubmitting ||
                                            !!result ||
                                            uncertainSubmission
                                        "
                                        @click="addReferenceUrl"
                                    >
                                        <Plus class="mr-2 size-4" />
                                        Add reference
                                    </Button>
                                </div>

                                <ReferenceUrlField
                                    :index="0"
                                    :value="referenceUrlInputs[0] ?? ''"
                                    :disabled="referenceInputsDisabled"
                                    :can-remove="canRemoveReference(0)"
                                    :error="referenceError(0)"
                                    @update="updateReferenceUrl(0, $event)"
                                    @remove="removeReferenceUrl(0)"
                                />
                                <ReferenceUrlField
                                    v-if="referenceUrlInputs.length > 1"
                                    :index="1"
                                    :value="referenceUrlInputs[1] ?? ''"
                                    :disabled="referenceInputsDisabled"
                                    :can-remove="canRemoveReference(1)"
                                    :error="referenceError(1)"
                                    @update="updateReferenceUrl(1, $event)"
                                    @remove="removeReferenceUrl(1)"
                                />
                                <ReferenceUrlField
                                    v-if="referenceUrlInputs.length > 2"
                                    :index="2"
                                    :value="referenceUrlInputs[2] ?? ''"
                                    :disabled="referenceInputsDisabled"
                                    :can-remove="canRemoveReference(2)"
                                    :error="referenceError(2)"
                                    @update="updateReferenceUrl(2, $event)"
                                    @remove="removeReferenceUrl(2)"
                                />
                                <ReferenceUrlField
                                    v-if="referenceUrlInputs.length > 3"
                                    :index="3"
                                    :value="referenceUrlInputs[3] ?? ''"
                                    :disabled="referenceInputsDisabled"
                                    :can-remove="canRemoveReference(3)"
                                    :error="referenceError(3)"
                                    @update="updateReferenceUrl(3, $event)"
                                    @remove="removeReferenceUrl(3)"
                                />

                                <p
                                    v-if="errors.reference_image_urls?.length"
                                    class="text-sm text-destructive"
                                >
                                    {{ errors.reference_image_urls[0] }}
                                </p>
                            </fieldset>
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
                                    v-if="generationStatus?.error_message"
                                    class="mt-3 text-sm text-destructive"
                                >
                                    {{ generationStatus.error_message }}
                                </p>
                                <p
                                    v-if="pollingError"
                                    class="mt-3 text-sm text-destructive"
                                >
                                    {{ pollingError }}
                                </p>
                                <p
                                    v-if="
                                        generationStatus?.reconciliation_paused
                                    "
                                    class="mt-3 text-sm text-muted-foreground"
                                >
                                    Automatic provider checks are paused. The
                                    generation remains accepted and can still
                                    complete from a trusted provider update.
                                </p>
                                <Button
                                    v-if="
                                        pollingError ||
                                        generationStatus?.reconciliation_paused ||
                                        generationStatus?.submission_status ===
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
                                <Button type="submit" :disabled="!canSubmit">
                                    <LoaderCircle
                                        v-if="isSubmitting"
                                        class="mr-2 size-4 animate-spin"
                                    />
                                    <Sparkles v-else class="mr-2 size-4" />
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
                                Retrying uses the original request key to avoid
                                creating a duplicate generation.
                            </p>
                        </form>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Wallet class="size-5" />
                            Pricing & Wallet
                        </CardTitle>
                        <CardDescription>
                            Estimates include the 25% service markup. Final
                            charges depend on actual usage.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div
                            v-if="isQuoting"
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <LoaderCircle class="size-4 animate-spin" />
                            Calculating estimate...
                        </div>
                        <p
                            v-else-if="quoteError"
                            class="text-sm text-destructive"
                        >
                            {{ quoteError }}
                        </p>
                        <div v-else-if="quote" class="space-y-4">
                            <div>
                                <div>
                                    <p class="text-xs text-muted-foreground">
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
                            </div>
                            <div
                                v-if="!quote.has_sufficient_balance"
                                role="alert"
                                class="flex gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                            >
                                <AlertCircle class="mt-0.5 size-4 shrink-0" />
                                <div class="space-y-2">
                                    <p>
                                        Generate is unavailable because this
                                        estimate exceeds your available credits.
                                    </p>
                                    <Button
                                        v-if="demoCredits.enabled"
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        @click="openCreditPackages"
                                    >
                                        Buy Credits
                                    </Button>
                                </div>
                            </div>
                        </div>
                        <div v-else-if="result" class="space-y-2 text-sm">
                            <div class="flex justify-between gap-3">
                                <span class="text-muted-foreground">
                                    Estimated charge
                                </span>
                                <span class="font-medium">
                                    {{ formatUsd(result.estimated_charge_usd) }}
                                </span>
                            </div>
                            <div
                                v-if="
                                    generationStatus?.customer_charge_usd !==
                                        null &&
                                    generationStatus?.customer_charge_usd !==
                                        undefined
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
                        <p v-else class="text-sm text-muted-foreground">
                            Enter a prompt to see the estimated price and wallet
                            balance.
                        </p>
                        <WalletCredits
                            ref="walletCreditsRef"
                            :wallet="wallet"
                            :demo-credits="demoCredits"
                            @purchased="onCreditsPurchased"
                        />
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
                    <div v-if="images.length" class="space-y-4">
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
                                <span class="text-xs text-muted-foreground">
                                    Image {{ index + 1 }}
                                </span>
                                <Button as-child size="sm" variant="outline">
                                    <a
                                        :href="url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <Download class="mr-2 size-4" />
                                        Open image
                                    </a>
                                </Button>
                            </div>
                        </div>
                        <p
                            v-if="isPreparingImage"
                            class="text-sm text-muted-foreground"
                        >
                            Preparing the remaining images...
                        </p>
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
                            <LoaderCircle
                                v-else-if="isPreparingImage"
                                class="mx-auto size-10 animate-spin text-primary"
                            />
                            <ImageIcon
                                v-else
                                class="mx-auto size-10 text-muted-foreground"
                            />
                            <p class="text-sm text-muted-foreground">
                                {{
                                    isProcessing
                                        ? 'Generating your image...'
                                        : isPreparingImage
                                          ? 'Preparing your image...'
                                          : completedWithoutImages
                                            ? 'Generation completed, but its image is not available yet.'
                                            : 'No image to preview yet.'
                                }}
                            </p>
                        </div>
                    </div>
                    <Button
                        v-if="isPreparingImage && pollingError"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="retryStatus"
                    >
                        <RefreshCw class="mr-2 size-4" />
                        Retry image preparation
                    </Button>
                    <p
                        v-if="generationStatus?.status === 'completed'"
                        class="text-sm text-muted-foreground"
                    >
                        Generation completed successfully.
                    </p>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
