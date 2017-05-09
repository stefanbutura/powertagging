<?php

/**
 * @file
 * Contains \Drupal\powertagging\Plugin\Field\FieldFormatter\PowerTaggingTagsFormatter
 */

namespace Drupal\powertagging\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\powertagging\Plugin\Field\FieldType\PowerTaggingTagsItem;
use Drupal\taxonomy\Entity\Term;

/**
 * Plugin implementation of the PowerTaggingTgs formatter.
 *
 * @FieldFormatter(
 *   id = "powertagging_tags_list",
 *   label = @Translation("Tag list"),
 *   field_types = {
 *     "powertagging_tags"
 *   }
 * )
 */
class PowerTaggingTagsFormatter extends FormatterBase {

  /**
   * Builds a renderable array for a field value.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field values to be rendered.
   * @param string $langcode
   *   The language that should be used to render the field.
   *
   * @return array
   *   A renderable array for $items, as an array of child elements keyed by
   *   consecutive numeric indexes starting from 0.
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    if (empty($items)) {
      return [];
    }

    $elements = NULL;
    $context = [
      'items' => $items,
      'langcode' => $langcode,
    ];
    \Drupal::moduleHandler()->alter('powertagging_tag_list', $elements, $context);

    if (is_null($elements)) {
      $elements = [];
      $tag_ids = [];
      /** @var PowerTaggingTagsItem $item */
      foreach ($items as $item) {
        if ($item->target_id !== NULL) {
          $tag_ids[] = $item->target_id;
        }
      }
      $terms = Term::loadMultiple($tag_ids);
      /** @var Term $term */
      foreach ($terms as $term) {
        $uri = $term->urlInfo();
        $elements[] = [
          '#type' => 'link',
          '#title' => $term->getName(),
          '#url' => $uri,
          '#options' => $uri->getOptions(),
        ];
      }
      // TODO: adapt the output for our needs.
      //$powertagging_config = PowerTaggingConfig::load($this->getFieldSettings()['powertagging_id']);
      //$elements = semantic_connector_theme_concepts($tags_to_theme, $powertagging_config->connection->getId(), $powertagging_config->project_id);
    }

    return $elements;
  }
}