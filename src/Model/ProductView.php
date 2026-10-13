<?php

declare(strict_types=1);

namespace SilverShop\Analytics\Model;

use SilverShop\Page\Product;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Security\Member;

/**
 * One row per product-page view — the raw append log behind the most-viewed report.
 *
 * Privacy by design: stores no raw PII. The optional {@link $VisitorHash} is a one-way hash of an anonymous
 * per-session token (not an IP or a login), used only to approximate unique-viewer counts; the member link is
 * populated only when ProductViewTrackingExtension.track_members is enabled. Raw rows are transient — they are
 * pruned after {@link $retention_days} by {@link \SilverShop\Analytics\Task\PruneProductViewsTask}.
 *
 * @property string $VisitorHash
 */
class ProductView extends DataObject
{
    private static string $table_name = 'SilverShop_ProductView';

    private static array $db = [
        'VisitorHash' => 'Varchar(40)',
    ];

    private static array $has_one = [
        'Product' => Product::class,
        'Member' => Member::class,
    ];

    private static array $indexes = [
        'ProductID' => true,
        'Created' => true,
    ];

    private static string $default_sort = '"Created" DESC';

    /**
     * How many days of raw view rows to keep. The most-viewed report reads these rows directly, so this also
     * bounds how far back the report can look; shops that need longer history should raise it (a daily rollup
     * is a planned follow-up). 0 disables pruning.
     */
    private static int $retention_days = 365;

    /**
     * Record a single product view. Returns the written row, or null when there is nothing to record.
     */
    public static function record(int $productID, ?string $visitorHash = null, int $memberID = 0): ?self
    {
        if ($productID <= 0) {
            return null;
        }

        $view = self::create();
        $view->ProductID = $productID;
        $view->VisitorHash = $visitorHash;
        $view->MemberID = $memberID;
        $view->write();

        return $view;
    }

    /**
     * Product IDs ranked by recorded views, most-viewed first — a read-only helper for surfaces that want a
     * "most viewed" / "popular right now" list (e.g. a storefront search dropdown). Reads the raw view log
     * directly, so figures are live. $sinceDays > 0 limits to views in the last N days (a "recent" window);
     * 0 counts all retained rows. Callers load the products themselves and apply their own visibility rules
     * (this intentionally returns IDs, not objects, and does not care whether a product still exists).
     *
     * @return list<int> ordered product IDs, most-viewed first (at most $limit)
     */
    public static function topProductIDs(int $limit = 4, int $sinceDays = 0): array
    {
        if ($limit <= 0) {
            return [];
        }

        try {
            $query = SQLSelect::create();
            $query->setSelect([
                'ProductID' => '"ProductID"',
                'Views' => 'COUNT("ID")',
            ]);
            $query->setFrom('"SilverShop_ProductView"');

            if ($sinceDays > 0) {
                $cutoff = date('Y-m-d H:i:s', strtotime("-{$sinceDays} days"));
                $query->addWhere(['"Created" >= ?' => $cutoff]);
            }

            $query->setGroupBy('"ProductID"');
            $query->setOrderBy('COUNT("ID")', 'DESC');
            $query->setLimit($limit);

            $ids = [];
            foreach ($query->execute() as $row) {
                $id = (int) $row['ProductID'];
                if ($id > 0) {
                    $ids[] = $id;
                }
            }

            return $ids;
        } catch (\Throwable $e) {
            // Table may not exist yet (pre dev/build) or a query/dialect error — never let a "popular" widget fatal.
            return [];
        }
    }

    /**
     * Delete raw view rows older than $days (defaults to the configured retention). Returns the number removed.
     */
    public static function prune(?int $days = null): int
    {
        $days = $days ?? (int) self::config()->get('retention_days');
        if ($days <= 0) {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $stale = self::get()->filter('Created:LessThan', $cutoff);
        $count = (int) $stale->count();

        if ($count > 0) {
            DB::prepared_query(
                'DELETE FROM "SilverShop_ProductView" WHERE "Created" < ?',
                [$cutoff]
            );
        }

        return $count;
    }
}
