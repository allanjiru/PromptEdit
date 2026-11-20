import assert from 'node:assert/strict';
import test from 'node:test';

import {
    generationDisplayStatus,
    isGenerationProcessing,
    shouldAutomaticallyRestoreGeneration,
    shouldPollImageReadiness,
    shouldPollGeneration,
    type GenerationStatus,
} from '../../resources/js/pages/studio/generationState.ts';

function status(overrides: Partial<GenerationStatus> = {}): GenerationStatus {
    return {
        id: 8,
        status: 'queued',
        submission_status: 'accepted',
        estimated_charge_usd: '0.086268750',
        customer_charge_usd: null,
        result_image_urls: [],
        expected_image_count: 0,
        images_ready: false,
        reconciliation_paused: false,
        error_message: null,
        completed_at: null,
        ...overrides,
    };
}

void test('a restored completed generation is terminal immediately', () => {
    const completed = status({
        status: 'completed',
        customer_charge_usd: '0.059450000',
        result_image_urls: ['/generations/8/images/0'],
        completed_at: '2026-11-19T12:04:14+00:00',
    });

    assert.equal(generationDisplayStatus(true, completed), 'Completed');
    assert.equal(isGenerationProcessing(true, completed), false);
    assert.equal(shouldPollGeneration(completed), false);
    assert.deepEqual(completed.result_image_urls, ['/generations/8/images/0']);
    assert.equal(completed.customer_charge_usd, '0.059450000');
});

void test('a known completed status is preserved while no newer response exists', () => {
    const lastKnown = status({ status: 'completed' });

    assert.equal(generationDisplayStatus(true, lastKnown), 'Completed');
    assert.equal(isGenerationProcessing(true, lastKnown), false);
});

void test('queued and processing generations continue polling', () => {
    const queued = status({ status: 'queued' });
    const processing = status({ status: 'processing' });

    assert.equal(generationDisplayStatus(true, queued), 'Queued');
    assert.equal(isGenerationProcessing(true, queued), true);
    assert.equal(shouldPollGeneration(queued), true);

    assert.equal(generationDisplayStatus(true, processing), 'Processing');
    assert.equal(isGenerationProcessing(true, processing), true);
    assert.equal(shouldPollGeneration(processing), true);
});

void test('failed and needs-review generations do not poll indefinitely', () => {
    const failed = status({
        status: 'failed',
        error_message: 'Provider rejected the request.',
    });
    const needsReview = status({ submission_status: 'needs_review' });

    assert.equal(generationDisplayStatus(true, failed), 'Failed');
    assert.equal(isGenerationProcessing(true, failed), false);
    assert.equal(shouldPollGeneration(failed), false);

    assert.equal(generationDisplayStatus(true, needsReview), 'Needs review');
    assert.equal(shouldPollGeneration(needsReview), false);
});

void test('an exhausted provider budget is shown as checking paused', () => {
    const paused = status({ reconciliation_paused: true });

    assert.equal(generationDisplayStatus(true, paused), 'Checking paused');
    assert.equal(isGenerationProcessing(true, paused), false);
    assert.equal(shouldPollGeneration(paused), false);
    assert.equal(paused.submission_status, 'accepted');
});

void test('completed latest generation is not automatically restored', () => {
    assert.equal(
        shouldAutomaticallyRestoreGeneration(
            status({ status: 'completed' }),
            true,
        ),
        false,
    );
});

void test('failed latest generation is not automatically restored', () => {
    assert.equal(
        shouldAutomaticallyRestoreGeneration(
            status({ status: 'failed' }),
            true,
        ),
        false,
    );
});

void test('needs-review latest generation is not automatically restored', () => {
    assert.equal(
        shouldAutomaticallyRestoreGeneration(
            status({ submission_status: 'needs_review' }),
            true,
        ),
        false,
    );
});

void test('queued latest generation is restored and continues polling', () => {
    const queued = status({ status: 'queued' });

    assert.equal(shouldAutomaticallyRestoreGeneration(queued, true), true);
    assert.equal(shouldPollGeneration(queued), true);
});

void test('processing latest generation is restored and continues polling', () => {
    const processing = status({ status: 'processing' });

    assert.equal(shouldAutomaticallyRestoreGeneration(processing, true), true);
    assert.equal(shouldPollGeneration(processing), true);
});

void test('no latest generation leaves Studio empty', () => {
    assert.equal(shouldAutomaticallyRestoreGeneration(null, true), false);
});

void test('user edits prevent delayed automatic restoration', () => {
    assert.equal(
        shouldAutomaticallyRestoreGeneration(
            status({ status: 'queued' }),
            false,
        ),
        false,
    );
});

void test('an active generation remains visible when it completes in this session', () => {
    const completed = status({
        status: 'completed',
        customer_charge_usd: '0.059450000',
        result_image_urls: ['/generations/8/images/0'],
    });

    assert.equal(generationDisplayStatus(true, completed), 'Completed');
    assert.equal(isGenerationProcessing(true, completed), false);
    assert.equal(shouldPollGeneration(completed), false);
});

void test('completed generations poll only while local images are unavailable', () => {
    const preparing = status({ status: 'completed', images_ready: false });
    const ready = status({
        status: 'completed',
        images_ready: true,
        expected_image_count: 1,
        result_image_urls: ['/generations/8/images/0'],
    });

    assert.equal(shouldPollGeneration(preparing), false);
    assert.equal(shouldPollImageReadiness(preparing), true);
    assert.equal(shouldPollImageReadiness(ready), false);
});

void test('an already hydrated generation is not overridden by automatic restoration', () => {
    assert.equal(
        shouldAutomaticallyRestoreGeneration(
            status({ status: 'processing' }),
            false,
        ),
        false,
    );
});
