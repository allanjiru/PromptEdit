export type GenerationLifecycleStatus =
    | 'queued'
    | 'processing'
    | 'completed'
    | 'failed';

export type GenerationSubmissionStatus =
    | 'pending'
    | 'submitting'
    | 'accepted'
    | 'uncertain'
    | 'needs_review';

export type GenerationStatus = {
    id: number;
    status: GenerationLifecycleStatus;
    submission_status: GenerationSubmissionStatus;
    estimated_charge_usd: string;
    customer_charge_usd: string | null;
    result_image_urls: string[];
    expected_image_count: number;
    images_ready: boolean;
    reconciliation_paused: boolean;
    error_message: string | null;
    completed_at: string | null;
};

export function isTerminalGeneration(status: GenerationStatus | null): boolean {
    return status?.status === 'completed' || status?.status === 'failed';
}

export function isGenerationProcessing(
    hasResult: boolean,
    status: GenerationStatus | null,
): boolean {
    return (
        hasResult &&
        !isTerminalGeneration(status) &&
        !status?.reconciliation_paused
    );
}

export function generationDisplayStatus(
    hasResult: boolean,
    status: GenerationStatus | null,
): string {
    if (!hasResult) {
        return 'Not started';
    }

    if (status?.status === 'completed') {
        return 'Completed';
    }

    if (status?.status === 'failed') {
        return 'Failed';
    }

    if (status?.status === 'processing') {
        if (status.reconciliation_paused) {
            return 'Checking paused';
        }

        return 'Processing';
    }

    if (status?.reconciliation_paused) {
        return 'Checking paused';
    }

    if (status?.submission_status === 'needs_review') {
        return 'Needs review';
    }

    return 'Queued';
}

export function shouldPollGeneration(status: GenerationStatus | null): boolean {
    return (
        !isTerminalGeneration(status) &&
        !status?.reconciliation_paused &&
        status?.submission_status !== 'needs_review'
    );
}

export function shouldPollImageReadiness(
    status: GenerationStatus | null,
): boolean {
    return status?.status === 'completed' && !status.images_ready;
}

export function shouldAutomaticallyRestoreGeneration(
    status: GenerationStatus | null,
    studioIsPristine: boolean,
): boolean {
    return (
        studioIsPristine &&
        (status?.status === 'queued' || status?.status === 'processing') &&
        status.submission_status !== 'needs_review'
    );
}
