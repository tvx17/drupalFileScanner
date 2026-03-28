# Drupal File Scanner

This module scans a configured folder recursively and creates media entities for matching files.

## What it does

- Supports configurable extensions (default: `jpg jpeg doc docx xlsx xls png pdf csv txt`).
- Scans recursively through all subfolders.
- Keeps original files in place by referencing them through a custom `scanfiles://` stream wrapper.
- Creates media in the `scanned_file` media type.
- Prevents duplicate media imports by storing and checking the source absolute path.

## Usage

1. Enable module: `drush en drupal_file_scanner`.
2. Configure folder in `/admin/config/media/drupal-file-scanner`.
3. Run scan from `/admin/content/drupal-file-scanner`.
4. The status message reports how many were added and how many already existed.
