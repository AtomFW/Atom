<?php

declare(strict_types=1);

namespace Atom\Head;

use Atom\Log\T4LOG;
use Atom\Head\Enum\OpenGraphTag;
use Atom\Head\Enum\ColorScheme;

/**
 * Head class
 *
 * This class is responsible for managing the HTML head section of a web page.
 *
 * It provides methods to set the title, add CSS and JavaScript files, add meta tags,
 * add link tags, add property tags, add generator tags and set other head section
 * attributes.
 *
 * @final
 */
final class Head
{
    public const VERSION = '1.0';

    private string $title = '';
    private array $css = [];
    private array $js = [];
    private array $meta = [];
    private array $link = [];
    private array $property = [];
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
    private array $apple = [];
    private array $ms = [];

    // public function __construct(private T4LOG $logger)
    // {
    // }

    /**
     * Add a node to the head.
     *
     * The node can either be a standalone node (i.e. `<meta>`) or a node with a closing tag (i.e. `<title>`).
     *
     * If the node has a closing tag, the content of the node should be set to null.
     *
     * @param string $nodeName The name of the node (i.e. "meta", "title", etc...).
     * @param string|null $tagName The value of the name attribute of the node.
     * @param string|null $content The content of the node.
     * @param string $nameAttributePrefix The prefix of the name attribute of the node.
     * @param string $nameAttributeSubPrefix The sub prefix of the name attribute of the node.
     * @param string|null $type The value of the type attribute of the node.
     * @param string|null $typeContent The content of the type attribute of the node.
     * @param bool $endTagNode Whether the node has a closing tag.
     * @return string The HTML string of the node.
     */
    protected function addNode(
        string $nodeName,
        ?string $tagName,
        ?string $content,
        string $nameAttributePrefix = 'name',
        string $nameAttributeSubPrefix = 'content',
        ?string $type = null,
        ?string $typeContent = null,
        bool $endTagNode = false
    ): string {
        if ($endTagNode) {
            $add = '';
            if ($tagName !== null) {
                $add = "{$nameAttributePrefix}=\"{$tagName}\"";
            }
            return "<{$nodeName} {$add}>{$content}</{$nodeName}>";
        }
        $add = '';
        if ($type !== null && $typeContent !== null) {
            $add = "{$type}=\"{$typeContent}\"";
        }
        if ($content !== null) {
            $add .= " {$nameAttributeSubPrefix}=\"{$content}\"";
        }

        return "<{$nodeName} {$nameAttributePrefix}=\"{$tagName}\" {$add}>";
    }

    /**
     * Meta return method
     *
     * This method returns a meta node HTML string.
     *
     * @param string $name The name of the meta node.
     * @param string $content The content of the meta node.
     * @return string The HTML string of the meta node.
     */
    protected function metaReturn(string $name, string $content): string
    {
        return $this->addNode("meta", $name, $content);
    }

    /**
     * Meta method
     *
     * This method sets a meta node with the given name and content.
     *
     * @param string $name The name of the meta node.
     * @param string $content The content of the meta node.
     * @return Head The current Head object.
     */
    public function meta(string $name, string $content): Head
    {
        $this->meta[] = $this->metaReturn($name, $content);

        return $this;
    }

    /**
     * Sets a property node.
     *
     * A property node is a type of meta node that is used to define
     * properties of the HTML document. It is commonly used to define
     * Open Graph properties.
     *
     * @param string $name The name of the property node.
     * @param string $content The content of the property node.
     * @return Head The current Head object.
     */
    public function property(string $name, string $content): Head
    {
        $this->property[] = $this->addNode("meta", $name, $content, "property");

        return $this;
    }

    /**
     * Adds a link node to the head.
     *
     * A link node is used to define a resource that is used by the HTML document.
     * It is commonly used to define stylesheets, scripts, and favicons.
     *
     * @param string $name The name of the link node.
     * @param string $content The content of the link node.
     * @param string|null $contentType The type of content the link node is referencing.
     * @return Head The current Head object.
     */
    public function link(string $name, string $content, ?string $contentType): Head
    {
        if ($name === "stylesheet") {
            $this->css[] = $this->addNode("link", $name, $content, "rel", "href", "type", $contentType);
        }
        $this->link[] = $this->addNode("link", $name, $content, "rel", "href", "type", $contentType);

        return $this;
    }

