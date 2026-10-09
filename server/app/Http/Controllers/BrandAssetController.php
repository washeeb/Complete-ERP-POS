<?php

namespace App\Http\Controllers;

use App\Domain\Branding\Branding;
use App\Models\BrandAsset;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A company's logo, dark logo, icon or monogram placeholder, for screens, tills, e-mails and the online shop. Public on
 * purpose (they print on receipts); the ?v= fingerprint makes them cacheable for a year. SVGs are served with a content
 * policy that forbids scripts and outside loads, even if one slipped past the cleaning on upload.
 */
class BrandAssetController extends Controller
{
    public function __invoke(Request $request, string $slug, string $kind): Response
    {
        $tenant = Tenant::where('slug', $slug)->firstOrFail();
        if ($kind === 'monogram.svg') {
            return $this->send(Branding::monogram($tenant->name, $tenant->brand_color ?: (string) config('brand.color')), 'image/svg+xml', $request);
        }
        abort_unless(isset(BrandAsset::KINDS[$kind]), 404);
        $asset = BrandAsset::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('kind', $kind)->where('bytes', '>', 0)->first();
        abort_unless($asset, 404);

        return $this->send(base64_decode($asset->data), $asset->mime, $request);
    }

    private function send(string $body, string $mime, Request $request): Response
    {
        return response($body, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => $request->query('v') ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
