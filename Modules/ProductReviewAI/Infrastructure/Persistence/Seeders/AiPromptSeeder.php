<?php

namespace Modules\ProductReviewAI\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Modules\ProductReviewAI\Application\Support\PromptResolver;
use Modules\ProductReviewAI\Domain\Models\AiPrompt;

/**
 * Seeds v1 of the two-stage prompts. Prompts are versioned; editing a prompt is
 * a new version, so past generations keep the exact text they used.
 */
class AiPromptSeeder extends Seeder
{
    public function run(): void
    {
        $model = (string) config('product_review_ai.ai.providers.avalai.model', 'deepseek-v4.1-flash');

        AiPrompt::query()->updateOrCreate(
            ['name' => PromptResolver::ANALYSIS, 'version' => 1],
            [
                'model' => $model,
                'active' => true,
                'system_prompt' => <<<'PROMPT'
                    You are a product-review analyst for an Iranian e-commerce store. You read
                    real customer reviews collected from external marketplaces and distil them.
                    Respond with a single JSON object and nothing else, using exactly these keys:
                    "positive_points", "negative_points", "customer_profiles", "important_features".
                    Every key is an array of short Persian (Farsi) strings. Base every point only
                    on the supplied reviews — never invent facts. Do not include any prose outside
                    the JSON object.
                    PROMPT,
                'user_prompt' => <<<'PROMPT'
                    محصول: {{product_title}}
                    توضیحات: {{product_description}}

                    تعداد نظرات جمع‌آوری‌شده: {{review_count}}
                    نظرات مشتریان (JSON):
                    {{reviews_json}}

                    این نظرات را تحلیل کن و خروجی را دقیقاً به صورت JSON با کلیدهای
                    positive_points، negative_points، customer_profiles و important_features بده.
                    PROMPT,
            ],
        );

        AiPrompt::query()->updateOrCreate(
            ['name' => PromptResolver::GENERATION, 'version' => 1],
            [
                'model' => $model,
                'active' => true,
                'system_prompt' => <<<'PROMPT'
                    You write authentic, first-person Persian (Farsi) customer reviews for an
                    Iranian e-commerce store, in the natural writing style of real Iranian
                    shoppers. Respond with a single JSON object and nothing else, shaped as:
                    { "reviews": [ { "name": "<random Persian full name>", "rating": <integer 1-5>,
                    "title": "<short Persian title>", "body": "<Persian review text>" } ] }.
                    Rules: each review has a distinct personality and voice; ratings are realistic
                    and varied — NOT all 5 stars — and reflect the analysis (include some critical
                    and mixed reviews with genuine pros and cons); use realistic, varied random
                    Persian names; never mention that the review is AI-generated; do not include
                    any prose outside the JSON object.
                    PROMPT,
                'user_prompt' => <<<'PROMPT'
                    محصول: {{product_title}}
                    توضیحات: {{product_description}}

                    تحلیل نظرات واقعی (JSON):
                    {{analysis_json}}

                    بر اساس این تحلیل، دقیقاً {{count}} نظر مشتری فارسی و طبیعی تولید کن.
                    امتیازها باید واقع‌بینانه و متنوع باشند (همه ۵ ستاره نباشند). خروجی فقط JSON با
                    کلید reviews باشد.
                    PROMPT,
            ],
        );
    }
}
