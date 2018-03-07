<?php

/**
 * @file
 * Contains \Drupal\powertagging\Plugin\Field\FieldFormatter\PowerTaggingTagsFormatter
 */

namespace Drupal\powertagging\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
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

  public static function defaultSettings() {
    return array(
      'add_alt_labels' => FALSE,
      'add_hidden_labels' => FALSE,
    );
  }

  public function settingsForm(array $form, FormStateInterface $form_state) {
    $settings = $this->settings;

    $form['info'] = array(
      '#markup' => '<p>' . t('Select the labels that will be added additionally in a hidden box to each PowerTagging Tag:') . '</p>',
    );
    $form['add_alt_labels'] = array(
      '#title' => t('Alternative labels'),
      '#type' => 'checkbox',
      '#default_value' => isset($settings['add_alt_labels']) ? $settings['add_alt_labels'] : FALSE,
    );
    $form['add_hidden_labels'] = array(
      '#title' => t('Hidden labels'),
      '#type' => 'checkbox',
      '#default_value' => isset($settings['add_hidden_labels']) ? $settings['add_hidden_labels'] : FALSE,
    );
    $form['help'] = array(
      '#markup' => '<p>' . t('The Drupal default search is improved by indexing the corresponding node with those labels.') . '</p>',
    );

    return $form;
  }

  public function settingsSummary() {
    $settings = $this->settings;

    $labels = array();
    if (isset($settings['add_alt_labels']) && $settings['add_alt_labels']) {
      $labels[] = t('Alternative labels');
    }
    if (isset($settings['add_hidden_labels']) && $settings['add_hidden_labels']) {
      $labels[] = t('Hidden labels');
    }

    return array(t('Hidden data: @labels', array('@labels' => (empty($labels) ? 'none' : implode(', ', $labels)))));
  }

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

    $settings = $this->settings;
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
      $tags_to_theme = array();
      /** @var Term $term */
      foreach ($terms as $term) {
        $uri = $term->get('field_uri')->getValue();
        $tags_to_theme[] = array(
          'uri' => (!empty($uri) ? $uri[0]['uri'] : ''),
          'html' => \Drupal\Component\Utility\Html::escape($term->getName()),
          'alt_labels' => (isset($settings['add_alt_labels']) && $settings['add_alt_labels'] && $term->hasField('field_alt_labels') && $term->get('field_alt_labels')->count() ? $term->get('field_alt_labels')->getString() : ''),
          'hidden_labels' => (isset($settings['field_hidden_labels']) && $settings['add_hidden_labels'] && $term->hasField('field_hidden_labels') && $term->get('field_hidden_labels')->count() ? $term->get('field_hidden_labels')->getString() : ''),
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