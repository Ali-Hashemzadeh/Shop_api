<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Requests;

/**
 * Authorize-only request for read + no-body admin actions (list, show, approve,
 * reject). Gives 403 before the controller runs for unauthorized callers.
 */
class ManageAiReviewsRequest extends ProductReviewAIRequest {}
