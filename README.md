Coercive FileServe Utility
==========================

PHP Serve File with header.

Get
---
```
composer require coercive/fileserve
```

Usage
-----

```php
use Coercive\Utility\FileServe;

# V1
FileServe::output('/path/file.extension');

##################

# V2
$serve = new FileServe('/path/file.extension');

# Send range
$oServe->range();

# Send direct download
$serve->download();

# Get mime
$string = $oServe->mimeType();

```