    /**
     * Sets an alternate link node.
     *
     * An alternate link node is a type of link node that is used to define
     * an alternative representation of the HTML document. It is commonly
     * used to define a canonical URL for the HTML document.
     *
     * @param string $content The content of the alternate link node.
     * @return Head The current Head object.
     */
    public function alternate(string $content): Head
    {
        $this->link("alternate", $content, null);

        return $this;
    }

    /**
     * Adds a script node to the head.
     *
     * A script node is used to define a block of JavaScript code that is
     * executed by the browser when the HTML document is loaded.
     *
     * @param string $content The content of the script node.
     * @param bool $isLdJson Whether the script node contains JSON-LD data.
     * @return Head The current Head object.
     */
    public function script(string $content, bool $isLdJson = false): Head
    {
        $type = "text/javascript";
        if ($isLdJson) {
            $type = "application/ld+json";
        }
        $this->js[] = $this->addNode("script", $type, $content, "type", "src");

        return $this;
    }

    /**
     * Adds a script node with text content to the head.
     *
     * A script node with text content is used to define a block of JavaScript code that is
     * executed by the browser when the HTML document is loaded.
     *
     * @param string $content The content of the script node.
     * @param bool $isLdJson Whether the script node contains JSON-LD data.
     * @return Head The current Head object.
     */
    public function scriptText(string $content, bool $isLdJson = false): Head
    {
        $type = "text/javascript";
        if ($isLdJson) {
            $type = "application/ld+json";
        }
        $this->js[] = $this->addNode("script", $type, $content, "type", "src", endTagNode: true);

        return $this;
    }

    /**
     * Adds a stylesheet node with a reference to an external CSS file to the head.
     *
     * A stylesheet node with a reference to an external CSS file is used to define
     * the styles of the HTML document.
     *
     * @param string $content The URL of the external CSS file.
     * @return Head The current Head object.
     */
    public function stylesheet(string $content): Head
    {
        $this->link("stylesheet", $content, "text/css");

        return $this;
    }

    /**
     * Adds a stylesheet node with inline CSS code to the head.
     *
     * A stylesheet node with inline CSS code is used to define the styles of the HTML document.
     *
     * @param string $content The inline CSS code.
     * @return Head The current Head object.
     */
    public function stylesheetText(string $content): Head
    {
        $this->css[] = $this->addNode("link", "stylesheet", $content, "type", "src", endTagNode: true);

        return $this;
    }

    /**
     * Adds a base node with a reference to an external URL to the head.
     *
     * A base node with a reference to an external URL is used to define the base URL
     * of the HTML document.
     *
     * @param string $content The URL of the external URL.
     * @return Head The current Head object.
     */
    public function base(string $content): Head
    {
        $this->meta[] = $this->addNode("base", $content, null, "href");

        return $this;
    }
    
    /**
     * Adds a title node with the title of the HTML document to the head.
     *
     * A title node with the title of the HTML document is used to define the title of the HTML document.
     *
     * @param string $title The title of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function title(string $title): Head
    {
        $this->title = $this->addNode("title", null, $title, endTagNode: true);

        return $this;
    }

    /*
        ready tags
    */

    /**
     * Adds a generator node to the head.
     *
     * A generator node is used to define the name of the software that generated the HTML document.
     *
     * @param string $content The name of the software that generated the HTML document.
     *
     * @return Head The current Head object.
     */
    public function generator(string $content): Head
    {
        $this->generator[] = $this->metaReturn("generator", $content);

        return $this;
    }

