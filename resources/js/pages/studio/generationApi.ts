import type {
    GenerationLifecycleStatus,
    GenerationStatus,
    GenerationSubmissionStatus,
} from './generationState';

export const GENERATION_SUBMISSION_TIMEOUT_MS = 30_000;
export const GENERATION_STATUS_TIMEOUT_MS = 10_000;
export const MAX_REFERENCE_IMAGES = 4;

const lifecycleStatuses = new Set<GenerationLifecycleStatus>([
    'queued',
    'processing',
    'completed',
    'failed',
]);

const submissionStatuses = new Set<GenerationSubmissionStatus>([
    'pending',
    'submitting',
    'accepted',
    'uncertain',
    'needs_review',
]);

export type GenerationSubmissionResponse = {
    id: number;
    request_key: string;
    status: GenerationLifecycleStatus;
    estimated_charge_units: number;
    estimated_charge_usd: string;
};

export type RestorableGeneration = GenerationStatus & {
    request_key: string;
    prompt: string;
    image_size: '1024x1024' | '1536x1024' | '1024x1536';
    quality: 'low' | 'medium' | 'high';
    reference_image_urls: string[];
    estimated_charge_units: number;
};

export class InvalidGenerationResponseError extends Error {
    constructor(
        message = 'The server returned an invalid generation response.',
    ) {
        super(message);
        this.name = 'InvalidGenerationResponseError';
    }
}

export type RequestTimeout = {
    clear: () => void;
    didTimeout: () => boolean;
};

export function startRequestTimeout(
    controller: AbortController,
    timeoutMs: number,
): RequestTimeout {
    let timedOut = false;
    const timer = setTimeout(() => {
        timedOut = true;
        controller.abort();
    }, timeoutMs);

    return {
        clear: () => clearTimeout(timer),
        didTimeout: () => timedOut,
    };
}

export function parseGenerationSubmissionResponse(
    value: unknown,
    expectedRequestKey: string,
): GenerationSubmissionResponse {
    const data = record(value);
    const id = positiveInteger(data.id, 'generation ID');
    const requestKey = requestKeyValue(data.request_key);
    const status = lifecycleStatus(data.status);

    if (requestKey !== expectedRequestKey) {
        throw new InvalidGenerationResponseError(
            'The server returned a generation for a different request key.',
        );
    }

    return {
        id,
        request_key: requestKey,
        status,
        estimated_charge_units: nonNegativeInteger(
            data.estimated_charge_units,
            'estimated charge',
        ),
        estimated_charge_usd: stringValue(
            data.estimated_charge_usd,
            'estimated charge',
        ),
    };
}

export function parseGenerationStatusResponse(
    value: unknown,
    expectedId?: number,
): GenerationStatus {
    const data = record(value);
    const id = positiveInteger(data.id, 'generation ID');
    const status = lifecycleStatus(data.status);
    const resultImageUrls = stringArray(
        data.result_image_urls,
        'result image URLs',
    );

    if (expectedId !== undefined && id !== expectedId) {
        throw new InvalidGenerationResponseError(
            'The server returned a different generation than requested.',
        );
    }

    return {
        id,
        status,
        submission_status: submissionStatus(data.submission_status),
        estimated_charge_usd: stringValue(
            data.estimated_charge_usd,
            'estimated charge',
        ),
        customer_charge_usd: nullableString(
            data.customer_charge_usd,
            'customer charge',
        ),
        result_image_urls: resultImageUrls,
        expected_image_count: nonNegativeInteger(
            data.expected_image_count ?? resultImageUrls.length,
            'expected image count',
        ),
        images_ready: booleanValue(
            data.images_ready ??
                (status === 'completed' && resultImageUrls.length > 0),
            'image readiness',
        ),
        reconciliation_paused: booleanValue(
            data.reconciliation_paused ?? false,
            'reconciliation state',
        ),
        error_message: nullableString(data.error_message, 'error message'),
        completed_at: nullableString(data.completed_at, 'completion time'),
    };
}

export function parseLatestGenerationResponse(
    value: unknown,
): RestorableGeneration | null {
    const wrapper = record(value);

    if (wrapper.generation === null) {
        return null;
    }

    const data = record(wrapper.generation);
    const status = parseGenerationStatusResponse(data);
    const imageSize = stringValue(data.image_size, 'image size');
    const quality = stringValue(data.quality, 'quality');

    if (!['1024x1024', '1536x1024', '1024x1536'].includes(imageSize)) {
        throw new InvalidGenerationResponseError(
            'The server returned an invalid generation image size.',
        );
    }

    if (!['low', 'medium', 'high'].includes(quality)) {
        throw new InvalidGenerationResponseError(
            'The server returned an invalid generation quality.',
        );
    }

    return {
        ...status,
        request_key: requestKeyValue(data.request_key),
        prompt: stringValue(data.prompt, 'prompt'),
        image_size: imageSize as RestorableGeneration['image_size'],
        quality: quality as RestorableGeneration['quality'],
        reference_image_urls: stringArray(
            data.reference_image_urls,
            'reference image URLs',
        ),
        estimated_charge_units: nonNegativeInteger(
            data.estimated_charge_units,
            'estimated charge',
        ),
    };
}

function record(value: unknown): Record<string, unknown> {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        throw new InvalidGenerationResponseError();
    }

    return value as Record<string, unknown>;
}

function lifecycleStatus(value: unknown): GenerationLifecycleStatus {
    if (
        typeof value !== 'string' ||
        !lifecycleStatuses.has(value as GenerationLifecycleStatus)
    ) {
        throw new InvalidGenerationResponseError(
            'The server returned an unknown generation status.',
        );
    }

    return value as GenerationLifecycleStatus;
}

function submissionStatus(value: unknown): GenerationSubmissionStatus {
    if (
        typeof value !== 'string' ||
        !submissionStatuses.has(value as GenerationSubmissionStatus)
    ) {
        throw new InvalidGenerationResponseError(
            'The server returned an unknown submission status.',
        );
    }

    return value as GenerationSubmissionStatus;
}

function positiveInteger(value: unknown, field: string): number {
    if (!Number.isInteger(value) || (value as number) <= 0) {
        throw new InvalidGenerationResponseError(
            `The server returned an invalid ${field}.`,
        );
    }

    return value as number;
}

function nonNegativeInteger(value: unknown, field: string): number {
    if (!Number.isInteger(value) || (value as number) < 0) {
        throw new InvalidGenerationResponseError(
            `The server returned an invalid ${field}.`,
        );
    }

    return value as number;
}

function stringValue(value: unknown, field: string): string {
    if (typeof value !== 'string') {
        throw new InvalidGenerationResponseError(
            `The server returned an invalid ${field}.`,
        );
    }

    return value;
}

function booleanValue(value: unknown, field: string): boolean {
    if (typeof value !== 'boolean') {
        throw new InvalidGenerationResponseError(
            `The server returned an invalid ${field}.`,
        );
    }

    return value;
}

function requestKeyValue(value: unknown): string {
    const requestKey = stringValue(value, 'request key');

    if (
        !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
            requestKey,
        )
    ) {
        throw new InvalidGenerationResponseError(
            'The server returned an invalid request key.',
        );
    }

    return requestKey;
}

function nullableString(value: unknown, field: string): string | null {
    if (value === null) {
        return null;
    }

    return stringValue(value, field);
}

function stringArray(value: unknown, field: string): string[] {
    if (
        !Array.isArray(value) ||
        !value.every((item) => typeof item === 'string')
    ) {
        throw new InvalidGenerationResponseError(
            `The server returned invalid ${field}.`,
        );
    }

    return value;
}
