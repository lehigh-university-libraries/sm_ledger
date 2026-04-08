<?php

namespace Drupal\sm_ledger\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Pretty-prints JSON string field values.
 *
 * @FieldFormatter(
 *   id = "sm_ledger_pretty_json",
 *   label = @Translation("Pretty JSON"),
 *   field_types = {
 *     "string",
 *     "string_long"
 *   }
 * )
 */
class PrettyJsonFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];

    foreach ($items as $delta => $item) {
      $value = (string) ($item->value ?? '');
      $pretty = $this->prettyPrintJson($value);

      $elements[$delta] = [
        '#type' => 'html_tag',
        '#tag' => 'pre',
        '#value' => $pretty,
      ];
    }

    return $elements;
  }

  /**
   * Pretty-prints a JSON string when possible.
   */
  private function prettyPrintJson(string $value): string {
    if ($value === '') {
      return $value;
    }

    try {
      $decoded = json_decode($value, TRUE, 512, JSON_THROW_ON_ERROR);
      return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?: $value;
    }
    catch (\Throwable) {
      return $value;
    }
  }

}