    /**
     * Adds a keywords node to the head.
     *
     * A keywords node is used to define the keywords of the HTML document.
     *
     * @param string $content The keywords of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function keywords(string $content): Head
    {
        $this->keywords = $this->metaReturn("keywords", $content);

        return $this;
    }

    /**
     * Adds an author node to the head.
     *
     * An author node is used to define the author of the HTML document.
     *
     * @param string $content The author of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function author(string $content): Head
    {
        $this->author = $this->metaReturn("author", $content);

        return $this;
    }

    /**
     * Adds a description node to the head.
     *
     * A description node is used to define a short description of the HTML document.
     *
     * @param string $content The description of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function description(string $content): Head
    {
        $this->description = $this->metaReturn("description", $content);

        return $this;
    }

    /**
     * Adds a viewport node to the head.
     *
     * A viewport node is used to define the zooming behavior of the HTML document.
     *
     * @param string|null $content The content of the viewport node.
     *     If null, it will be set to the default value.
     *
     * @return Head The current Head object.
     */
    public function viewport(?string $content = null): Head
    {
        if ($content === null) {
            $content =
            "width=1920, initial-scale=1.0, width=device-width, user-scalable=no, minimum-scale=1.0, " .
            "maximum-scale=1.0, initial-scale=1.0, viewport-fit=cover";
        }
        $this->viewport = $this->metaReturn("viewport", $content);

        return $this;
    }

    /**
     * Adds an authoring tool node to the head.
     *
     * An authoring tool node is used to define the tool that was used to create the HTML document.
     *
     * @param string $content The authoring tool used to create the HTML document.
     *
     * @return Head The current Head object.
     */
    public function authoringTool(string $content): Head
    {
        $this->authoringTool = $this->metaReturn("authoring_tool", $content);

        return $this;
    }

    /**
     * Adds a robots node to the head.
     *
     * A robots node is used to define the instructions that are given to web crawlers and other web robots.
     *
     * @param string $content The instructions that are given to web crawlers and other web robots.
     *
     * @return Head The current Head object.
     */
    public function robots(string $content): Head
    {
        $this->robots = $this->metaReturn("robots", $content);

        return $this;
    }

    /**
     * Adds a theme color node to the head.
     *
     * A theme color node is used to define the preferred color scheme of the HTML document.
     *
     * @param string $content The preferred color scheme of the HTML document.
     * @param bool $isDarkMode Whether to use the dark color scheme or not.
     *     If true, the dark color scheme will be used.
     *     If false, the light color scheme will be used.
     *
     * @return Head The current Head object.
     */
    public function themeColor(string $content, bool $isDarkMode = true): Head
    {
        if ($isDarkMode) {
            $this->themeColor = $this->addNode(
                "meta",
                "theme-color",
                $content,
                type: "media",
                typeContent: "(prefers-color-scheme: dark)"
            );
        } else {
            $this->themeColor = $this->addNode(
                "meta",
                "theme-color",
                $content,
                type: "media",
                typeContent: "(prefers-color-scheme: light)"
            );
        }

        return $this;
    }

    /**
     * Adds a color scheme node to the head.
     *
     * A color scheme node is used to define the preferred color scheme of the HTML document.
     *
     * @param ColorScheme $content The preferred color scheme of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function colorScheme(ColorScheme $content): Head
    {
        $this->colorScheme = $this->metaReturn("color-scheme", $content->value);

        return $this;
    }

    /**
     * Adds a revisit-after node to the head.
     *
     * A revisit-after node is used to define the time after which a search engine should revisit the HTML document.
     *
     * @param string $content The time after which a search engine should revisit the HTML document.
     *
     * @return Head The current Head object.
     */
    public function revisitAfter(string $content): Head
    {
        $this->revisitAfter = $this->metaReturn("revisit-after", $content);

        return $this;
    }

    /**
     * Adds a rating node to the head.
     *
     * A rating node is used to define the content rating of the HTML document.
     *
     * @param string $content The content rating of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function rating(string $content): Head
    {
        $this->rating = $this->metaReturn("rating", $content);

        return $this;
    }

    /**
     * Adds a distribution node to the head.
     *
     * A distribution node is used to define the distribution of the HTML document.
     *
     * @param string $content The distribution of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function distribution(string $content): Head
    {
        $this->distribution = $this->metaReturn("distribution", $content);

        return $this;
    }

    /**
     * Adds a creation date node to the head.
     *
     * A creation date node is used to define the date of creation of the HTML document.
     *
     * @param string $content The date of creation of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function creationdate(string $content): Head
    {
        $this->creationdate = $this->metaReturn("creationdate", $content);

        return $this;
    }

    /**
     * Adds a copyright node to the head.
     *
     * A copyright node is used to define the copyright of the HTML document.
     *
     * @param string $content The copyright of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function copyright(string $content): Head
    {
        $this->copyright = $this->metaReturn("copyright", $content);

        return $this;
    }

    /**
     * Adds a charset node to the head.
     *
     * A charset node is used to define the character encoding of the HTML document.
     *
     * @param string $content The character encoding of the HTML document. Default to "UTF-8".
     *
     * @return Head The current Head object.
     */
    public function charset(string $content = "UTF-8"): Head
    {
        $this->charset = $this->addNode("meta", $content, null, "charset");
        return $this;
    }

