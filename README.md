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

V1
--
```php
use Coercive\Utility\FileServe;

# Serve file
FileServe::output('/path/file.extension');
```

V2
--
```php
use Coercive\Utility\FileServe;

# V2
$serve = new FileServe('/path/file.extension');

# Send range
$serve->range();

# Serve file
$serve->serve();

# Send download
$serve->download();

# Get mime
$string = $serve->mimeType();

# Get filesize
$string = $serve->getSize();

```

Options

```php
# Enable / Disable header no cache options
$serve->enableCache()
$serve->disableCache()

# Enable / Disable header content disposition filename for html5 attr : <a download="filename">
$serve->enableFilename()
$serve->disableFilename()
```
