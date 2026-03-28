<?php

declare(strict_types=1);

namespace Drupal\drupal_file_scanner\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\drupal_file_scanner\Service\FileScanner;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the scanner trigger form.
 */
final class ScannerForm extends FormBase {

  /**
   * Constructs a scanner form.
   */
  public function __construct(
    private readonly FileScanner $scanner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('drupal_file_scanner.scanner'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'drupal_file_scanner_scan_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $configuration = $this->scanner->getConfiguration();

    $form['scan_path'] = [
      '#type' => 'item',
      '#title' => $this->t('Configured folder'),
      '#markup' => '<code>' . $configuration['scan_path'] . '</code>',
    ];

    $form['settings_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Change scanner settings'),
      '#url' => Url::fromRoute('drupal_file_scanner.settings'),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start scan'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->scanner->scan();

    if (!empty($result['errors'])) {
      foreach ($result['errors'] as $error) {
        $this->messenger()->addError($error);
      }
      return;
    }

    $this->messenger()->addStatus($this->t('Scan finished. Added: @added. Already existing: @existing.', [
      '@added' => $result['added'],
      '@existing' => $result['existing'],
    ]));
  }

}
