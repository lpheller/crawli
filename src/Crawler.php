<?php

namespace Lpheller\Crawli;

class Crawler
{
    /**
     * Array of links found.
     *
     * @var array
     */
    protected $links = [];

    /**
     * The full baseUrl for the currently crawled site.
     *
     * @var [type]
     */
    protected $baseUrl;

    /**
     * The host of the baseUrl currently crawled.
     *
     * @var string
     */
    protected $host;

    /**
     * A counter to track the running time.
     *
     * @var int
     */
    protected $startTime = 0;

    /**
     * Determine the maximum time to crawl the page.
     *
     * @var int|null
     */
    protected $timeout = 60;

    /**
     * Array of default strings to block when found in a page url.
     *
     * @var array
     */
    protected $blacklist = [
        'mailto:',
        'javascript:',
        '.pdf',
        'storage',
        'index.php',
        'tel:',
        '#',
    ];

    /**
     * Default Useragent to be used for crawling pages.
     *
     * @var string
     */
    protected $userAgent = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    /**
     * Array to keep track of already crawled links.
     *
     * @var array
     */
    protected $crawled = [];

    /**
     * Create a new Instance of the crawler.
     */
    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    /**
     * Start the crawler at a given entry point.
     *
     * @param  string $url
     * @return self
     */
    public function crawl(string $url)
    {
        $this->setBaseUrl($url);
        $this->host = parse_url($url)['host'];
        $this->parse($url);

        return $this;
    }

    /**
     * Gets the crawled links.
     *
     * @return array
     */
    public function getLinks()
    {
        return $this->links;
    }

    /**
     * Check the current running time do determine if the
     * crawling should be aborted before.
     *
     * @return void
     */
    public function getTimeRunning()
    {
        return microtime(true) - $this->startTime;
    }

    /**
     * Sets the baseUrl.
     *
     * @param  string $url
     * @return self
     */
    public function setBaseUrl(string $url)
    {
        $this->baseUrl = $url;

        return $this;
    }

    /**
     * Add a string or array of strings to the list of blocked strings.
     * All crawled links will be matched agains this list to determine
     * if a link should be indexed or is skipped.
     *
     * @param  array|string $value
     * @param  bool         $override
     * @return self
     */
    public function setBlacklist(array|string $value, $override = false)
    {
        if (is_array($value) && $override) {
            $this->blacklist = $value;
        }

        if (is_array($value) && ! $override) {
            $this->blacklist = array_merge($this->blacklist, $value);
        }

        if (is_string($value)) {
            $this->blacklist[] = $value;
        }

        return $this;
    }

    /**
     * Sets the user agent used to crawl pages.
     * The default is set as google bot.
     *
     * @param  string $value
     * @return self
     */
    public function setUserAgent(string $value)
    {
        $this->userAgent = $value;

        return $this;
    }

    /**
     * Configure timeout dynamically. Value in seconds or null to not use any timeout.
     *
     * @param  int|null $timeout
     * @return void
     */
    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Recursivly parses links found in HTML code in parsed links.
     *
     * @param string $url Url that should be fetched and parsed
     */
    protected function parse($url)
    {
        // echo 'crawling '. $url . PHP_EOL;
        $linksCountBeforeParsing = count($this->links);

        $dom = new \DOMDocument();

        // LIBXML_NOERROR supresses HTML5 errors
        // LIBXML_NOWARNING spuresses HTML5 warnings
        @$dom->loadHTML($this->fetch($url), LIBXML_NOERROR | LIBXML_NOWARNING);

        $xPath = new \DOMXPath($dom);

        $query = "//a[not(@rel) or @rel!='nofollow']/@href";
        $linkElements = $xPath->query($query);

        foreach ($linkElements as $element) {
            $link = $this->getFullUrl($element->nodeValue);

            if ($this->shouldBeIndexed($link)) {
                $this->links[] = $link;
            }
        }

        $this->crawled[] = $url;

        // Start at 0.
        // Index the first page, find 10 links.
        // 10 > 0 => parse each of the found links.
        // Either find new links to parse or
        // exit, once all links are marked as crawled.
        // or the time is running out and we decide to return what we already found.

        if (count($this->links) > $linksCountBeforeParsing) {
            foreach ($this->links as $link) {
                if ($this->shouldReturnBeforeTimeout()) {
                    break;
                }

                if ($this->wasPrivouslyCrawled($link)) {
                    continue;
                }

                $this->parse($link);
            }
        }
    }

    /**
     * Fetch page using CURL.
     *
     * @param $url
     * @return mixed
     */
    protected function fetch($url)
    {
        $options = [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_AUTOREFERER    => true,
            CURLOPT_CONNECTTIMEOUT => 60,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_MAXREDIRS      => 10,
        ];

        $curlHandle = curl_init($url);
        curl_setopt_array($curlHandle, $options);
        $content = curl_exec($curlHandle);
        curl_close($curlHandle);

        return $content;
    }

    /**
     * Dtermine if the crawling should be aborted based on the running time.
     *
     * @return bool
     */
    protected function shouldReturnBeforeTimeout()
    {
        if ($this->timeout == null) {
            return false;
        }

        return $this->getTimeRunning() >= ($this->timeout - 1);
    }

    /**
     * Determine if an url was previously crawled.
     *
     * @param  string $url
     * @return void
     */
    protected function wasPrivouslyCrawled(string $url)
    {
        return in_array($url, $this->crawled);
    }

    /**
     * Returns a full url link.
     *
     * @param  string $value
     * @return string fullUrl
     */
    protected function getFullUrl(string $value)
    {
        if ($this->isExternalLink($value)) {
            return $value;
        }

        $blacklisted = implode('|', $this->blacklist);
        if (preg_match('/(' . $blacklisted . ')/i', $value)) {
            return $value;
        }

        $url = parse_url($value);

        if (! array_key_exists('path', $url)) {
            return $value;
        }

        // by only using the path, we automatically get rid of page parameters
        // that should not be relevant for us right now.
        return $this->baseUrl . '/' . rtrim($url['path'], '/');
    }

    /**
     * Determine if a link should be crawled and indexed.
     *
     * @param  string $link
     * @return bool
     */
    protected function shouldBeIndexed($link)
    {
        if ($this->isExternalLink($link)) {
            return false;
        }

        // Skip links containing blacklisted elements.
        $blacklisted = implode('|', $this->blacklist);
        if (preg_match('/(' . $blacklisted . ')/i', $link)) {
            return false;
        }

        // skip links, that don't start with the same url as provided for baseurl
        if (! str_contains($link, $this->baseUrl)) {
            return false;
        }

        // if the link already was indexed priviously, no need to do this again
        if (in_array($link, $this->links)) {
            return false;
        }

        return true;
    }

    /**
     * Determines if a given url is linking to an external page.
     *
     * @param  string $url
     * @return bool
     */
    protected function isExternalLink(string $url)
    {
        $link = parse_url($url);

        // no host exists, most likely an internal link
        if (! array_key_exists('host', $link)) {
            return false;
        }

        // host exists but its our currently crawled host
        if ($link['host'] == $this->host) {
            return false;
        }

        return true;
    }
}
