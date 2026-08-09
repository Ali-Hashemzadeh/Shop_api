<?php

namespace Modules\Catalog\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;

class CatalogSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        // --- Categories ---
        $art = $this->seedCategory('هنری', 'art');
        $artColor = $this->seedCategory('رنگ', 'art-color', $art->id);
        $coloredPencil = $this->seedCategory('مداد رنگی', 'colored-pencil', $artColor->id);
        $oilPaint = $this->seedCategory('رنگ روغن', 'oil-paint', $artColor->id);
        $polychromos = $this->seedCategory('مداد رنگی پلی کروم', 'polychromos-colored-pencil', $artColor->id);
        $watercolor = $this->seedCategory('آبرنگ', 'watercolor', $artColor->id);

        $brush = $this->seedCategory('قلمو', 'brush', $art->id);
        $brushSet = $this->seedCategory('ست قلمو', 'brush-set', $brush->id);
        $singleBrush = $this->seedCategory('قلمو', 'single-brush', $brush->id);

        $artCardboard = $this->seedCategory('مقوا هنری', 'art-cardboard', $art->id);
        $fabriano = $this->seedCategory('فابریانو', 'fabriano', $artCardboard->id);
        $stenbach = $this->seedCategory('اشتنباخ', 'stenbach', $artCardboard->id);

        $paintingCanvas = $this->seedCategory('بوم نقاشی', 'painting-canvas', $art->id);
        $calligraphy = $this->seedCategory('خوشنویسی', 'calligraphy', $art->id);
        $drawingNotebooks = $this->seedCategory('دفاتر طراحی', 'drawing-notebooks', $art->id);

        $blackPencilDrawing = $this->seedCategory('طراحی سیاه قلم', 'black-pencil-drawing', $art->id);
        $mechanicalEraser = $this->seedCategory('پاکن اتودی', 'mechanical-eraser', $blackPencilDrawing->id);
        $drawingPencil = $this->seedCategory('مداد طراحی', 'drawing-pencil', $blackPencilDrawing->id);
        $conte = $this->seedCategory('کنته', 'conte', $blackPencilDrawing->id);
        $drawingMechanicalPencil = $this->seedCategory('اتود و مغز طراحی', 'drawing-mechanical-pencil-and-lead', $blackPencilDrawing->id);

        $engineering = $this->seedCategory('مهندسی و معماری', 'engineering-and-architecture');
        $technicalPen = $this->seedCategory('راپید', 'technical-pen', $engineering->id);
        $modelMaking = $this->seedCategory('ماکت سازی', 'model-making', $engineering->id);
        $renderMarker = $this->seedCategory('ماژیک راندو', 'render-marker', $engineering->id);
        $engineeringRuler = $this->seedCategory('خط کش و شابلون مهندسی', 'engineering-ruler-and-template', $engineering->id);
        $drawingBoard = $this->seedCategory('تخته رسم', 'drawing-board', $engineering->id);
        $archiveBag = $this->seedCategory('کیف آرشیو', 'archive-bag', $engineering->id);
        $engineeringCompass = $this->seedCategory('پرگار مهندسی', 'engineering-compass', $engineering->id);
        $cutter = $this->seedCategory('کاتر', 'cutter', $engineering->id);

        $bags = $this->seedCategory('کیف کوله و جامدادی', 'bags-backpacks-and-pencil-cases');
        $pencilCase = $this->seedCategory('جامدادی', 'pencil-case', $bags->id);
        $lunchBag = $this->seedCategory('کیف غذا', 'lunch-bag', $bags->id);

        $bottlesAndLunchBoxes = $this->seedCategory('قمقمه و ظرف غذا', 'bottles-and-lunch-boxes');
        $waterBottle = $this->seedCategory('قمقمه', 'water-bottle', $bottlesAndLunchBoxes->id);
        $lunchBox = $this->seedCategory('ظرف غذا', 'lunch-box', $bottlesAndLunchBoxes->id);

        $giftsAndAccessories = $this->seedCategory('اکسسوری و کادویی', 'accessories-and-gifts');

        $stationery = $this->seedCategory('لوازم تحریر', 'stationery');

        $coloringAndEntertainment = $this->seedCategory('وسایل رنگ آمیزی و سرگرمی', 'coloring-and-entertainment', $stationery->id);
        $studentPaper = $this->seedCategory('کاغذ و مقوا دانش آموزی', 'student-paper-and-cardboard', $coloringAndEntertainment->id);
        $stationeryColoredPencil = $this->seedCategory('مداد رنگی تحریر', 'stationery-colored-pencil', $coloringAndEntertainment->id);
        $studentGouache = $this->seedCategory('گواش و آبرنگ دانش آموزی', 'student-gouache-and-watercolor', $coloringAndEntertainment->id);
        $playDough = $this->seedCategory('خمیر و شن بازی', 'play-dough-and-sand', $coloringAndEntertainment->id);
        $stationerySet = $this->seedCategory('ست تحریر', 'stationery-set', $coloringAndEntertainment->id);
        $clay = $this->seedCategory('گل رس', 'clay', $coloringAndEntertainment->id);
        $globe = $this->seedCategory('کره جغرافیا', 'globe', $coloringAndEntertainment->id);
        $kindergartenCover = $this->seedCategory('کاور روپوش مهد کودک', 'kindergarten-smock-cover', $coloringAndEntertainment->id);

        $writingSupplies = $this->seedCategory('نوشت افزار', 'writing-supplies', $stationery->id);
        $pencil = $this->seedCategory('مداد', 'pencil', $writingSupplies->id);
        $pen = $this->seedCategory('خودکار', 'pen', $writingSupplies->id);
        $penSet = $this->seedCategory('ست خودکار', 'pen-set', $writingSupplies->id);
        $eraser = $this->seedCategory('پاک کن', 'eraser', $writingSupplies->id);
        $sharpener = $this->seedCategory('تراش', 'sharpener', $writingSupplies->id);
        $nameLabel = $this->seedCategory('برچسب نام و نشان', 'name-label', $writingSupplies->id);
        $rulerTemplate = $this->seedCategory('خط کش و شابلون', 'ruler-and-template', $writingSupplies->id);
        $compass = $this->seedCategory('پرگار', 'compass', $writingSupplies->id);
        $whiteboardMarker = $this->seedCategory('ماژیک وایت برد', 'whiteboard-marker', $writingSupplies->id);
        $whiteboardEraser = $this->seedCategory('تخته پاک کن', 'whiteboard-eraser', $writingSupplies->id);
        $permanentMarker = $this->seedCategory('ماژیک ثابت (غیر وایت برد)', 'permanent-marker', $writingSupplies->id);
        $cdMarker = $this->seedCategory('ماژیک سی دی', 'cd-marker', $writingSupplies->id);
        $mechanicalPencil = $this->seedCategory('اتود', 'mechanical-pencil', $writingSupplies->id);
        $mechanicalPencilLead = $this->seedCategory('مغز اتود', 'mechanical-pencil-lead', $writingSupplies->id);
        $highlighter = $this->seedCategory('ماژیک علامت گذار (هایلایتر)', 'highlighter', $writingSupplies->id);
        $rollerballPen = $this->seedCategory('روان نویس', 'rollerball-pen', $writingSupplies->id);
        $correctionSupplies = $this->seedCategory('غلط گیر', 'correction-supplies', $writingSupplies->id);

        $notebooks = $this->seedCategory('دفتر یادداشت', 'notebooks', $stationery->id);
        $binder = $this->seedCategory('کلاسور', 'binder', $notebooks->id);
        $singleLineNotebook = $this->seedCategory('دفتر تک خط', 'single-line-notebook', $notebooks->id);
        $binderPaper = $this->seedCategory('کاغذ کلاسور', 'binder-paper', $notebooks->id);
        $notepad = $this->seedCategory('دفترچه', 'notepad', $notebooks->id);
        $gridNotebook = $this->seedCategory('دفتر شطرنجی', 'grid-notebook', $notebooks->id);
        $drawingNotebook = $this->seedCategory('دفتر نقاشی', 'drawing-notebook', $notebooks->id);

        $adhesives = $this->seedCategory('چسب', 'adhesives', $stationery->id);
        $wideTape = $this->seedCategory('چسب نواری پهن (۵ سانت)', 'wide-tape-5cm', $adhesives->id);
        $narrowTape = $this->seedCategory('چسب نواری باریک (شیشه ای)', 'narrow-clear-tape', $adhesives->id);
        $maskingTape = $this->seedCategory('چسب کاغذی', 'masking-tape', $adhesives->id);
        $liquidGlue = $this->seedCategory('چسب مایع', 'liquid-glue', $adhesives->id);
        $glueStick = $this->seedCategory('چسب جامد (ماتیکی)', 'glue-stick', $adhesives->id);
        $doubleSidedTape = $this->seedCategory('چسب دو طرفه', 'double-sided-tape', $adhesives->id);
        $bookCover = $this->seedCategory('جلد کتاب و دفتر', 'book-and-notebook-cover', $adhesives->id);

        $officeSupplies = $this->seedCategory('ملزومات اداری', 'office-supplies');

        $filingSupplies = $this->seedCategory('لوازم بایگانی', 'filing-supplies', $officeSupplies->id);
        $leverArchFile = $this->seedCategory('زونکن', 'lever-arch-file', $filingSupplies->id);
        $foldersAndFiles = $this->seedCategory('پوشه و فایل', 'folders-and-files', $filingSupplies->id);
        $clearBookBinder = $this->seedCategory('کلیر بوک و کلاسور', 'clear-book-and-binder', $filingSupplies->id);
        $documentSleeve = $this->seedCategory('کاور کاغذ', 'document-sleeve', $filingSupplies->id);
        $envelope = $this->seedCategory('پاکت نامه', 'envelope', $filingSupplies->id);
        $buttonFolder = $this->seedCategory('پوشه دکمه دار', 'button-folder', $filingSupplies->id);
        $lockFolder = $this->seedCategory('پوشه قفل دار', 'lock-folder', $filingSupplies->id);
        $fileBag = $this->seedCategory('کیف فایل', 'file-bag', $filingSupplies->id);

        $staplers = $this->seedCategory('منگنه', 'staplers', $officeSupplies->id);
        $desktopStapler = $this->seedCategory('منگنه رومیزی', 'desktop-stapler', $staplers->id);
        $staples = $this->seedCategory('سوزن منگنه', 'staples', $staplers->id);

        $holePunch = $this->seedCategory('پانچ', 'hole-punch', $officeSupplies->id);

        $paper = $this->seedCategory('کاغذ', 'paper', $officeSupplies->id);
        $a4Paper = $this->seedCategory('کاغذ A4', 'a4-paper', $paper->id);
        $a5Paper = $this->seedCategory('کاغذ A5', 'a5-paper', $paper->id);
        $a3Paper = $this->seedCategory('کاغذ A3', 'a3-paper', $paper->id);
        $glossyPaper = $this->seedCategory('کاغذ گلاسه', 'glossy-paper', $paper->id);

        $whiteboard = $this->seedCategory('وایت برد', 'whiteboard', $officeSupplies->id);
        $whiteboardBoard = $this->seedCategory('تخته وایت برد', 'whiteboard-board', $whiteboard->id);

        $accountingBooksAndInvoices = $this->seedCategory('دفاتر حسابداری و فاکتور', 'accounting-books-and-invoices', $officeSupplies->id);
        $invoiceBook = $this->seedCategory('فاکتور', 'invoice-book', $accountingBooksAndInvoices->id);
        $accountingBooks = $this->seedCategory('دفاتر حسابداری', 'accounting-books', $accountingBooksAndInvoices->id);

        $desktopSupplies = $this->seedCategory('لوازم رومیزی', 'desktop-supplies', $officeSupplies->id);
        $stampPad = $this->seedCategory('استامپ', 'stamp-pad', $desktopSupplies->id);
        $deskTray = $this->seedCategory('کازیه رومیزی', 'desk-tray', $desktopSupplies->id);
        $clipsAndClamps = $this->seedCategory('کلیپس و گیره', 'clips-and-clamps', $desktopSupplies->id);

        $wholesale = $this->seedCategory('عمده', 'wholesale');

        // --- Products & Variants ---
        $this->seedProduct(
            category: $stationeryColoredPencil,
            title: 'مداد رنگی ۱۲ رنگ فابر کاستل',
            slug: 'faber-castell-12-color-pencils',
            description: 'مداد رنگی ۱۲ رنگ مناسب استفاده دانش‌آموزی و روزمره با رنگ‌های شفاف و روان.',
            variants: [
                ['price' => 45_000_000, 'attrs' => ['storage' => '12-color', 'color' => 'multi']],
                ['price' => 52_000_000, 'attrs' => ['storage' => '24-color', 'color' => 'multi']],
                ['price' => 62_000_000, 'attrs' => ['storage' => '36-color', 'color' => 'multi']],
            ],
        );

        $this->seedProduct(
            category: $pen,
            title: 'خودکار پنتر مدل SP-101',
            slug: 'panter-sp-101-pen',
            description: 'خودکار روان و خوش‌دست پنتر مناسب نوشتن روزمره، مدرسه و محیط اداری.',
            variants: [
                ['price' => 60_000_000, 'attrs' => ['storage' => 'single', 'color' => 'blue']],
                ['price' => 70_000_000, 'attrs' => ['storage' => 'single', 'color' => 'black']],
            ],
        );

        $this->seedProduct(
            category: $drawingPencil,
            title: 'مداد طراحی فابر کاستل سری 9000',
            slug: 'faber-castell-9000-drawing-pencil',
            description: 'مداد طراحی حرفه‌ای مناسب طراحی، اسکیس و سیاه قلم با درجه‌های مختلف سختی.',
            variants: [
                ['price' => 120_000_000, 'attrs' => ['chip' => 'HB', 'ram' => 'single', 'storage' => 'drawing']],
                ['price' => 170_000_000, 'attrs' => ['chip' => '2B', 'ram' => 'single', 'storage' => 'drawing']],
            ],
        );

        $this->seedProduct(
            category: $technicalPen,
            title: 'راپید یونی پین ۰.۵',
            slug: 'uni-pin-fineliner-05',
            description: 'راپید یونی پین با نوک ۰.۵ میلی‌متر مناسب طراحی فنی، معماری و نقشه‌کشی.',
            variants: [
                ['price' => 15_000_000, 'attrs' => ['color' => 'black']],
            ],
        );

        $this->seedProduct(
            category: $a4Paper,
            title: 'کاغذ A4 کپی مکس بسته ۵۰۰ عددی',
            slug: 'copymax-a4-paper-500-sheets',
            description: 'کاغذ A4 مناسب پرینتر، کپی و مصرف روزانه اداری در بسته ۵۰۰ عددی.',
            variants: [
                ['price' => 3_500_000, 'attrs' => ['color' => 'white']],
                ['price' => 3_500_000, 'attrs' => ['color' => 'white-premium']],
            ],
        );

        $this->command->info('Catalog sample data seeded: 5 products across 3 categories.');

    }

    /**
     * Idempotent category upsert that still assigns a public code.
     *
     * firstOrCreate cannot carry `public_code` (it is deliberately not
     * mass-assignable) and the `creating` hook is muted under WithoutModelEvents,
     * so a new row sets the code explicitly via the module's own generator.
     */
    private function seedCategory(string $name, string $slug, ?int $parentId = null): Category
    {
        $category = Category::query()->where('slug', $slug)->first();

        if ($category !== null) {
            return $category;
        }

        $category = new Category([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'parent_id' => $parentId,
        ]);

        $category->public_code = Category::generateUniquePublicCode();
        $category->save();

        return $category;
    }

    /** @param array<int,array{price:int,attrs:array<string,string>}> $variants */
    private function seedProduct(Category $category, string $title, string $slug, string $description, array $variants): void
    {
        // Seeders run under WithoutModelEvents, which mutes the `creating` hook that
        // normally assigns the code — and `uuid` is deliberately not mass-assignable,
        // so it cannot be passed through firstOrCreate either. Build the model and set
        // the code explicitly via the module's own generator, so seeded products get
        // their identifier exactly the way a real POST /products does.
        $product = Product::query()->where('slug', $slug)->first();

        if ($product === null) {
            $product = new Product([
                'category_id' => $category->id,
                'title' => $title,
                'slug' => $slug,
                'description' => $description,
                'status' => 'published',
            ]);
            $product->uuid = Product::generateUniquePublicCode();
            $product->save();
        }

        if ($product->variants()->count() > 0) {
            return;
        }

        foreach ($variants as $i => $v) {
            $variant = new ProductVariant([
                'product_id' => $product->id,
                'type' => 'color',
                'is_default' => $i === 0,
                'base_price' => $v['price'],
                'attributes' => $v['attrs'],
            ]);
            // Same story as the product code: `sku` is server-owned, not fillable,
            // and the creating hook is muted here.
            $variant->sku = ProductVariant::generateUniqueSku();
            $variant->save();
        }
    }
}
