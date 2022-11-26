# WebResourceExtractor

Simple and lightweight PHP class for downloading Web pages.

Examples on how to use this class.
```
$extractor = new WebResourceExtractor(true, '/cache/', 3600);
$extractor->setUserAgent('YOUR USER AGENT');
$extractor->followLocation(5); // allow cURL to follow redirects.

// Example #1. Extract a single Web resource.
$results = $extractor->extractWebResource('https://en.wikipedia.org/wiki/Main_Page', '', '', true, false);

// Example #2. Extract multiple Web resources by relative URLs.
$urls = [
    ['wiki/Wikipedia:Contents', '', '', true],
    ['wiki/Portal:Current_events', '', '', false],
    'wiki/Wikipedia:About',
    'wiki/404',
];

$results = $extractor->extractMultipleWebResources($urls, 'https://en.wikipedia.org', 15);

// Example #3. Extract multiple Web resources by absolute URLs.
$urls = [
    'https://en.wikipedia.org',
    'https://tg.wikipedia.org',
];

$results = $extractor->extractMultipleWebResources($urls);
```
