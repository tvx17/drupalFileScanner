<?php

declare(strict_types=1);

namespace Drupal\file_scanner\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;

final class FileScanner {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Scans configured directory and creates file/media entities.
   *
   * @return array<string,int>
   *   Stats: discovered, unsupported, existing, created, errors.
   */
  public function scan(): array {
    $config = $this->configFactory->get('file_scanner.settings');
    $directory = (string) $config->get('scan_directory');
    $extensions = array_values(array_filter(array_map(
      static fn (string $ext): string => strtolower(trim($ext, " \t\n\r\0\x0B.")),
      (array) $config->get('extensions')
    )));
    $mediaBundle = (string) $config->get('media_bundle');

    $stats = [
      'discovered' => 0,
      'unsupported' => 0,
      'existing' => 0,
      'created' => 0,
      'errors' => 0,
    ];

    if ($directory === '' || $mediaBundle === '' || $extensions === []) {
      return $stats;
    }

    $realBasePath = $this->fileSystem->realpath($directory);
    if ($realBasePath === FALSE || !is_dir($realBasePath)) {
      return $stats;
    }

    $mediaTypeStorage = $this->entityTypeManager->getStorage('media_type');
    $mediaType = $mediaTypeStorage->load($mediaBundle);
    if ($mediaType === NULL) {
      return $stats;
    }

    $sourceField = (string) ($mediaType->getSourceConfiguration()['source_field'] ?? '');
    if ($sourceField === '') {
      return $stats;
    }

    $fileStorage = $this->entityTypeManager->getStorage('file');

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($realBasePath, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
      if (!$item->isFile()) {
        continue;
      }

      $stats['discovered']++;
      $extension = strtolower((string) pathinfo($item->getFilename(), PATHINFO_EXTENSION));
      if (!in_array($extension, $extensions, TRUE)) {
        $stats['unsupported']++;
        continue;
      }

      $uri = $this->buildUriFromRealPath($directory, $realBasePath, $item->getPathname());
      if ($uri === NULL) {
        $stats['errors']++;
        continue;
      }

      $existingFiles = $fileStorage->loadByProperties(['uri' => $uri]);
      if ($existingFiles !== []) {
        $stats['existing']++;
        continue;
      }

      $file = File::create([
        'uid' => $this->currentUser->id(),
        'filename' => $item->getFilename(),
        'uri' => $uri,
        'status' => 1,
      ]);
      $file->setPermanent();
      $file->save();

      $media = Media::create([
        'bundle' => $mediaBundle,
        'uid' => $this->currentUser->id(),
        'status' => 1,
        'name' => $item->getFilename(),
      ]);

      if (!$media->hasField($sourceField)) {
        $stats['errors']++;
        $file->delete();
        continue;
      }

      $media->set($sourceField, ['target_id' => $file->id()]);
      $media->save();
      $stats['created']++;
    }

    return $stats;
  }

  private function buildUriFromRealPath(string $baseUri, string $realBasePath, string $realPath): ?string {
    $normalizedBasePath = rtrim(str_replace('\\', '/', $realBasePath), '/');
    $normalizedPath = str_replace('\\', '/', $realPath);

    if (!str_starts_with($normalizedPath, $normalizedBasePath . '/')) {
      return NULL;
    }

    $relative = ltrim(substr($normalizedPath, strlen($normalizedBasePath)), '/');
    $baseUri = rtrim($baseUri, '/');

    return $baseUri . '/' . $relative;
  }

}
