import assert from 'node:assert/strict';
import test from 'node:test';

import {
    InvalidGenerationResponseError,
    parseGenerationStatusResponse,
    parseGenerationSubmissionResponse,
} from '../../resources/js/pages/studio/generationApi.ts';

const requestKey = '11111111-1111-4111-8111-111111111111';

void test('valid submission and status responses are accepted', () => {
    const submission = parseGenerationSubmissionResponse(
        {
            id: 9,
            request_key: requestKey,
            status: 'queued',
            estimated_charge_units: 86_268_750,
            estimated_charge_usd: '0.086268750',
        },
        requestKey,
    );
    const status = parseGenerationStatusResponse(
        {
            id: 9,
            status: 'completed',
            submission_status: 'accepted',
            estimated_charge_usd: '0.086268750',
            customer_charge_usd: '0.059450000',
            result_image_urls: [],
            error_message: null,
            completed_at: '2026-11-19T12:04:14+00:00',
        },
        9,
    );

    assert.equal(submission.request_key, requestKey);
    assert.equal(status.status, 'completed');
});

void test('wrapped, malformed, unknown, and mismatched responses are rejected', () => {
    const malformed = [
        { data: { id: 9, status: 'completed' } },
        { id: 9, status: 'complete' },
        {
            id: 10,
            status: 'completed',
            submission_status: 'accepted',
            estimated_charge_usd: '0.01',
            customer_charge_usd: null,
            result_image_urls: [],
            error_message: null,
            completed_at: null,
        },
    ];

    for (const value of malformed) {
        assert.throws(
            () => parseGenerationStatusResponse(value, 9),
            InvalidGenerationResponseError,
        );
    }

    assert.throws(
        () =>
            parseGenerationSubmissionResponse(
                {
                    id: 9,
                    request_key: '22222222-2222-4222-8222-222222222222',
                    status: 'queued',
                    estimated_charge_units: 1,
                    estimated_charge_usd: '0.01',
                },
                requestKey,
            ),
        InvalidGenerationResponseError,
    );
});
