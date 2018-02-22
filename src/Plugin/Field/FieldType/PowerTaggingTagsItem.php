<?php
/**
 * @file
 * Contains \Drupal\powertagging\Plugin\Field\FieldType\PowerTaggingTagsItem
 */

namespace Drupal\powertagging\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\URL;
use Drupal\Core\Validation\Plugin\Validation\Constraint\AllowedValuesConstraint;
use Drupal\field\Entity\FieldConfig;
use Drupal\powertagging\Entity\PowerTaggingConfig;
use Drupal\powertagging\Form\PowerTaggingConfigForm;

/**
 * Plugin implementation of the 'powertagging_tags' field type.
 *
 * @FieldType(
 *   id = "powertagging_tags",
 *   label = @Translation("PowerTagging Tags"),
 *   description = @Translation("An entity field containing a taxonomy term
 *   reference."), category = @Translation("Reference"), default_widget =
 *   "powertagging_tags_default", default_formatter = "powertagging_tags_list",
 * )
 */
class PowerTaggingTagsItem extends FieldItemBase {

  /**
   * Returns the list of supported field types for the extraction mechanism.
   *
   * @return array
   *   The list of supported field types
   */
  public static function getSupportedFieldTypes() {
    // ContentEntityType ID => [
    //   FieldType ID => FieldWidget ID
    // ]
    return [
      'core' => [
        'string' => 'string_textfield',
        'string_long' => 'string_textarea',
      ],
      'text' => [
        'text' => 'text_textfield',
        'text_long' => 'text_textarea',
        'text_with_summary' => 'text_textarea_with_summary',
      ],
      'file' => [
        'file' => 'file_generic',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = [
      'columns' => [
        'target_id' => [
          'description' => 'The ID of the target taxonomy term.',
          'type' => 'int',
          'unsigned' => TRUE,
        ],
        'score' => [
          'description' => 'The score of the taxonomy term for this entity.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
      ],
      'indexes' => [
        'target_id' => ['target_id'],
      ],
    ];

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $target_type_info = \Drupal::entityTypeManager()
      ->getDefinition('taxonomy_term');

    $properties['target_id'] = DataDefinition::create('integer')
      ->setLabel(t('@label ID', ['@label' => $target_type_info->getLabel()]))
      ->setRequired(TRUE);
    $properties['score'] = DataDefinition::create('integer')
      ->setLabel(t('Score'))
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints() {
    $constraints = parent::getConstraints();
    // Remove the 'AllowedValuesConstraint' validation constraint because entity
    // reference fields already use the 'ValidReference' constraint.
    foreach ($constraints as $key => $constraint) {
      if ($constraint instanceof AllowedValuesConstraint) {
        unset($constraints[$key]);
      }
    }
    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    return empty($this->get('target_id'));
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings() {
    return [
        'powertagging_id' => NULL,
      ] + parent::defaultStorageSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data) {
    $options = [];
    $description = '';

    $powertagging_configs = PowerTaggingConfig::loadMultiple();
    /** @var PowerTaggingConfig $powertagging_config */
    if (!is_null($powertagging_configs)) {
      foreach ($powertagging_configs as $powertagging_config) {
        $options[$powertagging_config->id()] = $powertagging_config->getTitle();
      }
    }
    else {
      $url = URL::fromRoute('entity.powertagging.collection');
      $description = t('No PowerTagging configuration found.') . '<br />';
      $description .= t('Please create it first in the <a href="@url">PowerTagging configuration</a> area.', ['@url' => $url->toString()]);
      drupal_set_message(t('No PowerTagging configuration found for the selection below.'), 'error');
    }

    $element['powertagging_id'] = [
      '#type' => 'select',
      '#title' => t('Select the PowerTagging configuration'),
      '#description' => $description,
      '#options' => $options,
      '#default_value' => $this->getSetting('powertagging_id'),
      '#required' => TRUE,
      '#disabled' => $has_data,
      '#size' => 1,
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
        'include_in_tag_glossary' => FALSE,
        'automatically_tag_new_entities' => FALSE,
        'custom_freeterms' => TRUE,
        'fields' => [],
        'default_tags_field' => '',
        'limits' => [],
      ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    /** @var FieldConfig $field */
    $field = $form_state->getFormObject()->getEntity();
    $form = [];

    // Check if the entity type has taxonomy term references.
    $field_definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions($field->getTargetEntityTypeId(), $field->getTargetBundle());
    $term_references = ['' => '- None -'];
    foreach ($field_definitions as $field_definition) {
      if ($field_definition instanceof FieldConfig && $field_definition->getType() == 'entity_reference') {
        $handler = $field_definition->getSetting('handler');
        if (!is_null($handler) && strpos($handler, 'taxonomy_term') !== FALSE) {
          $term_references[$field_definition->getName()] = $field_definition->label();
        }
      }
    }

    // Show the fields with taxonomy term references if available.
    if (count($term_references) > 1) {
      $form['default_tags_field'] = [
        '#type' => 'radios',
        '#title' => t('Term reference fields that can be used for default values'),
        '#description' => t('Select the field from witch the linked terms are used as default values.'),
        '#options' => $term_references,
        '#default_value' => $field->getSetting('default_tags_field'),
      ];
    }

    // Show the fields that can be used for tagging.
    $options = $this->getSupportedTaggingFields($field->getTargetEntityTypeId(), $field->getTargetBundle());
    $form['fields'] = [
      '#type' => 'checkboxes',
      '#title' => t('Fields that can be used for tagging'),
      '#description' => t('Select the fields from witch the concepts and free terms are extracted.'),
      '#options' => $options,
      '#default_value' => $field->getSetting('fields'),
      '#required' => TRUE,
    ];

    // Limit settings.
    $form['limits'] = [
      '#type' => 'details',
      '#title' => t('Limit settings'),
      '#open' => TRUE,
    ];

    $powertagging_id = $field->getFieldStorageDefinition()
      ->getSetting('powertagging_id');
    $powertagging_config = PowerTaggingConfig::load($powertagging_id)
      ->getConfig();
    $limits = empty($field->getSetting('limits')) ? $powertagging_config['limits'] : $field->getSetting('limits');

    PowerTaggingConfigForm::addLimitsForm($form['limits'], $limits, TRUE);
    foreach (array('concepts', 'freeterms') as $concept_type) {
      $form['limits'][$concept_type]['#description'] .= '<br />' . t('Note: These settings override the global settings defined in the connected PowerTagging configuration.');
    }

    $form['limits']['freeterms']['custom_freeterms'] = array(
      '#type' => 'checkbox',
      '#title' => 'Allow users to add custom free terms',
      '#description' => 'If this options is enabled users can add custom freeterms by writing text in the search-box of the PowerTagging widget and clicking the enter key.',
      '#default_value' => $field->getSetting('custom_freeterms'),
      '#parents' => array('settings', 'custom_freeterms'),
    );

    // Show a checkbox for the including in a glossary if the "Smart Glossary"
    // module is installed and enabled.
    if (\Drupal::moduleHandler()->moduleExists('smart_glossary')) {
      $form['include_in_tag_glossary'] = [
        '#type' => 'checkbox',
        '#title' => t('Include in PowerTagging Tag Glossary'),
        '#description' => t('Show tags of this field in the "PowerTagging Tag Glossary" block (if it is enabled)'),
        '#default_value' => $field->getSetting('include_in_tag_glossary'),
      ];
    }

    $form['automatically_tag_new_entities'] = array(
      '#type' => 'checkbox',
      '#title' => t('Automatically tag new entities'),
      '#description' => t('When entities get created and don\'t have values for this field yet, they will be tagged automatically.'),
      '#default_value' => $field->getSetting('automatically_tag_new_entities'),
      '#empty_value' => '',
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public static function fieldSettingsToConfigData(array $settings) {
    $limits = [];
    if (!empty($settings['limits'])) {
      $limits = [
        'concepts_per_extraction' => $settings['limits']['concepts']['concepts_per_extraction'],
        'concepts_threshold' => $settings['limits']['concepts']['concepts_threshold'],
        'freeterms_per_extraction' => $settings['limits']['freeterms']['freeterms_per_extraction'],
        'freeterms_threshold' => $settings['limits']['freeterms']['freeterms_threshold'],
      ];
    }
    return [
      'include_in_tag_glossary' => $settings['include_in_tag_glossary'],
      'automatically_tag_new_entities' => $settings['automatically_tag_new_entities'],
      'custom_freeterms' => $settings['custom_freeterms'],
      'fields' => $settings['fields'],
      'default_tags_field' => $settings['default_tags_field'],
      'limits' => $limits,
    ];
  }

  /**
   * Get the the fields that are supported for the tagging.
   *
   * @param string $entity_type
   *   The entity type to check.
   * @param string $bundle
   *   The bundle to check.
   *
   * @return array
   *   A list of supported fields.
   */
  public static function getSupportedTaggingFields($entity_type, $bundle) {
    $field_definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions($entity_type, $bundle);
    $widget_manager = \Drupal::service('plugin.manager.field.widget');
    $supported_field_types = static::getSupportedFieldTypes();
    $supported_fields = [];

    switch ($entity_type) {
      case 'node':
        $supported_fields['title'] = $field_definitions['title']->getLabel()
           . '<span class="description">[Text field]</span>';
        break;

      case 'taxonomy_term':
        $supported_fields['name'] = t('Name of the term') . '<span class="description">[' . t('Textfield') . ']</span>';
        $supported_fields['description'] = t('Description') . '<span class="description">[' . t('Text area (multiple rows)') . ']</span>';
        break;
    }
    /** @var \Drupal\Core\Field\BaseFieldDefinition $field_definition */
    foreach ($field_definitions as $field_definition) {
      if (!$field_definition instanceof FieldConfig) {
        continue;
      }
      $field_storage = $field_definition->getFieldStorageDefinition();
      if (isset($supported_field_types[$field_storage->getTypeProvider()][$field_storage->getType()])) {
        $widget_id = $supported_field_types[$field_storage->getTypeProvider()][$field_storage->getType()];
        $widget_info = $widget_manager->getDefinition($widget_id);
        $supported_fields[$field_definition->getName()] = $field_definition->label() . '<span class="description">[' . $widget_info['label'] . ']</span>';
      }
    }

    return $supported_fields;
  }

}
