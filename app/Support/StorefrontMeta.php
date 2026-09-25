<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The storefront's <head> metadata — title, description, canonical,
 * robots, hreflang, Open Graph and X cards — built on the server from the
 * page Inertia is about to render.
 *
 * It has to be server-side: the storefront has no SSR, and the crawlers
 * behind link previews (WhatsApp, Facebook, X) and most search bots read
 * the initial HTML without running the React app, so anything <Head>
 * sets on the client never reaches them.
 *
 * Copy comes from the storefront's own locale files (meta.* plus the page
 * title keys the React pages already use), so a page's server title and
 * its client-side <Head> title are the same string.
 */
class StorefrontMeta
{
    /** Private, transactional or thin pages: kept out of search results. */
    private const NOINDEX = ['Cart/', 'Checkout/', 'Account/', 'Auth/', 'Wishlist/', 'OrderTracking/', 'Search/', 'Errors/'];

    private const OG_LOCALES = ['ar' => 'ar_EG', 'en' => 'en_US'];

    /** @var array<string, array<string, string>> */
    private static array $strings = [];

    /**
     * @param  array{component: string, props: array<string, mixed>}  $page
     * @return array{title: string, description: string, canonical: string, robots: string, type: string,
     *     image: string, image_alt: string, image_size: array{0: int, 1: int}|null, locale: string,
     *     alternate_locales: list<string>, alternates: array<string, string>, site_name: string,
     *     price: float|null}
     */
    public static function for(array $page): array
    {
        $locale = app()->getLocale();
        $props = $page['props'];

        $title = null;
        $description = self::t('meta.defaultDescription');
        $image = asset('storefront/images/og/waqar-share.jpg');
        $imageAlt = self::t('meta.shareAlt');
        $imageSize = [1200, 630];
        $type = 'website';
        $price = null;

        switch ($page['component']) {
            case 'Home':
                break;

            case 'Shop/Index':
                $heading = $props['heading'] ?? null;
                if (is_array($heading)) {
                    $title = (string) $heading['name'];
                    $description = self::clean((string) ($heading['description'] ?? ''))
                        ?: self::t('meta.listingDescription', ['name' => $title]);
                } else {
                    $title = self::t('nav.shop');
                    $description = self::t('meta.shopDescription');
                }
                break;

            case 'Product/Show':
                $product = $props['product'];
                $title = (string) $product['name'];
                $price = (float) $product['price'];
                $description = self::clean((string) ($product['short_description'] ?? ''))
                    ?: self::t('meta.productDescription', ['name' => $title, 'price' => self::money($price, $locale)]);
                if (! empty($product['images'][0])) {
                    $image = (string) $product['images'][0];
                    $imageAlt = $title;
                    $imageSize = null;
                }
                $type = 'product';
                break;

            case 'Pages/About':
                $title = self::t('footer.aboutUs');
                $description = self::t('meta.aboutDescription');
                break;

            case 'Pages/Contact':
                $title = self::t('contact.pageTitle');
                $description = self::t('meta.contactDescription');
                break;

            case 'Pages/Faqs':
                $title = self::t('footer.faqs');
                $description = self::t('meta.faqsDescription');
                break;

            case 'Cart/Index':
                $title = self::t('cart.title');
                break;

            case 'Checkout/Index':
                $title = self::t('checkout.title');
                break;
        }

        // Same shape as the client's Inertia title callback (app.tsx): the
        // page title, then the brand. Home carries the brand already.
        $fullTitle = $page['component'] === 'Home'
            ? self::t('meta.homeTitle')
            : ($title !== null ? $title.' | '.self::t('meta.brand') : self::t('meta.homeTitle'));

        $noindex = Str::startsWith($page['component'], self::NOINDEX);

        return [
            'title' => $fullTitle,
            'description' => $description,
            'canonical' => self::canonical(),
            'robots' => $noindex ? 'noindex, follow' : 'index, follow, max-image-preview:large',
            'type' => $type,
            'image' => $image,
            'image_alt' => $imageAlt,
            'image_size' => $imageSize,
            'locale' => self::OG_LOCALES[$locale] ?? 'ar_EG',
            'alternate_locales' => array_values(array_diff(self::OG_LOCALES, [self::OG_LOCALES[$locale] ?? null])),
            'alternates' => $noindex ? [] : self::alternates(),
            'site_name' => 'WAQAR | وقار',
            'price' => $price,
        ];
    }

    /**
     * This URL without its query string — filters and sorting would
     * otherwise each count as a separate page — except the page number,
     * which really is different content.
     */
    private static function canonical(): string
    {
        $page = (int) request()->query('page', 1);

        return url()->current().($page > 1 ? '?page='.$page : '');
    }

    /**
     * The same route in each storefront language, for hreflang. Arabic,
     * the default locale, doubles as x-default.
     *
     * @return array<string, string>
     */
    private static function alternates(): array
    {
        $route = request()->route();
        $name = Route::currentRouteName();

        if ($route === null || $name === null) {
            return [];
        }

        $links = [];
        foreach (SetLocale::SUPPORTED as $locale) {
            $links[$locale] = route($name, [...$route->parameters(), 'locale' => $locale]);
        }
        $links['x-default'] = $links['ar'];

        return $links;
    }

    /** Plain, single-line text, cut to what search results show. */
    private static function clean(string $text): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags($text))), 160);
    }

    private static function money(float $amount, string $locale): string
    {
        $number = number_format($amount, 2);

        return $locale === 'ar' ? $number.' ج.م' : 'EGP '.$number;
    }

    /**
     * A storefront string, from the same resources/js/storefront/locales
     * file the React side reads, with :placeholder replacement.
     *
     * @param  array<string, string>  $replace
     */
    private static function t(string $key, array $replace = []): string
    {
        $locale = app()->getLocale();
        self::$strings[$locale] ??= (array) json_decode(
            (string) file_get_contents(resource_path("js/storefront/locales/{$locale}.json")),
            true,
        );

        $value = (string) (self::$strings[$locale][$key] ?? $key);
        foreach ($replace as $name => $replacement) {
            $value = str_replace(':'.$name, $replacement, $value);
        }

        return $value;
    }
}