    /**
     * Adds a shortcut icon node to the head.
     *
     * A shortcut icon node is used to define a shortcut icon for the HTML document.
     *
     * @param string $content The shortcut icon of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function shortcutIcon(string $content): Head
    {
        $this->link("shortcut icon", $content, "image/x-icon");

        return $this;
    }

    /**
     * Adds a favicon node to the head.
     *
     * A favicon node is used to define a favicon for the HTML document.
     *
     * @param string $content The favicon of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function favicon(string $content): Head
    {
        $this->link("icon", $content, "image/svg+xml");

        return $this;
    }

    /**
     * Adds an icon node to the head.
     *
     * An icon node is used to define an icon for the HTML document.
     *
     * @param string $content The icon of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function icon(string $content): Head
    {
        $this->link("icon", $content, "image/webp");

        return $this;
    }

    /**
     * Adds a canonical node to the head.
     *
     * A canonical node is used to define a canonical URL for the HTML document.
     *
     * @param string $content The canonical URL of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function canonical(string $content): Head
    {
        $this->link("canonical", $content, null);

        return $this;
    }
    
    /**
     * Adds a manifest node to the head.
     *
     * A manifest node is used to define a web app manifest for the HTML document.
     * The web app manifest is a JSON file that contains information about the web application,
     * such as its name, description, icons, and start URL.
     *
     * @param string $content The content of the manifest node.
     *
     * @return Head The current Head object.
     */
    public function manifest(string $content): Head
    {
        $this->link("manifest", $content, null);

        return $this;
    }

    /*
        og tags
    */
    /**
     * Adds an OpenGraph meta tag to the head.
     *
     * OpenGraph meta tags are used to provide structured data about the HTML document.
     * They are used by Facebook and other services to provide additional information about the
     * document, such as its title, description, and images.
     *
     * @param OpenGraphTag $ogName The name of the OpenGraph meta tag.
     * @param string $content The content of the OpenGraph meta tag.
     *
     * @return Head The current Head object.
     */
    public function og(OpenGraphTag $ogName, string $content): Head
    {
        $this->property("og:{$ogName->value}", $content);

        return $this;
    }

    /**
     * Adds an OpenGraph meta tag to the head.
     *
     * OpenGraph meta tags are used to provide structured data about the HTML document.
     * They are used by Facebook and other services to provide additional information about the
     * document, such as its title, description, and images.
     *
     * @param string $content The title of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function ogTitle(string $content): Head
    {
        $this->property("og:title", $content);

        return $this;
    }

    /**
     * Adds an OpenGraph site name meta tag to the head.
     *
     * The OpenGraph site name meta tag is used to define the name of the website that the
     * HTML document is associated with.
     *
     * @param string $content The name of the website.
     *
     * @return Head The current Head object.
     */
    public function ogSiteName(string $content): Head
    {
        $this->property("og:site_name", $content);

        return $this;
    }

    /**
     * Adds an OpenGraph meta tag to the head.
     *
     * OpenGraph meta tags are used to provide structured data about the HTML document.
     * They are used by Facebook and other services to provide additional information about the
     * document, such as its title, description, and images.
     *
     * @param string $content The description of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function ogDescription(string $content): Head
    {
        $this->property("og:description", $content);

        return $this;
    }

    /**
     * Adds an OpenGraph URL meta tag to the head.
     *
     * The OpenGraph URL meta tag is used to define the canonical URL of the HTML document.
     * It is used by Facebook and other services to determine the URL of the document.
     *
     * @param string $content The canonical URL of the HTML document.
     *
     * @return Head The current Head object.
     */
    public function ogUrl(string $content): Head
    {
        $this->property("og:url", $content);

        return $this;
    }

