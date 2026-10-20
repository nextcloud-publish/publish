# Integration fixture

The landing page of the fixture site. ssg-library treats every folder as a page
whose body is its `Readme.md`, so this file is what `index.html` renders from.

Plain `.md` files sitting next to this one become leaf sub-pages, and
sub-folders become nested pages — both are listed automatically, so this page
does not link to them by hand.

## What this exercises

| Step                | Where it shows up                                  |
| ------------------- | -------------------------------------------------- |
| Download            | `<job>/input/content.tar.gz`                       |
| Extract             | `<job>/input/content_unarchived/`                  |
| Render              | `<job>/output/index.html` and one dir per page     |

A fenced block, so the renderer's syntax highlighting is in the output too:

```php
$pages = (new SsgLab\SiteBuilder())->build($in, $out, $slug);
```
