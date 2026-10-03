<?php

namespace Bizzsol\ApprovalMatrix\Support;

use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Lets the consuming app tell the approver inbox how to open a document and how to call it:
 *
 *   DocumentLinks::register('procurement.purchase_order', fn (Model $po) => ['url' => route(...), 'label' => 'PO '.$po->reference_no]);
 *
 * Unregistered document types simply show "<Type> #id" without a link.
 */
class DocumentLinks
{
    /** @var array<string,Closure> */
    private static array $resolvers = [];

    public static function register(string $documentType, Closure $resolver): void
    {
        self::$resolvers[$documentType] = $resolver;
    }

    public static function flush(): void
    {
        self::$resolvers = [];
    }

    /** @return array{url:?string,label:string}|null */
    public static function describe(ApprovalRequest $request): ?array
    {
        $resolver = self::$resolvers[$request->document_type] ?? null;
        $document = $resolver ? $request->approvable : null;
        if (! $resolver || ! $document instanceof Model) {
            return null;
        }
        $described = $resolver($document);

        return is_array($described) && isset($described['label']) ? $described + ['url' => null] : null;
    }
}
