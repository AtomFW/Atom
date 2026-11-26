<?php

declare(strict_types=1);

namespace Atom\Head;

use Atom\Filter\InputFilter;
use Atom\Head\Enum\ColorScheme;
use Atom\Head\Enum\OpenGraphTag;
use Stringable;

/**
 * Fluent HTML `<head>` section builder with XSS-safe attribute escaping.
 */
final class Head implements Stringable
{
    public const string VERSION = '1.1';

    private string $title = '';

    /** @var list<string> */
    private array $css = [];

    /** @var list<string> */
    private array $js = [];

    /** @var list<string> */
    private array $meta = [];

    /** @var list<string> */
    private array $link = [];

    /** @var list<string> */
    private array $property = [];

    /** @var list<string> */
    private array $generator = [];

    private string $keywords = '';
    private string $author = '';
    private string $description = '';
    private string $viewport = '';
    private string $charset = '';
    private string $authoringTool = '';
    private string $robots = '';
    private string $themeColor = '';
    private string $revisitAfter = '';
    private string $rating = '';
    private string $distribution = '';
    private string $creationdate = '';
    private string $copyright = '';
    private string $colorScheme = '';

    /** @var list<string> */
    private array $apple = [];

    /** @var list<string> */
    private array $ms = [];

    protected function addNode(
        string $nodeName,
        ?string $tagName,
        ?string $content,
        string $nameAttributePrefix = 'name',
        string $nameAttributeSubPrefix = 'content',
        ?string $type = null,
        ?string $typeContent = null,
        bool $endTagNode = false,
        bool $rawInnerContent = false,
    ): string {
        $safeNode = \preg_replace('/[^a-zA-Z0-9\-]/', '', $nodeName) ?: 'meta';
        $safePrefix = \preg_replace('/[^a-zA-Z0-9\-_:]/', '', $nameAttributePrefix) ?: 'name';
        $safeSubPrefix = \preg_replace('/[^a-zA-Z0-9\-_:]/', '', $nameAttributeSubPrefix) ?: 'content';

        if ($endTagNode) {
            $add = $tagName !== null
                ? "{$safePrefix}=\"" . InputFilter::escapeHtml($tagName) . '"'
                : '';
            $body = $content === null
                ? ''
                : ($rawInnerContent ? $content : InputFilter::escapeHtml($content));

            return "<{$safeNode} {$add}>{$body}</{$safeNode}>";
        }

        $add = '';
        if ($type !== null && $typeContent !== null) {
            $safeTypeAttr = \preg_replace('/[^a-zA-Z0-9\-_:]/', '', $type) ?: 'type';
            $add = "{$safeTypeAttr}=\"" . InputFilter::escapeHtml($typeContent) . '"';
        }
        if ($content !== null) {
            $add .= " {$safeSubPrefix}=\"" . InputFilter::escapeHtml($content) . '"';
        }

        $safeTagVal = $tagName !== null ? InputFilter::escapeHtml($tagName) : '';
        return "<{$safeNode} {$safePrefix}=\"{$safeTagVal}\" {$add}>";
    }

    protected function metaReturn(string $name, string $content): string
    {
        return $this->addNode('meta', $name, $content);
    }

    public function meta(string $name, string $content): self
    {
        $this->meta[] = $this->metaReturn($name, $content);
        return $this;
    }

    public function property(string $name, string $content): self
    {
        $this->property[] = $this->addNode('meta', $name, $content, 'property');
        return $this;
    }

    public function link(string $name, string $content, ?string $contentType): self
    {
        $node = $this->addNode('link', $name, $content, 'rel', 'href', 'type', $contentType);
        if ($name === 'stylesheet') {
            $this->css[] = $node;
        }
        $this->link[] = $node;

        return $this;
    }

    public function alternate(string $content): self
    {
        return $this->link('alternate', $content, null);
    }

    public function script(string $content, bool $isLdJson = false): self
    {
        $type = $isLdJson ? 'application/ld+json' : 'text/javascript';
        $this->js[] = $this->addNode('script', $type, $content, 'type', 'src');
        return $this;
    }

    public function scriptText(string $content, bool $isLdJson = false): self
    {
        $type = $isLdJson ? 'application/ld+json' : 'text/javascript';
        $safeInline = \str_ireplace('</script>', '<\/script>', $content);
        $this->js[] = $this->addNode('script', $type, $safeInline, 'type', 'src', endTagNode: true, rawInnerContent: true);
        return $this;
    }

    public function stylesheet(string $content): self
    {
        return $this->link('stylesheet', $content, 'text/css');
    }

    public function stylesheetText(string $content): self
    {
        $safeCss = \str_ireplace('</style>', '', $content);
        $this->css[] = $this->addNode('style', 'text/css', $safeCss, 'type', 'src', endTagNode: true, rawInnerContent: true);
        return $this;
    }

