<?php

declare(strict_types=1);

namespace Drupal\drupal_file_scanner\StreamWrapper;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StreamWrapper\LocalStream;

/**
 * Stream wrapper for files that stay in the configured scan folder.
 */
final class ScanFilesStream extends LocalStream {

  /**
   * Constructs the stream wrapper.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getType(): int {
    return STREAM_WRAPPERS_LOCAL_NORMAL;
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return (string) t('Scan files');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) t('Files from the configured scanner directory.');
  }

  /**
   * {@inheritdoc}
   */
  public function getDirectoryPath(): string {
    $path = (string) $this->configFactory->get('drupal_file_scanner.settings')->get('scan_path');
    return rtrim($path, '/');
  }

}
