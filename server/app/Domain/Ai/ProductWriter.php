<?php

namespace App\Domain\Ai;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Product;
use App\Security\Authorizer;
use Illuminate\Support\Facades\DB;

/**
 * Drafts an online-shop description for a product from what the system knows (name, category, brand, pack sizes,
 * details such as strength or size). The draft is shown for editing; saving goes through the normal product save.
 */
final class ProductWriter
{
    public function __construct(private readonly AiGateway $gateway, private readonly Authorizer $authz) {}

    public function draft(Actor $actor, Product $product, string $tone = 'friendly'): string
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, 'catalog.manage')) {
            throw new AccessDenied;
        }
        $facts = array_filter([
            'Name' => $product->name, 'SKU' => $product->sku,
            'Category' => DB::table('categories')->where('id', $product->category_id)->value('name'),
            'Brand' => DB::table('brands')->where('id', $product->brand_id)->value('name'),
            'Sold as' => $product->units()->with('uom')->get()->map(fn ($u) => $u->uom->name.($u->is_base ? '' : ' of '.rtrim(rtrim((string) $u->factor, '0'), '.')))->implode(', '),
            'Size' => $product->size, 'Colour' => $product->colour, 'Strength' => $product->strength, 'Form' => $product->dosage_form, 'Generic name' => $product->generic_name,
            'Current description' => $product->description,
        ], fn ($v) => $v !== null && $v !== '');
        $provider = $this->gateway->provider($actor->tenantId);
        $reply = $this->gateway->chat($actor, 'product_text', $provider,
            "You write product descriptions for a Ghanaian business's online shop. Tone: {$tone}. Write 2 to 4 short sentences in plain British English: what it is and who it suits, using only the facts given. "
            .'Never invent ingredients, health claims, sizes, prices or guarantees. For medicines add: "Read the label; ask the pharmacist." No headings, no emojis, no quotation marks around the text.',
            [['role' => 'user', 'content' => collect($facts)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n")]]);
        $text = trim($reply->text, " \n\"'");
        if ($text === '') {
            throw new DomainException('The AI returned nothing; try again.');
        }

        return mb_substr($text, 0, 2000);
    }
}
