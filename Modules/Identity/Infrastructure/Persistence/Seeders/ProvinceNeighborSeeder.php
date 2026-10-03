<?php

namespace Modules\Identity\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Modules\Identity\Domain\Models\Province;
use Modules\Identity\Domain\Models\ProvinceNeighbor;

/**
 * Seeds the province adjacency matrix used by the Post shipping calculator to
 * classify a delivery as same-province / neighbouring / non-neighbouring.
 *
 * PRODUCTION-SAFE & IDEMPOTENT:
 *   - Provinces are resolved by NAME, never by a hardcoded numeric id, so the seeder
 *     is portable across databases with different id sequences.
 *   - firstOrCreate is used for every pair, so re-running never duplicates a row.
 *   - A province name that does not exist yet is skipped silently (additive), so the
 *     seeder never fails on a partially-seeded locations table.
 *   - Nothing is ever truncated or deleted.
 *
 * The adjacency below is keyed by the province names as they appear in
 * storage/app/locations.json (including the "(شمال)" suffixes on Gilan/Mazandaran).
 * It is stored as data — application logic never hardcodes province relations.
 */
class ProvinceNeighborSeeder extends Seeder
{
    /**
     * Province => its bordering provinces. Rows are written in BOTH directions, so a
     * one-sided entry here still produces a symmetric matrix in the database.
     *
     * @var array<string, list<string>>
     */
    private const ADJACENCY = [
        'آذربایجان شرقی' => ['آذربایجان غربی', 'اردبیل', 'زنجان'],
        'آذربایجان غربی' => ['آذربایجان شرقی', 'کردستان'],
        'اردبیل' => ['آذربایجان شرقی', 'زنجان', 'گیلان (شمال)'],
        'اصفهان' => ['مرکزی', 'قم', 'سمنان', 'یزد', 'فارس', 'کهگیلویه و بویراحمد', 'چهارمحال و بختیاری', 'لرستان'],
        'البرز' => ['تهران', 'قزوین', 'مازندران (شمال)', 'مرکزی'],
        'ایلام' => ['کرمانشاه', 'لرستان', 'خوزستان'],
        'بوشهر' => ['فارس', 'خوزستان', 'کهگیلویه و بویراحمد', 'هرمزگان'],
        'تهران' => ['البرز', 'سمنان', 'مازندران (شمال)', 'قم', 'مرکزی'],
        'چهارمحال و بختیاری' => ['اصفهان', 'خوزستان', 'کهگیلویه و بویراحمد', 'لرستان'],
        'خراسان جنوبی' => ['خراسان رضوی', 'سیستان و بلوچستان', 'کرمان', 'یزد', 'سمنان'],
        'خراسان رضوی' => ['خراسان شمالی', 'خراسان جنوبی', 'سمنان'],
        'خراسان شمالی' => ['خراسان رضوی', 'گلستان', 'سمنان'],
        'خوزستان' => ['ایلام', 'لرستان', 'چهارمحال و بختیاری', 'کهگیلویه و بویراحمد', 'بوشهر'],
        'زنجان' => ['آذربایجان شرقی', 'اردبیل', 'گیلان (شمال)', 'قزوین', 'همدان', 'کردستان'],
        'سمنان' => ['تهران', 'مازندران (شمال)', 'گلستان', 'خراسان شمالی', 'خراسان رضوی', 'خراسان جنوبی', 'اصفهان', 'قم'],
        'سیستان و بلوچستان' => ['خراسان جنوبی', 'کرمان', 'هرمزگان'],
        'فارس' => ['اصفهان', 'یزد', 'کرمان', 'هرمزگان', 'بوشهر', 'کهگیلویه و بویراحمد'],
        'قزوین' => ['البرز', 'زنجان', 'همدان', 'مرکزی', 'گیلان (شمال)', 'مازندران (شمال)'],
        'قم' => ['تهران', 'مرکزی', 'اصفهان', 'سمنان'],
        'کردستان' => ['آذربایجان غربی', 'زنجان', 'همدان', 'کرمانشاه'],
        'کرمان' => ['یزد', 'فارس', 'هرمزگان', 'سیستان و بلوچستان', 'خراسان جنوبی'],
        'کرمانشاه' => ['کردستان', 'همدان', 'لرستان', 'ایلام'],
        'کهگیلویه و بویراحمد' => ['اصفهان', 'چهارمحال و بختیاری', 'خوزستان', 'بوشهر', 'فارس'],
        'گلستان' => ['مازندران (شمال)', 'سمنان', 'خراسان شمالی'],
        'گیلان (شمال)' => ['اردبیل', 'زنجان', 'قزوین', 'مازندران (شمال)'],
        'لرستان' => ['همدان', 'مرکزی', 'اصفهان', 'خوزستان', 'چهارمحال و بختیاری', 'ایلام', 'کرمانشاه'],
        'مازندران (شمال)' => ['گیلان (شمال)', 'قزوین', 'البرز', 'تهران', 'سمنان', 'گلستان'],
        'مرکزی' => ['تهران', 'البرز', 'قزوین', 'همدان', 'لرستان', 'اصفهان', 'قم'],
        'هرمزگان' => ['بوشهر', 'فارس', 'کرمان', 'سیستان و بلوچستان'],
        'همدان' => ['کردستان', 'زنجان', 'قزوین', 'مرکزی', 'لرستان', 'کرمانشاه'],
        'یزد' => ['اصفهان', 'فارس', 'کرمان', 'خراسان جنوبی', 'سمنان'],
    ];

    public function run(): void
    {
        // Resolve name => id once (no hardcoded ids). Only names actually present in
        // the provinces table are considered — a missing province simply has no rows.
        $idByName = Province::query()->pluck('id', 'name');

        foreach (self::ADJACENCY as $provinceName => $neighbourNames) {
            $provinceId = $idByName->get($provinceName);

            if ($provinceId === null) {
                continue;
            }

            foreach ($neighbourNames as $neighbourName) {
                $neighbourId = $idByName->get($neighbourName);

                if ($neighbourId === null || $neighbourId === $provinceId) {
                    continue;
                }

                // Store both directions so the matrix is symmetric regardless of which
                // side the pair was declared on. firstOrCreate keeps it idempotent.
                ProvinceNeighbor::firstOrCreate([
                    'province_id' => $provinceId,
                    'neighbor_province_id' => $neighbourId,
                ]);

                ProvinceNeighbor::firstOrCreate([
                    'province_id' => $neighbourId,
                    'neighbor_province_id' => $provinceId,
                ]);
            }
        }
    }
}