    public function base(string $content): self
    {
        $this->meta[] = $this->addNode('base', $content, null, 'href');
        return $this;
    }

    public function title(string $title): self
    {
        $this->title = $this->addNode('title', null, $title, endTagNode: true);
        return $this;
    }

    public function generator(string $content): self
    {
        $this->generator[] = $this->metaReturn('generator', $content);
        return $this;
    }

    public function keywords(string $content): self
    {
        $this->keywords = $this->metaReturn('keywords', $content);
        return $this;
    }

    public function author(string $content): self
    {
        $this->author = $this->metaReturn('author', $content);
        return $this;
    }

    public function description(string $content): self
    {
        $this->description = $this->metaReturn('description', $content);
        return $this;
    }

    public function viewport(?string $content = null): self
    {
        $viewportValue = $content ?? 'width=device-width, initial-scale=1.0, viewport-fit=cover';
        $this->viewport = $this->metaReturn('viewport', $viewportValue);
        return $this;
    }

    public function authoringTool(string $content): self
    {
        $this->authoringTool = $this->metaReturn('authoring_tool', $content);
        return $this;
    }

    public function robots(string $content): self
    {
        $this->robots = $this->metaReturn('robots', $content);
        return $this;
    }

    public function themeColor(string $content, bool $isDarkMode = true): self
    {
        $this->themeColor = $this->addNode(
            'meta',
            'theme-color',
            $content,
            type: 'media',
            typeContent: $isDarkMode ? '(prefers-color-scheme: dark)' : '(prefers-color-scheme: light)',
        );

        return $this;
    }

    public function colorScheme(ColorScheme $content): self
    {
        $this->colorScheme = $this->metaReturn('color-scheme', $content->value);
        return $this;
    }

    public function revisitAfter(string $content): self
    {
        $this->revisitAfter = $this->metaReturn('revisit-after', $content);
        return $this;
    }

    public function rating(string $content): self
    {
        $this->rating = $this->metaReturn('rating', $content);
        return $this;
    }

    public function distribution(string $content): self
    {
        $this->distribution = $this->metaReturn('distribution', $content);
        return $this;
    }

    public function creationdate(string $content): self
    {
        $this->creationdate = $this->metaReturn('creationdate', $content);
        return $this;
    }

    public function copyright(string $content): self
    {
        $this->copyright = $this->metaReturn('copyright', $content);
        return $this;
    }

    public function charset(string $content = 'UTF-8'): self
    {
        $this->charset = $this->addNode('meta', $content, null, 'charset');
        return $this;
    }

    public function shortcutIcon(string $content): self
    {
        return $this->link('shortcut icon', $content, 'image/x-icon');
    }

    public function favicon(string $content): self
    {
        return $this->link('icon', $content, 'image/svg+xml');
    }

    public function icon(string $content): self
    {
        return $this->link('icon', $content, 'image/webp');
    }

    public function canonical(string $content): self
    {
        return $this->link('canonical', $content, null);
    }

    public function manifest(string $content): self
    {
        return $this->link('manifest', $content, null);
    }

    public function og(OpenGraphTag $ogName, string $content): self
    {
        return $this->property("og:{$ogName->value}", $content);
    }

    public function ogTitle(string $content): self
    {
        return $this->property('og:title', $content);
    }

    public function ogSiteName(string $content): self
    {
        return $this->property('og:site_name', $content);
    }

    public function ogDescription(string $content): self
    {
        return $this->property('og:description', $content);
    }

    public function ogUrl(string $content): self
    {
        return $this->property('og:url', $content);
    }

    public function ogImage(string $content): self
    {
        return $this->property('og:image', $content);
    }

    public function ogImageWidth(string $content): self
    {
        return $this->property('og:image:width', $content);
    }

    public function ogImageHeight(string $content): self
    {
        return $this->property('og:image:height', $content);
    }

    public function ogType(string $content): self
    {
        return $this->property('og:type', $content);
    }

    public function ogLocale(string $content): self
    {
        return $this->property('og:locale', $content);
    }

    public function ogDeterminer(string $content): self
    {
        return $this->property('og:determiner', $content);
    }

    public function appleMobileWebAppTitle(string $content): self
    {
        $this->apple[] = $this->metaReturn('apple-mobile-web-app-title', $content);
        return $this;
    }

    public function appleMobileWebAppStatusBarStyle(string $content): self
    {
        $this->apple[] = $this->metaReturn('apple-mobile-web-app-status-bar-style', $content);
        return $this;
    }

    public function appleTouchIcon(string $content): self
    {
        return $this->link('apple-touch-icon', $content, null);
    }

    public function appleTouchStartupImage(string $content): self
    {
        return $this->link('apple-touch-startup-image', $content, null);
    }

    public function appleMaskIcon(string $content, string $color): self
    {
        $this->link[] = $this->addNode('link', 'mask-icon', $content, 'rel', 'href', 'color', $color);
        return $this;
    }

