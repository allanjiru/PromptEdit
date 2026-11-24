<script setup lang="ts">
import { computed, ref } from 'vue';
import { CheckCircle2, CreditCard, LoaderCircle } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { createRequestKey } from '@/lib/requestKey';
import type {
    DemoCreditConfig,
    DemoCreditPackage,
    WalletSummary,
} from '@/types';

const props = defineProps<{
    wallet: WalletSummary;
    demoCredits: DemoCreditConfig;
}>();

const emit = defineEmits<{
    purchased: [wallet: WalletSummary];
}>();

const expanded = ref(false);
const selectedPackageId = ref<string | null>(null);
const isPurchasing = ref(false);
const purchaseError = ref('');
const purchaseSuccess = ref('');
const requestKey = ref<string | null>(null);

const selectedPackage = computed<DemoCreditPackage | null>(
    () =>
        props.demoCredits.packages.find(
            (item) => item.id === selectedPackageId.value,
        ) ?? null,
);

function formatUsd(value: string | number): string {
    const amount = Number(value);

    if (!Number.isFinite(amount)) return '—';

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
}

function csrfHeaders(): Record<string, string> {
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    if (token) return { 'X-CSRF-TOKEN': token };

    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='));

    return cookie
        ? {
              'X-XSRF-TOKEN': decodeURIComponent(
                  cookie.substring('XSRF-TOKEN='.length),
              ),
          }
        : {};
}

function serverMessage(body: unknown, fallback: string): string {
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

function open(): void {
    if (!props.demoCredits.enabled) return;

    expanded.value = true;
    purchaseError.value = '';
}

function selectPackage(packageId: string): void {
    if (isPurchasing.value) return;

    if (selectedPackageId.value !== packageId) {
        requestKey.value = null;
    }

    selectedPackageId.value = packageId;
    purchaseError.value = '';
    purchaseSuccess.value = '';
}

async function purchase(): Promise<void> {
    if (!selectedPackage.value || isPurchasing.value) return;

    requestKey.value ??= createRequestKey();
    isPurchasing.value = true;
    purchaseError.value = '';
    purchaseSuccess.value = '';

    const controller = new AbortController();
    let timedOut = false;
    const timeout = window.setTimeout(() => {
        timedOut = true;
        controller.abort();
    }, 10_000);

    try {
        const response = await fetch('/wallet/demo-credits', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...csrfHeaders(),
            },
            body: JSON.stringify({
                package: selectedPackage.value.id,
                request_key: requestKey.value,
            }),
            signal: controller.signal,
        });
        const body: unknown = await response.json();

        if (!response.ok) {
            purchaseError.value = serverMessage(
                body,
                response.status === 429
                    ? 'Too many purchase attempts. Please wait a minute.'
                    : 'The simulated purchase could not be completed.',
            );
            return;
        }

        const result = body as {
            credited_usd: string;
            wallet: WalletSummary;
        };

        emit('purchased', result.wallet);
        purchaseSuccess.value = `${formatUsd(result.credited_usd)} in demo credits added. No real payment was made.`;
        requestKey.value = null;
        selectedPackageId.value = null;
        expanded.value = false;
    } catch (error) {
        purchaseError.value = timedOut
            ? 'Purchase confirmation timed out. Retry to safely check the same request.'
            : error instanceof Error
              ? error.message
              : 'The simulated purchase could not be completed.';
    } finally {
        window.clearTimeout(timeout);
        isPurchasing.value = false;
    }
}

defineExpose({ open });
</script>

<template>
    <section
        data-testid="wallet-credits"
        class="space-y-4 rounded-lg border border-border bg-muted/20 p-4"
    >
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <p class="text-xs text-muted-foreground">Available credits</p>
                <p class="text-xl font-semibold">
                    {{ formatUsd(wallet.available_usd) }}
                </p>
            </div>
            <div>
                <p class="text-xs text-muted-foreground">Reserved credits</p>
                <p class="text-xl font-semibold">
                    {{ formatUsd(wallet.reserved_usd) }}
                </p>
            </div>
        </div>

        <p class="text-xs text-muted-foreground">
            Demo credits are simulated for this assessment. No card is needed
            and no real money is charged.
        </p>

        <Button
            v-if="demoCredits.enabled"
            type="button"
            variant="outline"
            data-testid="buy-credits"
            :disabled="isPurchasing"
            @click="expanded = !expanded"
        >
            <CreditCard class="mr-2 size-4" />
            Buy Credits
        </Button>
        <p v-else class="text-sm text-muted-foreground">
            Simulated purchases are not enabled in this deployment.
        </p>

        <div v-if="expanded" class="space-y-3" data-testid="credit-packages">
            <div class="grid gap-2 sm:grid-cols-3">
                <button
                    v-for="item in demoCredits.packages"
                    :key="item.id"
                    type="button"
                    class="rounded-lg border p-3 text-left transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-60"
                    :class="
                        selectedPackageId === item.id
                            ? 'border-primary bg-primary/5 ring-1 ring-primary'
                            : 'border-border'
                    "
                    :disabled="isPurchasing"
                    :data-testid="`credit-package-${item.id}`"
                    @click="selectPackage(item.id)"
                >
                    <span class="block text-sm font-medium">
                        {{ item.label }}
                    </span>
                    <span class="text-sm text-muted-foreground">
                        {{ formatUsd(item.amount_usd) }}
                    </span>
                </button>
            </div>

            <div v-if="selectedPackage" class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    data-testid="confirm-credit-purchase"
                    :disabled="isPurchasing"
                    @click="purchase"
                >
                    <LoaderCircle
                        v-if="isPurchasing"
                        class="mr-2 size-4 animate-spin"
                    />
                    {{
                        isPurchasing
                            ? 'Adding credits...'
                            : `Confirm ${selectedPackage.label}`
                    }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    :disabled="isPurchasing"
                    @click="expanded = false"
                >
                    Cancel
                </Button>
            </div>
        </div>

        <p v-if="purchaseError" role="alert" class="text-sm text-destructive">
            {{ purchaseError }}
        </p>
        <p
            v-if="purchaseSuccess"
            role="status"
            class="flex items-start gap-2 text-sm text-green-700 dark:text-green-400"
        >
            <CheckCircle2 class="mt-0.5 size-4 shrink-0" />
            {{ purchaseSuccess }}
        </p>
    </section>
</template>
