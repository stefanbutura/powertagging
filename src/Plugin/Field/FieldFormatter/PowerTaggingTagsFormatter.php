<?php

/**
 * @file
 * Contains \Drupal\powertagging\Plugin\Field\FieldFormatter\PowerTaggingTagsFormatter
 */

namespace Drupal\powertagging\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\powertagging\Entity\PowerTaggingConfig;
use Drupal\powertagging\Plugin\Field\FieldType\PowerTaggingTagsItem;
use Drupal\semantic_connector\SemanticConnector;
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
      $tags_to_theme = array();
      foreach ($terms as $term) {
        $uri = $term->get('field_uri')->getValue();
        $tags_to_theme[] = array(
          'uri' => (!empty($uri) ? $uri[0]['uri'] : ''),
          'html' => \Drupal\Component\Utility\Html::escape($term->getName()),
        );
      }
      $powertagging_config = PowerTaggingConfig::load($this->getFieldSetting('powertagging_id'));
      $elements[] = array(
        '#markup' => SemanticConnector::themeConcepts($tags_to_theme, $powertagging_config->getConnectionId(), $powertagging_config->getProjectId())
      );
    }

    return $elements;
  }
}