    /**
     * Adds an OpenGraph image meta tag to the head.
     *
     * The OpenGraph image meta tag is used to define the image that is associated with the HTML document.
     * It is used by Facebook and other services to provide additional information about the
     * document, such as its title, description, and images.
     *
     * @param string $content The image URL.
     *
     * @return Head The current Head object.
     */
    public function ogImage(string $content): Head
    {
        $this->property("og:image", $content);

        return $this;
    }

    /**
     * Adds an OpenGraph image width meta tag to the head.
     *
     * The OpenGraph image width meta tag is used to define the width of the image that is associated with the HTML document.
     * It is used by Facebook and other services to provide additional information about the
     * document, such as its title, description, and images.
     *
     * @param string $content The width of the image in pixels.
     *
     * @return Head The current Head object.
     */
    public function ogImageWidth(string $content): Head
    {
        $this->property("og:image:width", $content);

        return $this;
    }

    /**
     * Adds an OpenGraph image height meta tag to the head.
     *
     * The OpenGraph image height meta tag is used to define the height of the image that is associated with the HTML document.
     * It is used by Facebook and other services to provide additional information about the
     * document, such as its title, description, and images.
     *
     * @param string $content The height of the image in pixels.
     *
     * @return Head The current Head object.
     */
    public function ogImageHeight(string $content): Head
    {
        $this->property("og:image:height", $content);

        return $this;
    }

    /**
     * Adds an OpenGraph type meta tag to the head.
     *
     * The OpenGraph type meta tag is used to define the type of the HTML document.
     * It is used by Facebook and other services to provide additional information about the
     * document, such as its title, description, and images.
     *
     * @param string $content The type of the document (e.g. "article", "blog", etc...).
     *
     * @return Head The current Head object.
     */
    public function ogType(string $content): Head
    {
        $this->property("og:type", $content);

        return $this;
    }

    /**
     * Adds an OpenGraph locale meta tag to the head.
     *
     * The OpenGraph locale meta tag is used to define the locale of the HTML document.
     * It is used by Facebook and other services to provide additional information about the
     * document, such as its title, description, and images.
     *
     * @param string $content The locale of the document (e.g. "en_US", "pl_PL", etc...).
     *
     * @return Head The current Head object.
     */
    public function ogLocale(string $content): Head
    {
        $this->property("og:locale", $content);

        return $this;
    }

    /**
     * Adds an OpenGraph Determiner meta tag to the head.
     *
     * The OpenGraph Determiner meta tag is used to define whether the HTML document is
     * an instant article or not.
     *
     * @param string $content The content of the og:determiner meta tag.
     *
     * @return Head The current Head object.
     */
    public function ogDeterminer(string $content): Head
    {
        $this->property("og:determiner", $content);

        return $this;
    }

    /*
        apple tag
    */
    /**
     * Adds an Apple mobile web app title meta tag to the head.
     *
     * The Apple mobile web app title meta tag is used to define the title of the HTML document
     * when it is displayed on an Apple device.
     *
     * @param string $content The title of the document when it is displayed on an Apple device.
     *
     * @return Head The current Head object.
     */
    public function appleMobileWebAppTitle(string $content): Head
    {
        $this->apple[] = $this->metaReturn("apple-mobile-web-app-title", $content);

        return $this;
    }

    /**
     * Adds an Apple mobile web app status bar style meta tag to the head.
     *
     * The Apple mobile web app status bar style meta tag is used to define the style of the
     * status bar when the HTML document is displayed on an Apple device.
     *
     * @param string $content The style of the status bar (e.g. "default", "black", "black-translucent").
     *
     * @return Head The current Head object.
     */
    public function appleMobileWebAppStatusBarStyle(string $content): Head
    {
        $this->apple[] = $this->metaReturn("apple-mobile-web-app-status-bar-style", $content);

        return $this;
    }

