<?php

/**
 * MIT License
 *
 * Copyright (c) 2024 René Coignard
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

class Weblog {
    private static $config = [];
    private const VERSION = '1.2.0';
    private const CONFIG_PATH = __DIR__ . '/config.yml';
    private const DEFAULT_LINE_WIDTH = 72;
    private const DEFAULT_PREFIX_LENGTH = 3;
    private const DEFAULT_WEBLOG_DIR = __DIR__ . '/weblog/';

    /**
     * Main function to run the «Weblog».
     */
    public static function run() {
        self::loadConfig();
        header('Content-Type: text/plain; charset=utf-8');

        $requestedPost = self::getRequestedPost();

        if ($requestedPost) {
            echo "\n\n";
            self::renderPost($requestedPost);
            echo "\n\n";
            self::renderFooter(date("Y", $requestedPost->getMTime()));
        } else {
            if (isset($_GET['go'])) {
                if ($_GET['go'] === 'sitemap.xml') {
                    header('Content-Type: application/xml; charset=utf-8');
                    echo self::renderSitemap();
                    exit;
                } else if ($_GET['go'] === 'rss') {
                    header('Content-Type: application/xml; charset=utf-8');
                    echo self::generateRSS();
                    exit;
                } else if (rand(1, 10) != 1) {
                    echo "404 Not Found\n";
                } else {
                    echo "404 Cat Found\n\n  ／l、meow\n（ﾟ､ ｡ ７\n  l  ~ヽ\n  じしf_,)ノ\n";
                }
                http_response_code(404);
            } else {
                echo "\n\n";
                echo self::centerText(self::$config['author_name']) . "\n";
                echo "\nAbout\n\n";
                echo self::formatParagraph(self::$config['about_text']);
                echo "\n\n\n";
                self::renderAllPosts();
                self::renderFooter();
            }
        }
    }

    /**
     * Loads configuration from a YAML file. Parses the file line-by-line and populates the config array.
     */
    private static function loadConfig() {
        $configContent = file_get_contents(self::CONFIG_PATH);
        $lines = explode("\n", $configContent);
        foreach ($lines as $line) {
            if (trim($line) && $line[0] !== '#' && strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                self::$config[trim($key)] = trim($value);
            }
        }
        self::$config['line_width'] = self::$config['line_width'] ?? self::DEFAULT_LINE_WIDTH;
        self::$config['prefix_length'] = self::$config['prefix_length'] ?? self::DEFAULT_PREFIX_LENGTH;
        self::$config['weblog_dir'] = self::$config['weblog_dir'] ?? self::DEFAULT_WEBLOG_DIR;
        self::$config['domain'] = rtrim(self::$config['domain'] ?? 'https://renecoignard.com', '/');
    }

    /**
     * Centers text within the configured line width.
     * @param string $text The text to be centered.
     * @return string The centered text.
     */
    private static function centerText($text) {
        $lineWidth = self::$config['line_width'];
        $leftPadding = ($lineWidth - mb_strlen($text)) / 2;
        return str_repeat(' ', floor($leftPadding)) . $text;
    }

    /**
     * Formats a paragraph to fit within the configured line width, using a specified prefix length.
     * @param string $text The text of the paragraph.
     * @return string The formatted paragraph.
     */
    private static function formatParagraph($text) {
        $lineWidth = self::$config['line_width'];
        $prefixLength = self::$config['prefix_length'];
        $linePrefix = str_repeat(' ', $prefixLength);
        $words = explode(' ', $text);
        $line = $linePrefix;
        $result = '';

        foreach ($words as $word) {
            if (mb_strlen($line . $word) > $lineWidth) {
                $result .= rtrim($line) . "\n";
                $line = $linePrefix . $word . ' ';
            } else {
                $line .= $word . ' ';
            }
        }

        return $result . rtrim($line);
    }

    /**
     * Renders all blog posts sorted by modification date in descending order.
     */
    private static function renderAllPosts() {
        $weblogDir = self::$config['weblog_dir'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($weblogDir, RecursiveDirectoryIterator::SKIP_DOTS));
        $files = iterator_to_array($iterator);

        usort($files, function($a, $b) {
            return $b->getMTime() - $a->getMTime();
        });

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'txt') {
                self::renderPost($file);
                echo "\n\n";
            }
        }
    }

    /**
     * Retrieves the requested post based on the GET parameter, converting the title to a slug and handling .txt extension.
     * @return SplFileInfo|null The file info of the requested post or null if not found.
     */
    private static function getRequestedPost() {
        $postSlug = $_GET['go'] ?? '';
        $postSlug = preg_replace('/\.txt$/', '', $postSlug);
        $weblogDir = self::$config['weblog_dir'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($weblogDir, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'txt') {
                $slug = self::slugify(basename($file->getFilename(), '.txt'));
                if ($slug === $postSlug) {
                    return $file;
                }
            }
        }
        return null;
    }

    /**
     * Retrieves the range of years (earliest and latest) from all blog posts.
     * @return array Associative array with keys 'min' and 'max' indicating the minimum and maximum years.
     */
    private static function getPostYearsRange() {
        $weblogDir = self::$config['weblog_dir'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($weblogDir, RecursiveDirectoryIterator::SKIP_DOTS));
        $minYear = PHP_INT_MAX;
        $maxYear = 0;

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'txt') {
                $fileYear = date("Y", $file->getMTime());
                if ($fileYear < $minYear) {
                    $minYear = $fileYear;
                }
                if ($fileYear > $maxYear) {
                    $maxYear = $fileYear;
                }
            }
        }

        return ['min' => $minYear, 'max' => $maxYear];
    }

    /**
     * Fetches all blog posts for the RSS feed, sorted from newest to oldest.
     * @return array An array of posts with necessary data for the RSS feed.
     */
    private static function fetchAllPosts() {
        $weblogDir = self::$config['weblog_dir'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($weblogDir, RecursiveDirectoryIterator::SKIP_DOTS));
        $posts = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'txt') {
                $slug = self::slugify(basename($file->getFilename(), '.txt'));
                $posts[] = [
                    'title' => basename($file->getFilename(), '.txt'),
                    'slug' => $slug,
                    'date' => $file->getMTime(),
                    'guid' => $slug,
                    'content' => file_get_contents($file->getPathname())
                ];
            }
        }
        usort($posts, function($a, $b) {
            return $b['date'] - $a['date'];
        });
        return $posts;
    }

    /**
     * Converts a string to a URL-friendly slug, ensuring non-ASCII characters are appropriately replaced.
     * @param string $title The string to slugify.
     * @return string The slugified string.
     */
    private static function slugify($title) {
        $title = mb_strtolower($title);
        $title = iconv('UTF-8', 'ASCII//TRANSLIT', $title);
        $title = preg_replace('/[^a-z0-9]+/', '-', $title);
        return trim($title, '-');
    }

    /**
     * Renders a single blog post, including its header and content.
     * @param SplFileInfo $file The file information object for the post.
     */
    private static function renderPost($file) {
        $relativePath = str_replace(self::$config['weblog_dir'], '', $file->getPathname());

        $pathParts = explode('/', trim($relativePath, '/'));
        $category = (count($pathParts) > 1) ? ucfirst($pathParts[0]) : "Misc";

        $title = basename($file->getFilename(), '.txt');
        $date = date("d F Y", $file->getMTime());

        $header = self::formatPostHeader($category, $title, $date);
        echo $header . "\n\n\n";

        $content = file_get_contents($file->getPathname());
        echo self::formatPostContent($content);
    }

    /**
     * Formats the header of a blog post, including category, title, and publication date.
     * @param string $category The category of the post.
     * @param string $title The title of the post.
     * @param string $date The publication date of the post.
     * @return string The formatted header.
     */
    private static function formatPostHeader($category, $title, $date) {
        $lineWidth = self::$config['line_width'];
        $categoryWidth = 20;
        $dateWidth = 20;
        $titleWidth = $lineWidth - $categoryWidth - $dateWidth;

        if (substr($title, 0, 1) === '~') {
            $title = '* * *';
        }

        $titlePadding = max((int) (($titleWidth - mb_strlen($title)) / 2), 0);
        $formattedTitle = sprintf("%-{$titlePadding}s%s%-{$titlePadding}s", '', $title, '');

        if (mb_strlen($formattedTitle) < $titleWidth) {
            $formattedTitle .= ' ';
        }

        return sprintf("%-{$categoryWidth}s%s%{$dateWidth}s", $category, $formattedTitle, $date);
    }

    /**
     * Formats the content of a blog post into paragraphs.
     * @param string $content The raw content of the post.
     * @return string The formatted content.
     */
    private static function formatPostContent($content) {
        $paragraphs = explode("\n", $content);
        $formattedContent = '';

        foreach ($paragraphs as $paragraph) {
            $formattedContent .= self::formatParagraph($paragraph) . "\n";
        }

        return $formattedContent;
    }

    /**
     * Renders the footer with dynamic copyright information based on the post dates or a specific year if provided.
     * @param int|null $year The specific year for the post page, null for the main page.
     */
    private static function renderFooter($year = null) {
        $authorEmail = self::$config['author_email'] ?? self::$config['author_name'];

        if ($year !== null) {
            $copyrightText = "Copyright (c) $year $authorEmail";
        } else {
            $postYears = self::getPostYearsRange();
            $earliestYear = $postYears['min'];
            $latestYear = $postYears['max'];
            $currentYear = date("Y");
            if ($earliestYear === $latestYear) {
                $copyrightText = "Copyright (c) $earliestYear $authorEmail";
            } else {
                $copyrightText = "Copyright (c) $earliestYear-$latestYear $authorEmail";
            }
        }

        echo self::centerText($copyrightText);
        echo "\n\n";
    }

    /**
     * Renders the sitemap in XML format, listing all blog posts, including the main page.
     * Sorts posts from newest to oldest.
     * @return string The XML content of the sitemap.
     */
    private static function renderSitemap() {
        $weblogDir = self::$config['weblog_dir'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($weblogDir, RecursiveDirectoryIterator::SKIP_DOTS));
        $files = iterator_to_array($iterator);

        usort($files, function($a, $b) {
            return $b->getMTime() - $a->getMTime();
        });

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $urlset = $dom->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $dom->appendChild($urlset);

        $mainUrl = $dom->createElement('url');
        $mainLoc = $dom->createElement('loc', self::$config['domain'] . '/');
        $mainUrl->appendChild($mainLoc);

        $lastmodDate = $files ? date('Y-m-d', $files[0]->getMTime()) : date('Y-m-d');
        $mainLastmod = $dom->createElement('lastmod', $lastmodDate);
        $mainUrl->appendChild($mainLastmod);

        $mainPriority = $dom->createElement('priority', '1.0');
        $mainUrl->appendChild($mainPriority);

        $mainChangefreq = $dom->createElement('changefreq', 'always');
        $mainUrl->appendChild($mainChangefreq);

        $urlset->appendChild($mainUrl);

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'txt') {
                $url = $dom->createElement('url');
                $loc = $dom->createElement('loc', self::$config['domain'] . '/' . self::slugify(basename($file->getFilename(), '.txt')) . '/');
                $url->appendChild($loc);

                $lastmod = $dom->createElement('lastmod', date('Y-m-d', $file->getMTime()));
                $url->appendChild($lastmod);

                $priority = $dom->createElement('priority', '1.0');
                $url->appendChild($priority);

                $changefreq = $dom->createElement('changefreq', 'always');
                $url->appendChild($changefreq);

                $urlset->appendChild($url);
            }
        }

        return $dom->saveXML();
    }

    /**
     * Generates an RSS feed for the blog.
     * @return string The RSS feed as an XML format string.
     */
    private static function generateRSS() {
        $rssTemplate = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $rssTemplate .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $rssTemplate .= '<channel>' . "\n";
        $rssTemplate .= '<title>' . htmlspecialchars(self::$config['author_name']) . '</title>' . "\n";
        $rssTemplate .= '<link>' . htmlspecialchars(self::$config['domain']) . '/' . '</link>' . "\n";
        $rssTemplate .= '<atom:link href="' . htmlspecialchars(self::$config['domain']) . '/rss/" rel="self" type="application/xml" />' . "\n";
        $rssTemplate .= '<description>' . htmlspecialchars(self::$config['about_text']) . '</description>' . "\n";
        $rssTemplate .= '<generator>Weblog ' . self::VERSION . '</generator>' . "\n";

        $posts = self::fetchAllPosts();
        foreach ($posts as $post) {
            $paragraphs = explode("\n", trim($post['content']));
            $formattedContent = '';
            $lastParagraphKey = count($paragraphs) - 1;

            foreach ($paragraphs as $key => $paragraph) {
                if (!empty($paragraph)) {
                    $formattedContent .= '<p>' . htmlspecialchars($paragraph) . '</p>';
                    if ($key !== $lastParagraphKey) {
                        $formattedContent .= '<br/>';
                    }
                }
            }

            $rssTemplate .= '<item>' . "\n";
            $rssTemplate .= '<title>' . htmlspecialchars($post['title']) . '</title>' . "\n";
            $rssTemplate .= '<link>' . htmlspecialchars(self::$config['domain']) . '/' . htmlspecialchars($post['slug']) . '/' . '</link>' . "\n";
            $rssTemplate .= '<pubDate>' . date(DATE_RSS, $post['date']) . '</pubDate>' . "\n";
            $rssTemplate .= '<guid isPermaLink="false">' . htmlspecialchars(self::$config['domain']) . '/' . htmlspecialchars($post['slug']) . '/' . '</guid>' . "\n";
            $rssTemplate .= '<description>' . $formattedContent . '</description>' . "\n";
            $rssTemplate .= '</item>' . "\n";
        }

        $rssTemplate .= '</channel>' . "\n";
        $rssTemplate .= '</rss>' . "\n";
        return $rssTemplate;
    }
}

Weblog::run();
