# WebResourceExtractor

Simple and lightweight PHP class for downloading Web pages.

Examples on how to use this class.
```
$web = new WebResourceExtractor(true, '/cache/', 3600);
$web->setUserAgent('YOUR USER AGENT');
$web->followLocation(5); // allow cURL to follow redirects.

// Example #1. Extract a single Web resource.
$arResults = $web->extractWebResource('https://en.wikipedia.org/wiki/Main_Page', '', '', true, false);

// Example #2. Extract multiple Web resources by relative URLs.
$arUrls = [
	['wiki/Wikipedia:Contents', '', '', true],
	['wiki/Portal:Current_events', '', '', false],
	'wiki/Wikipedia:About',
	'wiki/404',
];
$arResults = $web->extractMultipleWebResources($arUrls, 'https://en.wikipedia.org', 15);

// Example #3. Extract multiple Web resources by absolute URLs.
$arUrls = [
	'https://en.wikipedia.org',
	'https://tg.wikipedia.org',
];
$arResults = $web->extractMultipleWebResources($arUrls);
```

NOTE: the docs will updated soon.
