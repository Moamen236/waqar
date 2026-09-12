<?php

namespace App\Services\Content;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Product descriptions are authored as rich text in the admin
 * (react-quill) and rendered on the storefront with
 * `dangerouslySetInnerHTML`, which is the one place in this application
 * where stored markup becomes live DOM.
 *
 * Left unsanitised that is stored XSS across a real privilege boundary:
 * any employee holding `products.update` — a catalog role, not an
 * administrative one — could run script in the session of every customer
 * *and* every Super Admin who opens the product. It was a confirmed High
 * from the Phase 5 security review, still open at the end of Phase 6.
 *
 * Sanitising on **write** rather than on render is deliberate. The stored
 * value has more than one consumer already (storefront page, order
 * confirmation mail) and will have more later (Section 23's mobile API),
 * and a sanitiser attached to one renderer protects only that renderer.
 * The escaping on the way in is the durable half; the CSP added in
 * `SecurityHeaders` is the second layer, not the first.
 *
 * The allowlist is Quill's own toolbar — anything the editor can produce
 * survives a round trip, so this is invisible to the person writing a
 * description and total for anyone trying to smuggle script through it.
 */
class RichTextSanitizer
{
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('span')
            ->allowElement('div')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('blockquote')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('h1')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('h4')
            ->allowElement('h5')
            ->allowElement('h6')
            ->allowElement('a', ['href', 'title', 'target', 'rel'])
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height'])
            ->allowElement('table')
            ->allowElement('thead')
            ->allowElement('tbody')
            ->allowElement('tr')
            ->allowElement('th')
            ->allowElement('td')
            // Quill writes its alignment and indent levels as classes.
            ->allowAttribute('class', ['p', 'span', 'div', 'li', 'ol', 'ul', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'])
            // `javascript:` and `data:` URLs are as good as a <script>
            // tag; only real links and images are kept.
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowMediaSchemes(['https', 'http'])
            ->forceHttpsUrls(false)
            // `dropElement`, not `blockElement`. Blocking removes the tag
            // but *keeps its text*, so a blocked <script> left its source
            // behind as visible prose in the description — harmless but
            // wrong. Dropping removes the element and everything inside.
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('iframe')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('form');

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        return $this->sanitizer->sanitize($html);
    }

    /**
     * A translatable field arrives as `['en' => '…', 'ar' => '…']` — each
     * locale is separately authored, so each is separately sanitised.
     *
     * @param  array<string, string|null>|null  $translations
     * @return array<string, string|null>|null
     */
    public function cleanTranslations(?array $translations): ?array
    {
        if ($translations === null) {
            return null;
        }

        return array_map(fn (?string $value) => $this->clean($value), $translations);
    }
}