    /**
     * Adds an Apple touch icon meta tag to the head.
     *
     * The Apple touch icon meta tag is used to define the icon that is displayed on an Apple
     * device when the HTML document is saved to the home screen.
     *
     * @param string $content The URL of the icon.
     *
     * @return Head The current Head object.
     */
    public function appleTouchIcon(string $content): Head
    {
        $this->link("apple-touch-icon", $content, null);

        return $this;
    }

    /**
     * Adds an Apple touch startup image meta tag to the head.
     *
     * The Apple touch startup image meta tag is used to define the image that is displayed on an Apple
     * device when the HTML document is saved to the home screen.
     *
     * @param string $content The URL of the image.
     *
     * @return Head The current Head object.
     */
    public function appleTouchStartupImage(string $content): Head
    {
        $this->link("apple-touch-startup-image", $content, null);

        return $this;
    }

    /**
     * Adds an Apple mask icon meta tag to the head.
     *
     * The Apple mask icon meta tag is used to define the icon that is displayed on an Apple
     * device when the HTML document is saved to the home screen.
     *
     * @param string $content The URL of the icon.
     * @param string $color The color of the icon.
     *
     * @return Head The current Head object.
     */
    public function appleMaskIcon(string $content, string $color): Head
    {
        $this->link[] = $this->addNode("link", "mask-icon", $content, "rel", "href", "color", $color);

        return $this;
    }

    /**
     * Adds an Apple mobile web app capable meta tag to the head.
     *
     * The Apple mobile web app capable meta tag is used to define whether the HTML document is
     * an Apple mobile web app or not.
     *
     * @param bool $content If true, the HTML document is an Apple mobile web app. If false,
     * it is not.
     *
     * @return Head The current Head object.
     */
    public function appleMobileWebAppCapable(bool $content = true): Head
    {
        if ($content) {
            $this->apple[] = $this->metaReturn("apple-mobile-web-app-capable", "yes");

            return $this;
        }
        $this->apple[] = $this->metaReturn("apple-mobile-web-app-capable", "no");

        return $this;
    }

    /**
     * Adds an Apple iTunes app meta tag to the head.
     *
     * The Apple iTunes app meta tag is used to define the ID of the iTunes app that is
     * associated with the HTML document.
     *
     * @param string $content The ID of the iTunes app.
     *
     * @return Head The current Head object.
     */
    public function appleItunesApp(string $content): Head
    {
        $this->apple[] = $this->metaReturn("apple-itunes-app", "app-id={$content}");

        return $this;
    }

    /**
     * Adds an Apple telephone format detection meta tag to the head.
     *
     * The Apple telephone format detection meta tag is used to define whether the HTML document
     * is able to detect phone numbers in the document and convert them to clickable links or not.
     *
     * @param bool $content If true, the HTML document is able to detect phone numbers. If false,
     * it is not.
     *
     * @return Head The current Head object.
     */
    public function appleTelephoneDetection(bool $content = true): Head
    {
        if ($content) {
            $this->apple[] = $this->metaReturn("format-detection", "telephone=yes");

            return $this;
        }
        $this->apple[] = $this->metaReturn("format-detection", "telephone=no");

        return $this;
    }

    /**
     * Adds an Apple email format detection meta tag to the head.
     *
     * The Apple email format detection meta tag is used to define whether the HTML document
     * is able to detect email addresses in the document and convert them to clickable links or not.
     *
     * @param bool $content If true, the HTML document is able to detect email addresses. If false,
     * it is not.
     *
     * @return Head The current Head object.
     */
    public function appleEmailDetection(bool $content = true): Head
    {
        if ($content) {
            $this->apple[] = $this->metaReturn("format-detection", "email=yes");

            return $this;
        }
        $this->apple[] = $this->metaReturn("format-detection", "email=no");

        return $this;
    }

    /**
     * Adds an Apple address format detection meta tag to the head.
     *
     * The Apple address format detection meta tag is used to define whether the HTML document
     * is able to detect addresses in the document and convert them to clickable links or not.
     *
     * @param bool $content If true, the HTML document is able to detect addresses. If false,
     * it is not.
     *
     * @return Head The current Head object.
     */
    public function appleaAdressDetection(bool $content = true): Head
    {
        if ($content) {
            $this->apple[] = $this->metaReturn("format-detection", "address=yes");

            return $this;
        }
        $this->apple[] = $this->metaReturn("format-detection", "address=no");

        return $this;
    }

