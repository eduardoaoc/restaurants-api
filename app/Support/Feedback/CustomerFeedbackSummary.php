<?php

namespace App\Support\Feedback;

/**
 * Aggregate-only view of a waiter's customer feedback — never carries any
 * individual CustomerFeedback row or PII. See CustomerFeedbackSummaryResolver.
 */
readonly class CustomerFeedbackSummary
{
    public function __construct(
        public int $feedbackCount,
        public ?float $averageOverall,
        public ?float $averageService,
        public ?float $averageWaitTime,
        public ?float $averageFood,
    ) {}
}