    public function appleMobileWebAppCapable(bool $content = true): self
    {
        $this->apple[] = $this->metaReturn('apple-mobile-web-app-capable', $content ? 'yes' : 'no');
        return $this;
    }

    public function appleItunesApp(string $content): self
    {
        $this->apple[] = $this->metaReturn('apple-itunes-app', "app-id={$content}");
        return $this;
    }

    public function appleTelephoneDetection(bool $content = true): self
    {
        $this->apple[] = $this->metaReturn('format-detection', $content ? 'telephone=yes' : 'telephone=no');
        return $this;
    }

    public function appleEmailDetection(bool $content = true): self
    {
        $this->apple[] = $this->metaReturn('format-detection', $content ? 'email=yes' : 'email=no');
        return $this;
    }

    public function appleaAdressDetection(bool $content = true): self
    {
        $this->apple[] = $this->metaReturn('format-detection', $content ? 'address=yes' : 'address=no');
        return $this;
    }

    public function appleaDateDetection(bool $content = true): self
    {
        $this->apple[] = $this->metaReturn('format-detection', $content ? 'date=yes' : 'date=no');
        return $this;
    }

    public function appleFormatDetection(
        bool $isTelephone = true,
        bool $isEmail = true,
        bool $isAddress = true,
        bool $isDate = true,
    ): self {
        $telephone = $isTelephone ? 'yes' : 'no';
        $email = $isEmail ? 'yes' : 'no';
        $address = $isAddress ? 'yes' : 'no';
        $date = $isDate ? 'yes' : 'no';

        $this->apple[] = $this->metaReturn(
            'format-detection',
            "telephone={$telephone}, email={$email}, address={$address}, date={$date}"
        );

        return $this;
    }

    public function alAndroidPackage(string $content): self
    {
        return $this->property('al:android:package', $content);
    }

    public function alAndroidAppName(string $content): self
    {
        return $this->property('al:android:app_name', $content);
    }

    public function alAndroidUrl(string $content): self
    {
        return $this->property('al:android:url', $content);
    }

    public function alAndroidClass(string $content): self
    {
        return $this->property('al:android:class', $content);
    }

    public function msApplicationID(string $content): self
    {
        $this->ms[] = $this->metaReturn('msApplication-ID', $content);
        return $this;
    }

    public function msApplicationPackageFamilyName(string $content): self
    {
        $this->ms[] = $this->metaReturn('msApplication-PackageFamilyName', $content);
        return $this;
    }

    public function msApplicationName(string $content): self
    {
        $this->ms[] = $this->metaReturn('application-name', $content);
        return $this;
    }

    public function msApplicationTooltip(string $content): self
    {
        $this->ms[] = $this->metaReturn('msapplication-tooltip', $content);
        return $this;
    }

    public function msApplicationStarturl(string $content): self
    {
        $this->ms[] = $this->metaReturn('msapplication-starturl', $content);
        return $this;
    }

    public function build(): string
    {
        return $this->title
            . \implode("\n", $this->meta)
            . \implode("\n", $this->link)
            . \implode("\n", $this->property)
            . \implode("\n", $this->generator)
            . $this->viewport . \PHP_EOL
            . $this->keywords . \PHP_EOL
            . $this->author . \PHP_EOL
            . $this->description . \PHP_EOL
            . $this->charset . \PHP_EOL
            . $this->authoringTool . \PHP_EOL
            . $this->robots . \PHP_EOL
            . $this->themeColor . \PHP_EOL
            . $this->revisitAfter . \PHP_EOL
            . $this->rating . \PHP_EOL
            . $this->distribution . \PHP_EOL
            . $this->creationdate . \PHP_EOL
            . $this->copyright . \PHP_EOL
            . $this->colorScheme . \PHP_EOL
            . \implode("\n", $this->apple)
            . \implode("\n", $this->ms)
            . \implode("\n", $this->css)
            . \implode("\n", $this->js);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'title' => $this->title,
            'css' => $this->css,
            'js' => $this->js,
            'meta' => $this->meta,
            'link' => $this->link,
            'property' => $this->property,
            'generator' => $this->generator,
            'viewport' => $this->viewport,
            'keywords' => $this->keywords,
            'author' => $this->author,
            'description' => $this->description,
            'charset' => $this->charset,
            'authoringTool' => $this->authoringTool,
            'robots' => $this->robots,
            'themeColor' => $this->themeColor,
            'revisitAfter' => $this->revisitAfter,
            'rating' => $this->rating,
            'distribution' => $this->distribution,
            'creationdate' => $this->creationdate,
            'copyright' => $this->copyright,
            'colorScheme' => $this->colorScheme,
            'apple' => $this->apple,
            'ms' => $this->ms,
        ];
    }

    public function __invoke(): string
    {
        return \implode("\n", $this->meta);
    }

    public function __toString(): string
    {
        return \implode("\n", $this->meta);
    }
}