    /**
     * Adds an Apple date format detection meta tag to the head.
     *
     * The Apple date format detection meta tag is used to define whether the HTML document
     * is able to detect dates in the document and convert them to clickable links or not.
     *
     * @param bool $content If true, the HTML document is able to detect dates. If false,
     * it is not.
     *
     * @return Head The current Head object.
     */
    public function appleaDateDetection(bool $content = true): Head
    {
        if ($content) {
            $this->apple[] = $this->metaReturn("format-detection", "date=yes");

            return $this;
        }
        $this->apple[] = $this->metaReturn("format-detection", "date=no");

        return $this;
    }

    /**
     * Adds an Apple format detection meta tag to the head.
     *
     * The Apple format detection meta tag is used to define whether the HTML document
     * is able to detect phone numbers, email addresses, addresses and dates in the document
     * and convert them to clickable links or not.
     *
     * @param bool $isTelephone If true, the HTML document is able to detect phone numbers. If false,
     * it is not.
     * @param bool $isEmail If true, the HTML document is able to detect email addresses. If false,
     * it is not.
     * @param bool $isAddress If true, the HTML document is able to detect addresses. If false,
     * it is not.
     * @param bool $isDate If true, the HTML document is able to detect dates. If false,
     * it is not.
     *
     * @return Head The current Head object.
     */
    public function appleFormatDetection(
        bool $isTelephone = true,
        bool $isEmail = true,
        bool $isAddress = true,
        bool $isDate = true
    ): Head {
        $telephone = "no";
        $email = "no";
        $address = "no";
        $date = "no";

        if ($isTelephone) {
            $telephone = "yes";
        }

        if ($isEmail) {
            $email = "yes";
        }

        if ($isAddress) {
            $address = "yes";
        }

        if ($isDate) {
            $date = "yes";
        }

        $this->apple[] = $this->metaReturn(
            "format-detection",
            "telephone={$telephone}, email={$email}, address={$address}, date={$date}"
        );

        return $this;
    }

    /*
        al tag
    */
    /**
     * Sets the Android package meta tag.
     *
     * The Android package meta tag is used by Google Play to determine which app to open
     * when a user clicks on an Android App Link.
     *
     * @param string $content The value of the Android package meta tag.
     *
     * @return Head The current Head object.
     */
    public function alAndroidPackage(string $content): Head
    {
        $this->property("al:android:package", $content);

        return $this;
    }

    /**
     * Sets the Android app name meta tag.
     *
     * The Android app name meta tag is used by Google Play to determine which app to open
     * when a user clicks on an Android App Link.
     *
     * @param string $content The value of the Android app name meta tag.
     *
     * @return Head The current Head object.
     */
    public function alAndroidAppName(string $content): Head
    {
        $this->property("al:android:app_name", $content);

        return $this;
    }

    /**
     * Sets the Android URL meta tag.
     *
     * The Android URL meta tag is used to define the URL that the app is associated with.
     *
     * @param string $content The value of the Android URL meta tag.
     *
     * @return Head The current Head object.
     */
    public function alAndroidUrl(string $content): Head
    {
        $this->property("al:android:url", $content);

        return $this;
    }

    /**
     * Sets the Android class meta tag.
     *
     * The Android class meta tag is used to specify the class of the app that is associated with the URL.
     *
     * @param string $content The value of the Android class meta tag.
     *
     * @return Head The current Head object.
     */
    public function alAndroidClass(string $content): Head
    {
        $this->property("al:android:class", $content);

        return $this;
    }

    /*
        ms Tag
    */
    /**
     * Sets the Microsoft application ID meta tag.
     *
     * The Microsoft application ID meta tag is used to specify the ID of the app that is associated with the URL.
     *
     * @param string $content The value of the Microsoft application ID meta tag.
     *
     * @return Head The current Head object.
     */
    public function msApplicationID(string $content): Head
    {
        $this->ms[] = $this->metaReturn("msApplication-ID", $content);

        return $this;
    }

