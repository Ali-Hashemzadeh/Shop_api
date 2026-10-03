<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Sources;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\ProductReviewAI\Domain\Contracts\ReviewSourceInterface;
use Modules\ProductReviewAI\Domain\DTOs\ExternalProductDTO;
use Modules\ProductReviewAI\Domain\DTOs\ExternalReviewDTO;
use Modules\ProductReviewAI\Domain\Exceptions\ExternalSourceException;
use Modules\ProductReviewAI\Domain\Exceptions\IntegrationUnavailableException;

/**
 * Digikala marketplace adapter. Uses the public Digikala JSON APIs — no HTML
 * scraping. Fails loudly: missing configuration throws
 * IntegrationUnavailableException, a failed call throws ExternalSourceException.
 * It never returns fabricated data.
 *
 * Field paths follow the observed Digikala API shape and are parsed defensively
 * (multiple fallbacks) so a minor upstream change degrades to "no data" rather
 * than a crash.
 */
class DigikalaSource implements ReviewSourceInterface
{
    public function code(): string
    {
        return 'digikala';
    }

    public function searchProducts(string $query, int $limit = 5): array
    {
        $response = $this->get($this->config('search_path', '/discovery/api/v2/search'), [
            'q' => $query,
            'columns_per_page' => $limit,
            'page' => 1,
        ]);

        $products = $this->extractProducts($response);

        return array_values(array_map(
            fn ($product): ExternalProductDTO => $this->toProductDTO((array) $product),
            array_slice($products, 0, $limit),
        ));
    }

