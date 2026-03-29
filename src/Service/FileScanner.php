<?php

declare(strict_types=1);

namespace Drupal\file_scanner\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

final class FileScanner {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * @return array<string,int>
   */
  public function scan(): array {
    $config = $this->configFactory->get('file_scanner.settings');
    $directory = trim((string) $config->get('scan_directory'));
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

    $mediaType = $this->entityTypeManager->getStorage('media_type')->load($mediaBundle);
    if ($mediaType === NULL) {
      return $stats;
    }

    $sourceField = (string) ($mediaType->getSourceConfiguration()['source_field'] ?? '');
    if ($sourceField === '') {
      return $stats;
    }

    $fileStorage = $this->entityTypeManager->getStorage('file');
    $mediaStorage = $this->entityTypeManager->getStorage('media');

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

      if ($fileStorage->loadByProperties(['uri' => $uri]) !== []) {
        $stats['existing']++;
        continue;
      }

      /** @var \Drupal\file\FileInterface $file */
      $file = $fileStorage->create([
        'uid' => (int) $this->currentUser->id(),
        'filename' => $item->getFilename(),
        'uri' => $uri,
        'status' => FileInterface::STATUS_PERMANENT,
      ]);
      $file->setPermanent();
      $file->save();

      /** @var \Drupal\media\MediaInterface $media */
      $media = $mediaStorage->create([
        'bundle' => $mediaBundle,
        'uid' => (int) $this->currentUser->id(),
        'status' => MediaInterface::PUBLISHED,
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
    return rtrim($baseUri, '/') . '/' . $relative;
  }

}