    /**
     * Sets the Microsoft application package family name meta tag.
     *
     * The Microsoft application package family name meta tag is used to specify the package family name of the app that is associated with the URL.
     *
     * @param string $content The value of the Microsoft application package family name meta tag.
     *
     * @return Head The current Head object.
     */
    public function msApplicationPackageFamilyName(string $content): Head
    {
        $this->ms[] = $this->metaReturn("msApplication-PackageFamilyName", $content);

        return $this;
    }

    /**
     * Sets the Microsoft application name meta tag.
     *
     * The Microsoft application name meta tag is used to specify the name of the app that is associated with the URL.
     *
     * @param string $content The value of the Microsoft application name meta tag.
     *
     * @return Head The current Head object.
     */
    public function msApplicationName(string $content): Head
    {
        $this->ms[] = $this->metaReturn("application-name", $content);

        return $this;
    }

    /**
     * Sets the Microsoft application tooltip meta tag.
     *
     * The Microsoft application tooltip meta tag is used to specify the tooltip text that should be displayed when the user hovers over the app tile in the Windows start menu.
     *
     * @param string $content The value of the Microsoft application tooltip meta tag.
     *
     * @return Head The current Head object.
     */
    public function msApplicationTooltip(string $content): Head
    {
        $this->ms[] = $this->metaReturn("msapplication-tooltip", $content);

        return $this;
    }

    /**
     * Sets the Microsoft application start URL meta tag.
     *
     * The Microsoft application start URL meta tag is used to specify the URL that should be opened when the user clicks on the app tile in the Windows start menu.
     *
     * @param string $content The value of the Microsoft application start URL meta tag.
     *
     * @return Head The current Head object.
     */
    public function msApplicationStarturl(string $content): Head
    {
        $this->ms[] = $this->metaReturn("msapplication-starturl", $content);

        return $this;
    }

    /**
     * Builds the HTML head section based on the current Head object.
     *
     * This method will return a string containing the HTML head section based on the current Head object.
     *
     * @return string The HTML head section based on the current Head object.
     */
    public function build(): string
    {
        $head = "";

        $head .= $this->title;
        $head .= implode("\n", $this->meta);
        $head .= implode("\n", $this->link);
        $head .= implode("\n", $this->property);
        $head .= implode("\n", $this->generator);
        $head .= $this->viewport . PHP_EOL;
        $head .= $this->keywords . PHP_EOL;
        $head .= $this->author . PHP_EOL;
        $head .= $this->description . PHP_EOL;
        $head .= $this->charset . PHP_EOL;
        $head .= $this->authoringTool . PHP_EOL;
        $head .= $this->robots . PHP_EOL;
        $head .= $this->themeColor . PHP_EOL;
        $head .= $this->revisitAfter . PHP_EOL;
        $head .= $this->rating . PHP_EOL;
        $head .= $this->distribution . PHP_EOL;
        $head .= $this->creationdate . PHP_EOL;
        $head .= $this->copyright . PHP_EOL;
        $head .= $this->colorScheme . PHP_EOL;
        $head .= implode("\n", $this->apple);
        $head .= implode("\n", $this->ms);
        $head .= implode("\n", $this->css);
        $head .= implode("\n", $this->js);

        return $head;
    }

    /**
     * Return an array containing the debug information of the current Head object.
     *
     * This method is used by the var_dump() function to display the debug information of the current Head object.
     *
     * @return array The debug information of the current Head object.
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
            'ms' => $this->ms
        ];
    }

    /**
     * This magic method is used to convert the Head object to a string.
     *
     * When the Head object is converted to a string, this method will be called.
     * It will return the HTML head tag with all the meta tags and other information.
     *
     * @return string The HTML head tag with all the meta tags and other information.
     */
    public function __invoke(): string
    {
        return implode("\n", $this->meta);
    }

    /**
     * This magic method is used to convert the Head object to a string.
     *
     * When the Head object is converted to a string, this method will be called.
     * It will return the HTML head tag with all the meta tags and other information.
     *
     * @return string The HTML head tag with all the meta tags and other information.
     */
    public function __toString(): string
    {
        return implode("\n", $this->meta);
    }
}