    /**
     * The v2 discovery search returns a nested widget layout: the outer
     * `vertical_product_listing` widget carries its own `data.widgets[]`, and
     * each product is an entry shaped `{ "type": "product", "data": {...} }`
     * (arbitrarily nested). We walk the tree and collect every such product
     * entry's `data`. Flat layouts (`data.products`) remain as fallbacks so a
     * shape change degrades to "no matches" rather than a crash.
     *
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function extractProducts(array $response): array
    {
        $products = [];
        $this->collectProductEntries(data_get($response, 'data.widgets', []), $products);

        if ($products !== []) {
            return $products;
        }

        foreach (['data.products', 'data.product_list', 'data.search.products', 'products'] as $path) {
            $flat = data_get($response, $path);

            if (is_array($flat) && $flat !== []) {
                return array_values($flat);
            }
        }

        return [];
    }

    /**
     * Recursively gather `{type:"product", data:{id,...}}` entries. Only product
     * wrappers are collected, so filter/brand nodes (which also carry ids) are
     * never mistaken for products.
     *
     * @param  list<array<string, mixed>>  $products
     */
    private function collectProductEntries(mixed $node, array &$products): void
    {
        if (! is_array($node)) {
            return;
        }

        if (($node['type'] ?? null) === 'product' && is_array($node['data'] ?? null) && isset($node['data']['id'])) {
            $products[] = $node['data'];

            return;
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collectProductEntries($child, $products);
            }
        }
    }

    public function getProduct(string $externalId): ExternalProductDTO
    {
        $externalId = $this->normalizeId($externalId);

        $response = $this->get("/v1/product/{$externalId}/", []);
        $product = (array) (data_get($response, 'data.product') ?? []);

        if ($product === []) {
            // Fall back to the id/URL we already know, so the caller still gets
            // a usable DTO rather than an exception for a valid id.
            return new ExternalProductDTO(
                externalId: $externalId,
                title: (string) data_get($response, 'data.product.title_fa', ''),
                url: $this->webUrl($externalId),
            );
        }

        return $this->toProductDTO($product);
    }

    public function getReviews(string $externalId, int $limit): array
    {
        $externalId = $this->normalizeId($externalId);
        $path = str_replace('{product_id}', $externalId, $this->config('reviews_path', '/v1/rate-review/products/{product_id}/'));
        $maxPages = max(1, (int) $this->config('max_pages', 30));

        $collected = [];
        $page = 1;

        while (count($collected) < $limit && $page <= $maxPages) {
            $response = $this->get($path, ['page' => $page]);

            // Real shape: data.comments[]. Older/alternate shapes kept as fallbacks.
            $items = (array) (data_get($response, 'data.comments')
                ?? data_get($response, 'data.reviews.items')
                ?? data_get($response, 'data.reviews')
                ?? []);

            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                $item = (array) $item;
                $body = trim((string) (data_get($item, 'body') ?? data_get($item, 'text') ?? ''));

                if ($body === '') {
                    continue;
                }

                // Digikala uses `rate` (0 = a non-buyer wish/comment with no star).
                $rating = data_get($item, 'rate') ?? data_get($item, 'rating');
                $rating = is_numeric($rating) && (int) $rating >= 1 && (int) $rating <= 5 ? (int) $rating : null;

                $collected[] = new ExternalReviewDTO(
                    rating: $rating,
                    title: ($t = trim((string) (data_get($item, 'title') ?? ''))) !== '' ? $t : null,
                    body: $body,
                    raw: $item,
                );

                if (count($collected) >= $limit) {
                    break;
                }
            }

            $totalPages = (int) (data_get($response, 'data.pager.total_pages') ?? 0);
            if ($totalPages > 0 && $page >= $totalPages) {
                break;
            }

            $page++;
        }

        return $collected;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function toProductDTO(array $product): ExternalProductDTO
    {
        $id = (string) (data_get($product, 'id') ?? '');
        $title = (string) (data_get($product, 'title_fa')
            ?? data_get($product, 'title_en')
            ?? data_get($product, 'title')
            ?? '');

        $uri = data_get($product, 'url.uri');
        $url = is_string($uri) && $uri !== ''
            ? rtrim($this->config('web_base_url', 'https://www.digikala.com'), '/').$uri
            : $this->webUrl($id);

        return new ExternalProductDTO(externalId: $id, title: $title, url: $url);
    }

    private function webUrl(string $id): ?string
    {
        if ($id === '') {
            return null;
        }

        return rtrim($this->config('web_base_url', 'https://www.digikala.com'), '/')."/product/dkp-{$id}/";
    }

    /**
     * The marketplace id may arrive as `123`, `dkp-123`, or `DKP-123`.
     */
    private function normalizeId(string $externalId): string
    {
        return ltrim(preg_replace('/^dkp-/i', '', trim($externalId)) ?? '', '-');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        $baseUrl = rtrim((string) $this->config('base_url', ''), '/');

        if ($baseUrl === '') {
            throw new IntegrationUnavailableException(
                'Digikala is not configured: set DIGIKALA_BASE_URL. External review collection cannot start.'
            );
        }

        $url = $baseUrl.$path;

        try {
            $response = Http::acceptJson()
                ->timeout((int) $this->config('timeout', 30))
                ->withHeaders(['User-Agent' => 'Shop-API/1.0 (+product-review-ai)'])
                ->get($url, $query);
        } catch (\Throwable $e) {
            Log::warning('ProductReviewAI Digikala request errored', [
                'url' => $url,
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            throw new ExternalSourceException("Digikala request to [{$path}] failed: {$e->getMessage()}", 0, $e);
        }

        if ($response->failed()) {
            Log::warning('ProductReviewAI Digikala request failed', [
                'url' => $url,
                'query' => $query,
                'status' => $response->status(),
                'response' => mb_substr($response->body(), 0, 4000),
            ]);

            throw new ExternalSourceException("Digikala request to [{$path}] returned HTTP {$response->status()}.");
        }

        Log::info('ProductReviewAI Digikala request', [
            'url' => $url,
            'query' => $query,
            'status' => $response->status(),
            'response' => mb_substr($response->body(), 0, 4000),
        ]);

        return (array) $response->json();
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("product_review_ai.sources.digikala.{$key}", $default);
    }
}
